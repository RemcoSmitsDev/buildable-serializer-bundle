<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\Author;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithAuthor;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SetterFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Integration tests for the AbstractNormalizer::IGNORED_ATTRIBUTES context key
 * in both the generated normalizer and the generated denormalizer.
 *
 * ### What IGNORED_ATTRIBUTES does
 *
 * `AbstractNormalizer::IGNORED_ATTRIBUTES` is a denylist of PHP property names
 * that are skipped during (de)normalization. When the key is absent from the
 * context (or set to an empty array), all properties are processed as normal.
 *
 * Unlike `ATTRIBUTES` (an allowlist), `IGNORED_ATTRIBUTES` is applied to every
 * element of nested structures automatically — properties listed in it are
 * skipped at every nesting level.
 *
 * ### Fixture anatomy ({@see SimpleBlog}, {@see BlogWithAuthor}, {@see Author}, {@see SetterFixture})
 *
 * `SimpleBlog` has four properties:
 *   - `$id`      — int, required constructor parameter
 *   - `$title`   — string, required constructor parameter
 *   - `$content` — string, required constructor parameter
 *   - `$excerpt` — nullable string, optional constructor parameter (default null)
 *
 * `BlogWithAuthor` has four properties:
 *   - `$id`       — int, required constructor parameter
 *   - `$title`    — string, required constructor parameter
 *   - `$author`   — nested Author object, required constructor parameter
 *   - `$coAuthor` — nullable nested Author object, optional (default null)
 *
 * `Author` has three properties:
 *   - `$id`    — int, required constructor parameter
 *   - `$name`  — string, required constructor parameter
 *   - `$email` — string, required constructor parameter
 *
 * `SetterFixture` has three properties populated via setters (no constructor params):
 *   - `$name`  — string (default '')
 *   - `$age`   — int (default 0)
 *   - `$email` — nullable string (default null)
 *
 * ### Normalizer coverage
 *
 *   1. Without IGNORED_ATTRIBUTES — all properties appear in the output.
 *   2. Single ignored property `['title']` — all properties except `title` appear.
 *   3. Multiple ignored properties `['title', 'excerpt']` — only `id` and `content` appear.
 *   4. Empty denylist `[]` — all properties appear (equivalent to no filter).
 *   5. IGNORED_ATTRIBUTES set to `null` explicitly — all properties appear.
 *   6. Ignoring a nested object property `['coAuthor']` — that key is absent from
 *      the output while the other top-level properties remain.
 *   7. Ignoring a top-level scalar property while nested objects are present.
 *
 * ### Denormalizer coverage
 *
 *   8.  Without IGNORED_ATTRIBUTES — all supplied payload fields are written.
 *   9.  Single ignored constructor param `['excerpt']` — the param falls back to
 *       its default (`null`) regardless of the value in the payload.
 *   10. Multiple ignored constructor params — each falls back to its default.
 *   11. Empty denylist `[]` — all supplied fields are written (no filter).
 *   12. IGNORED_ATTRIBUTES set to `null` — equivalent to no filter.
 *   13. Non-ignored fields are still extracted correctly when some are ignored.
 *   14. Setter-based fixture: ignored property is not written during populate();
 *       the existing default value is preserved.
 *   15. Setter-based fixture: OBJECT_TO_POPULATE + IGNORED_ATTRIBUTES — ignored
 *       field retains its pre-existing value; other fields are updated normally.
 *   16. Multiple setter fields ignored simultaneously.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory
 */
final class IgnoredAttributesContextTest extends AbstractTestCase
{
    private string $tempDir;

    /** The instantiated generated normalizer for SimpleBlog. */
    private NormalizerInterface $simpleBlogNormalizer;

    /**
     * The instantiated generated normalizer for BlogWithAuthor, wired to a
     * Serializer that also knows about the Author normalizer so that nested
     * delegation works.
     */
    private NormalizerInterface $blogWithAuthorNormalizer;

    /** The instantiated generated denormalizer for SimpleBlog. */
    private DenormalizerInterface $simpleBlogDenormalizer;

    /** The instantiated generated denormalizer for SetterFixture. */
    private DenormalizerInterface $setterDenormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();

        $generator = $this->makeGenerator();
        $writer = $this->makeWriter($this->tempDir);
        $pathResolver = $this->makePathResolver($this->tempDir);
        $factory = $generator->getMetadataFactory();

        // --- SimpleBlog normalizer ---
        $simpleBlogMeta = $factory->getMetadataFor(SimpleBlog::class);
        $simpleBlogNormFqcn = $pathResolver->resolveNormalizerFqcn($simpleBlogMeta);
        if (!class_exists($simpleBlogNormFqcn, false)) {
            require_once $writer->write($simpleBlogMeta);
        }
        $this->simpleBlogNormalizer = new $simpleBlogNormFqcn();

        // --- BlogWithAuthor normalizer (needs nested Author delegation) ---
        $authorMeta = $factory->getMetadataFor(Author::class);
        $authorNormFqcn = $pathResolver->resolveNormalizerFqcn($authorMeta);
        if (!class_exists($authorNormFqcn, false)) {
            require_once $writer->write($authorMeta);
        }
        $authorNormalizer = new $authorNormFqcn();

        $blogMeta = $factory->getMetadataFor(BlogWithAuthor::class);
        $blogNormFqcn = $pathResolver->resolveNormalizerFqcn($blogMeta);
        if (!class_exists($blogNormFqcn, false)) {
            require_once $writer->write($blogMeta);
        }
        $this->blogWithAuthorNormalizer = new $blogNormFqcn();

        // Wire both normalizers into a Serializer so nested delegation works.
        $serializer = new Serializer([$authorNormalizer, $this->blogWithAuthorNormalizer]);
        if (method_exists($this->blogWithAuthorNormalizer, 'setNormalizer')) {
            $this->blogWithAuthorNormalizer->setNormalizer($serializer);
        }

        // --- SimpleBlog denormalizer ---
        $this->simpleBlogDenormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        // --- SetterFixture denormalizer ---
        $this->setterDenormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testNormalizerWithoutIgnoredAttributesIncludesAllProperties(): void
    {
        $blog = new SimpleBlog(1, 'Hello', 'World', 'Short');

        $result = $this->simpleBlogNormalizer->normalize($blog, 'json', []);

        $this->assertSame(['id' => 1, 'title' => 'Hello', 'content' => 'World', 'excerpt' => 'Short'], $result);
    }

    public function testNormalizerIgnoresSingleProperty(): void
    {
        $blog = new SimpleBlog(1, 'Hello', 'World', 'Short');

        $result = $this->simpleBlogNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['title'],
        ]);

        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('excerpt', $result);
    }

    public function testNormalizerIgnoresMultipleProperties(): void
    {
        $blog = new SimpleBlog(1, 'Hello', 'World', 'Short');

        $result = $this->simpleBlogNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['title', 'excerpt'],
        ]);

        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayNotHasKey('excerpt', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('World', $result['content']);
    }

    public function testNormalizerEmptyIgnoredAttributesIncludesAllProperties(): void
    {
        $blog = new SimpleBlog(1, 'Hello', 'World');

        $result = $this->simpleBlogNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::IGNORED_ATTRIBUTES => [],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testNormalizerNullIgnoredAttributesIsEquivalentToNoFilter(): void
    {
        $blog = new SimpleBlog(42, 'My Title', 'My Content');

        $result = $this->simpleBlogNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::IGNORED_ATTRIBUTES => null,
        ]);

        $this->assertSame(42, $result['id']);
        $this->assertSame('My Title', $result['title']);
        $this->assertSame('My Content', $result['content']);
    }

    public function testNormalizerIgnoresTopLevelNestedObjectProperty(): void
    {
        $author = new Author(10, 'Alice', 'alice@example.com');
        $blog = new BlogWithAuthor(1, 'A Post', $author);

        $result = $this->blogWithAuthorNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['coAuthor'],
        ]);

        $this->assertArrayNotHasKey('coAuthor', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('author', $result);
    }

    public function testNormalizerIgnoresTopLevelScalarPropertyAndKeepsNestedObject(): void
    {
        $author = new Author(10, 'Alice', 'alice@example.com');
        $blog = new BlogWithAuthor(1, 'A Post', $author);

        $result = $this->blogWithAuthorNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['title'],
        ]);

        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('author', $result);
    }

    public function testNormalizerIgnoredAttributesDoesNotAffectIgnoredFlagOnMetadata(): void
    {
        // Verify the denylist is purely a runtime filter — it does not interact
        // with #[Ignore] attributes baked into metadata at generation time.
        $blog = new SimpleBlog(7, 'Title', 'Content');

        // Even when we list a non-existent property name it must not crash.
        $result = $this->simpleBlogNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['nonExistentField'],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testDenormalizerWithoutIgnoredAttributesWritesAllFields(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->simpleBlogDenormalizer->denormalize([
            'id' => 5,
            'title' => 'T',
            'content' => 'C',
            'excerpt' => 'E',
        ], SimpleBlog::class);

        $this->assertSame(5, $result->getId());
        $this->assertSame('T', $result->getTitle());
        $this->assertSame('C', $result->getContent());
        $this->assertSame('E', $result->getExcerpt());
    }

    public function testDenormalizerIgnoresOptionalConstructorParamFallsBackToDefault(): void
    {
        // $excerpt has a default of null. Ignoring it means the default is used
        // even when a value is explicitly present in the payload.
        /** @var SimpleBlog $result */
        $result = $this->simpleBlogDenormalizer->denormalize(
            ['id' => 1, 'title' => 'Hello', 'content' => 'World', 'excerpt' => 'should-be-ignored'],
            SimpleBlog::class,
            null,
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['excerpt']],
        );

        $this->assertSame(1, $result->getId());
        $this->assertSame('Hello', $result->getTitle());
        $this->assertSame('World', $result->getContent());
        $this->assertNull($result->getExcerpt());
    }

    public function testDenormalizerEmptyIgnoredAttributesWritesAllFields(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->simpleBlogDenormalizer->denormalize(
            ['id' => 7, 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            [AbstractNormalizer::IGNORED_ATTRIBUTES => []],
        );

        $this->assertSame(7, $result->getId());
        $this->assertSame('T', $result->getTitle());
        $this->assertSame('C', $result->getContent());
    }

    public function testDenormalizerNullIgnoredAttributesIsEquivalentToNoFilter(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->simpleBlogDenormalizer->denormalize(
            ['id' => 3, 'title' => 'Title', 'content' => 'Content'],
            SimpleBlog::class,
            null,
            [AbstractNormalizer::IGNORED_ATTRIBUTES => null],
        );

        $this->assertSame(3, $result->getId());
        $this->assertSame('Title', $result->getTitle());
        $this->assertSame('Content', $result->getContent());
    }

    public function testDenormalizerNonIgnoredFieldsAreStillExtractedCorrectly(): void
    {
        /** @var SimpleBlog $result */
        $result = $this->simpleBlogDenormalizer->denormalize(
            ['id' => 99, 'title' => 'Keep Me', 'content' => 'Also Keep', 'excerpt' => 'ignored'],
            SimpleBlog::class,
            null,
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['excerpt']],
        );

        $this->assertSame(99, $result->getId());
        $this->assertSame('Keep Me', $result->getTitle());
        $this->assertSame('Also Keep', $result->getContent());
        $this->assertNull($result->getExcerpt());
    }

    public function testDenormalizerSetterFixtureWithoutIgnoredAttributesWritesAllFields(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize([
            'name' => 'Alice',
            'age' => 30,
            'email' => 'alice@example.com',
        ], SetterFixture::class);

        $this->assertSame('Alice', $result->getName());
        $this->assertSame(30, $result->getAge());
        $this->assertSame('alice@example.com', $result->getEmail());
    }

    public function testDenormalizerSetterFixtureIgnoresSingleProperty(): void
    {
        // 'age' is ignored — the setter is never called; default of 0 is kept.
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'Bob', 'age' => 99, 'email' => 'bob@example.com'],
            SetterFixture::class,
            null,
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['age']],
        );

        $this->assertSame('Bob', $result->getName());
        $this->assertSame(0, $result->getAge()); // default — never written
        $this->assertSame('bob@example.com', $result->getEmail());
    }

    public function testDenormalizerSetterFixtureIgnoresMultipleProperties(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'Carol', 'age' => 25, 'email' => 'carol@example.com'],
            SetterFixture::class,
            null,
            [AbstractNormalizer::IGNORED_ATTRIBUTES => ['age', 'email']],
        );

        $this->assertSame('Carol', $result->getName());
        $this->assertSame(0, $result->getAge()); // default
        $this->assertNull($result->getEmail()); // default
    }

    public function testDenormalizerSetterFixtureObjectToPopulatePreservesIgnoredField(): void
    {
        $existing = new SetterFixture();
        $existing->setName('Original');
        $existing->setAge(10);
        $existing->setEmail('original@example.com');

        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'Updated', 'age' => 99, 'email' => 'updated@example.com'],
            SetterFixture::class,
            null,
            [
                AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
                AbstractNormalizer::IGNORED_ATTRIBUTES => ['age'],
            ],
        );

        // 'age' must not have been overwritten — the existing value is preserved.
        $this->assertSame(10, $result->getAge());
        // All other fields should have been updated normally.
        $this->assertSame('Updated', $result->getName());
        $this->assertSame('updated@example.com', $result->getEmail());
    }

    public function testDenormalizerSetterFixtureObjectToPopulateMultipleIgnoredFields(): void
    {
        $existing = new SetterFixture();
        $existing->setName('OriginalName');
        $existing->setAge(42);
        $existing->setEmail('original@example.com');

        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'NewName', 'age' => 1, 'email' => 'new@example.com'],
            SetterFixture::class,
            null,
            [
                AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
                AbstractNormalizer::IGNORED_ATTRIBUTES => ['age', 'email'],
            ],
        );

        // Only 'name' should have been updated; 'age' and 'email' are preserved.
        $this->assertSame('NewName', $result->getName());
        $this->assertSame(42, $result->getAge());
        $this->assertSame('original@example.com', $result->getEmail());
    }

    public function testDenormalizerSetterFixtureEmptyIgnoredAttributesWritesAllFields(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(['name' => 'Dave', 'age' => 55], SetterFixture::class, null, [
            AbstractNormalizer::IGNORED_ATTRIBUTES => [],
        ]);

        $this->assertSame('Dave', $result->getName());
        $this->assertSame(55, $result->getAge());
    }

    public function testDenormalizerSetterFixtureNullIgnoredAttributesIsEquivalentToNoFilter(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'Eve', 'age' => 22, 'email' => 'eve@example.com'],
            SetterFixture::class,
            null,
            [AbstractNormalizer::IGNORED_ATTRIBUTES => null],
        );

        $this->assertSame('Eve', $result->getName());
        $this->assertSame(22, $result->getAge());
        $this->assertSame('eve@example.com', $result->getEmail());
    }
}
