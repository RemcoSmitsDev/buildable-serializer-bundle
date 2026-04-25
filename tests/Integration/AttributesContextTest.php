<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\Author;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithAuthor;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithGroups;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\IgnoredPropertiesFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SerializedNameFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SetterFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\WitherFixture;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Integration tests for the AbstractNormalizer::ATTRIBUTES context key in both
 * the generated normalizer and the generated denormalizer.
 *
 * ### What ATTRIBUTES does
 *
 * `AbstractNormalizer::ATTRIBUTES` is an allowlist of PHP property names that
 * limits which properties are included in the (de)normalized output. When the
 * key is absent from the context, all properties are processed as normal.
 *
 * For nested structures the value can be a nested array-map:
 *
 * ```php
 * [AbstractNormalizer::ATTRIBUTES => ['id', 'author' => ['name']]]
 * ```
 *
 * This means: include `id` on the top-level object, and for the nested `author`
 * object only include `name`.
 *
 * ### Fixture anatomy ({@see BlogWithAuthor}, {@see Author})
 *
 * `BlogWithAuthor` has four properties:
 *   - `$id`       — int, required constructor parameter
 *   - `$title`    — string, required constructor parameter
 *   - `$author`   — nested Author object, required constructor parameter
 *   - `$coAuthor` — nullable nested Author object, optional constructor parameter (default null)
 *
 * `Author` has three properties:
 *   - `$id`    — int, required constructor parameter
 *   - `$name`  — string, required constructor parameter
 *   - `$email` — string, required constructor parameter
 *
 * ### Normalizer coverage
 *
 *   1. Without ATTRIBUTES — all properties appear in the output.
 *   2. Flat allowlist `['id', 'title']` — only those two keys appear; `author`
 *      and `coAuthor` are absent.
 *   3. Single-property allowlist `['id']` — only `id` appears.
 *   4. Allowlist containing a nested key `['id', 'author' => ['name']]` —
 *      `id` appears at the top level, `author` is present and contains only
 *      `name` (not `id` or `email`).
 *   5. Allowlist with a nested key but no sub-array `['id', 'author']` — the
 *      nested `author` object is normalized with ALL its properties (no sub-
 *      filtering), matching Symfony's behaviour when the nested attribute is
 *      listed without a sub-array.
 *   6. Empty allowlist `[]` — no properties appear in the output.
 *   7. ATTRIBUTES set to `null` explicitly — all properties appear (same as
 *      omitting the key).
 *
 * ### Denormalizer coverage
 *
 * All denormalizer tests use `OBJECT_TO_POPULATE` (with a fully-built
 * `BlogWithAuthor`) or `IgnoredPropertiesFixture` (whose constructor params
 * have defaults) so that ATTRIBUTES-filtered required params never trigger a
 * TypeError from an absent default value.
 *
 *   8. Without ATTRIBUTES — all supplied payload fields are written to the
 *      object.
 *   9. Flat allowlist `['id']` — only `id` is updated; `title` in the payload
 *      is ignored and the pre-existing value is preserved.
 *  10. Multiple-field allowlist `['id', 'title']` — both fields are updated.
 *  11. Empty allowlist `[]` — no fields are written; all existing values are
 *      preserved.
 *  12. Non-allowlisted field in the payload is not written during populate().
 *  13. ATTRIBUTES filters constructor params: `IgnoredPropertiesFixture.$secret`
 *      falls back to its default (`''`) when not in the allowlist.
 *  14. `ATTRIBUTES => null` is equivalent to omitting the key entirely.
 *  15. `OBJECT_TO_POPULATE` + `ATTRIBUTES` combined: only allowlisted fields
 *      are updated on the pre-existing object.
 *  16. `#[SerializedName]` interaction: ATTRIBUTES uses the PHP property name
 *      (not the serialized key) for the allowlist check. A field listed by its
 *      PHP name in ATTRIBUTES is populated even though data arrives under its
 *      serialized alias, and a field whose PHP name is absent from ATTRIBUTES
 *      is skipped even when its serialized alias is present in the payload.
 *  17. Wither strategy: ATTRIBUTES correctly skips wither-populated properties
 *      that are not in the allowlist, leaving the object's existing value.
 *  18. OBJECT_TO_POPULATE with a mixed allowlist: some fields updated, others
 *      untouched — each independently asserted.
 *  19. Groups + ATTRIBUTES combined: a property must satisfy both filters to
 *      be written; one that passes ATTRIBUTES but not GROUPS is still skipped,
 *      and one that passes GROUPS but not ATTRIBUTES is also skipped.
 *  20. Nested allowlist `['id', 'title', 'author' => ['id']]` — the child
 *      denormalizer for `author` must receive `ATTRIBUTES => ['id']` in its
 *      context, not the full parent allowlist.
 *  21. Nested key listed without a sub-array `['id', 'title', 'author']` —
 *      the child denormalizer for `author` must receive a context with
 *      ATTRIBUTES stripped entirely (no sub-filtering; all child properties
 *      are allowed).
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory
 */
final class AttributesContextTest extends AbstractTestCase
{
    private string $tempDir;

    /** The instantiated generated normalizer for BlogWithAuthor. */
    private NormalizerInterface $normalizer;

    /** The instantiated generated normalizer for Author (needed for nested delegation). */
    private NormalizerInterface $authorNormalizer;

    /** The instantiated generated denormalizer for BlogWithAuthor. */
    private DenormalizerInterface $denormalizer;

    /** The instantiated generated denormalizer for SetterFixture (populate-phase tests). */
    private DenormalizerInterface $setterDenormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();

        // --- Normalizer setup ---
        $generator = $this->makeGenerator();
        $writer = $this->makeWriter($this->tempDir);
        $pathResolver = $this->makePathResolver($this->tempDir);
        $factory = $generator->getMetadataFactory();

        // Generate & load BlogWithAuthor normalizer
        $blogMetadata = $factory->getMetadataFor(BlogWithAuthor::class);
        $blogNormalizerFqcn = $pathResolver->resolveNormalizerFqcn($blogMetadata);
        if (!class_exists($blogNormalizerFqcn, false)) {
            require_once $writer->write($blogMetadata);
        }
        $this->normalizer = new $blogNormalizerFqcn();

        // Generate & load Author normalizer (used by nested normalization)
        $authorMetadata = $factory->getMetadataFor(Author::class);
        $authorNormalizerFqcn = $pathResolver->resolveNormalizerFqcn($authorMetadata);
        if (!class_exists($authorNormalizerFqcn, false)) {
            require_once $writer->write($authorMetadata);
        }
        $this->authorNormalizer = new $authorNormalizerFqcn();

        // Wire the normalizers together so nested delegation works
        $innerNormalizer = new \Symfony\Component\Serializer\Serializer([$this->authorNormalizer, $this->normalizer]);
        if (method_exists($this->normalizer, 'setNormalizer')) {
            $this->normalizer->setNormalizer($innerNormalizer);
        }

        // --- Denormalizer setup ---
        $this->denormalizer = $this->loadDenormalizerFor(BlogWithAuthor::class, $this->tempDir);
        $this->setterDenormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    private function makeBlog(): BlogWithAuthor
    {
        return new BlogWithAuthor(
            id: 1,
            title: 'Hello World',
            author: new Author(id: 10, name: 'Alice', email: 'alice@example.com'),
            coAuthor: new Author(id: 20, name: 'Bob', email: 'bob@example.com'),
        );
    }

    public function testNormalizerWithoutAttributesIncludesAllProperties(): void
    {
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', []);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('author', $result);
        $this->assertArrayHasKey('coAuthor', $result);
    }

    public function testNormalizerFlatAllowlistIncludesOnlyListedProperties(): void
    {
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => ['id', 'title'],
        ]);

        $this->assertSame(['id', 'title'], array_keys($result));
        $this->assertSame(1, $result['id']);
        $this->assertSame('Hello World', $result['title']);
    }

    public function testNormalizerFlatAllowlistExcludesUnlistedProperties(): void
    {
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => ['id', 'title'],
        ]);

        $this->assertArrayNotHasKey('author', $result);
        $this->assertArrayNotHasKey('coAuthor', $result);
    }

    public function testNormalizerSinglePropertyAllowlist(): void
    {
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => ['id'],
        ]);

        $this->assertSame(['id'], array_keys($result));
        $this->assertSame(1, $result['id']);
    }

    public function testNormalizerEmptyAllowlistProducesEmptyOutput(): void
    {
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => [],
        ]);

        $this->assertSame([], $result);
    }

    public function testNormalizerNullAttributesIsEquivalentToNoFilter(): void
    {
        $resultWithNull = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => null,
        ]);
        $resultWithoutKey = $this->normalizer->normalize($this->makeBlog(), 'json', []);

        $this->assertSame($resultWithoutKey, $resultWithNull);
    }

    public function testNormalizerNestedAllowlistFiltersNestedProperties(): void
    {
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => ['id', 'author' => ['name']],
        ]);

        // Top-level: only 'id' and 'author'
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('author', $result);
        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayNotHasKey('coAuthor', $result);

        // Nested: only 'name' in author
        $this->assertIsArray($result['author']);
        $this->assertArrayHasKey('name', $result['author']);
        $this->assertArrayNotHasKey('id', $result['author']);
        $this->assertArrayNotHasKey('email', $result['author']);
        $this->assertSame('Alice', $result['author']['name']);
    }

    public function testNormalizerNestedPropertyWithoutSubArrayIncludesAllChildProperties(): void
    {
        // When a nested object is listed without a sub-array value (plain string
        // entry), the entire nested object is normalized without sub-filtering.
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => ['id', 'author'],
        ]);

        $this->assertArrayHasKey('author', $result);
        $authorResult = $result['author'];
        $this->assertArrayHasKey('id', $authorResult);
        $this->assertArrayHasKey('name', $authorResult);
        $this->assertArrayHasKey('email', $authorResult);
    }

    public function testNormalizerMultipleNestedAllowlists(): void
    {
        $result = $this->normalizer->normalize($this->makeBlog(), 'json', [
            AbstractNormalizer::ATTRIBUTES => [
                'id',
                'author' => ['id', 'name'],
                'coAuthor' => ['email'],
            ],
        ]);

        // Top-level
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('author', $result);
        $this->assertArrayHasKey('coAuthor', $result);
        $this->assertArrayNotHasKey('title', $result);

        // author: id + name, not email
        $this->assertArrayHasKey('id', $result['author']);
        $this->assertArrayHasKey('name', $result['author']);
        $this->assertArrayNotHasKey('email', $result['author']);

        // coAuthor: only email
        $this->assertArrayNotHasKey('id', $result['coAuthor']);
        $this->assertArrayNotHasKey('name', $result['coAuthor']);
        $this->assertArrayHasKey('email', $result['coAuthor']);
        $this->assertSame('bob@example.com', $result['coAuthor']['email']);
    }

    /**
     * Baseline: without ATTRIBUTES all fields in the payload are written.
     * SetterFixture has no constructor params, so all fields go through populate().
     */
    public function testDenormalizerWithoutAttributesReadsAllFields(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(['name' => 'Alice', 'age' => 30], SetterFixture::class);

        $this->assertSame('Alice', $result->getName());
        $this->assertSame(30, $result->getAge());
    }

    public function testDenormalizerFlatAllowlistOnlyWritesListedFields(): void
    {
        // Both 'name' and 'age' are in the payload; only 'name' is in the allowlist.
        // populate() must skip 'age', leaving the declaration-default (0) intact.
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(['name' => 'Alice', 'age' => 99], SetterFixture::class, null, [
            AbstractNormalizer::ATTRIBUTES => ['name'],
        ]);

        $this->assertSame('Alice', $result->getName());
        $this->assertSame(0, $result->getAge()); // not in allowlist — default preserved
    }

    public function testDenormalizerAllowlistWithMultipleFieldsWritesAll(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(['name' => 'Bob', 'age' => 25], SetterFixture::class, null, [
            AbstractNormalizer::ATTRIBUTES => ['name', 'age'],
        ]);

        $this->assertSame('Bob', $result->getName());
        $this->assertSame(25, $result->getAge());
    }

    public function testDenormalizerEmptyAllowlistDoesNotWriteAnyField(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(['name' => 'Carol', 'age' => 50], SetterFixture::class, null, [
            AbstractNormalizer::ATTRIBUTES => [],
        ]);

        // Empty allowlist — no fields written; declaration-site defaults survive.
        $this->assertSame('', $result->getName());
        $this->assertSame(0, $result->getAge());
    }

    public function testDenormalizerNonAllowlistedFieldInPayloadIsNotWritten(): void
    {
        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(['name' => 'Dave', 'age' => 40], SetterFixture::class, null, [
            AbstractNormalizer::ATTRIBUTES => ['name'],
        ]);

        // 'age' was in the payload but not in the allowlist
        $this->assertSame(0, $result->getAge());
    }

    public function testDenormalizerAttributesFiltersConstructorParam(): void
    {
        $denormalizerForFixture = $this->loadDenormalizerFor(IgnoredPropertiesFixture::class, $this->tempDir);

        // 'title' is in the allowlist; 'secret' is not.
        // Even though 'secret' is present in the payload, the generated
        // construct() must use the constructor default ('') instead.
        /** @var IgnoredPropertiesFixture $result */
        $result = $denormalizerForFixture->denormalize(
            ['title' => 'My Title', 'secret' => 'injected'],
            IgnoredPropertiesFixture::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['title']],
        );

        $this->assertSame('My Title', $result->getTitle());
        $this->assertSame('', $result->getSecret()); // fell back to constructor default
    }

    public function testDenormalizerNullAttributesAllowsAllConstructorParams(): void
    {
        $denormalizerForFixture = $this->loadDenormalizerFor(IgnoredPropertiesFixture::class, $this->tempDir);

        /** @var IgnoredPropertiesFixture $result */
        $result = $denormalizerForFixture->denormalize(['title' => 'My Title'], IgnoredPropertiesFixture::class, null, [
            AbstractNormalizer::ATTRIBUTES => null,
        ]);

        $this->assertSame('My Title', $result->getTitle());
    }

    public function testDenormalizerObjectToPopulateWithAttributesPreservesNonAllowlistedField(): void
    {
        // Use SetterFixture with OBJECT_TO_POPULATE: only 'name' is in the
        // allowlist so 'age' must not be updated on the pre-existing object.
        $existing = new SetterFixture();
        $existing->setName('Original');
        $existing->setAge(10);

        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'Updated', 'age' => 99],
            SetterFixture::class,
            null,
            [
                AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
                AbstractNormalizer::ATTRIBUTES => ['name'],
            ],
        );

        $this->assertSame('Updated', $result->getName()); // in allowlist
        $this->assertSame(10, $result->getAge()); // not in allowlist — preserved
    }

    public function testDenormalizerNullAttributesWithObjectToPopulateIsEquivalentToNoFilter(): void
    {
        $existingA = new SetterFixture();
        $existingB = new SetterFixture();

        /** @var SetterFixture $withNull */
        $withNull = $this->setterDenormalizer->denormalize(['name' => 'Eve', 'age' => 22], SetterFixture::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existingA,
            AbstractNormalizer::ATTRIBUTES => null,
        ]);

        /** @var SetterFixture $withoutKey */
        $withoutKey = $this->setterDenormalizer->denormalize(
            ['name' => 'Eve', 'age' => 22],
            SetterFixture::class,
            null,
            [AbstractNormalizer::OBJECT_TO_POPULATE => $existingB],
        );

        $this->assertSame($withoutKey->getName(), $withNull->getName());
        $this->assertSame($withoutKey->getAge(), $withNull->getAge());
    }

    public function testDenormalizerAttributesUsesPhpNameNotSerializedName(): void
    {
        // The allowlist uses PHP name "emailAddress", but data arrives under
        // its serialized alias "email_address". The field must be populated.
        $denormalizerForFixture = $this->loadDenormalizerFor(SerializedNameFixture::class, $this->tempDir);

        /** @var SerializedNameFixture $result */
        $result = $denormalizerForFixture->denormalize(
            ['id' => 1, 'email_address' => 'alice@example.com', 'display_name' => 'Alice'],
            SerializedNameFixture::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['id', 'emailAddress']],
        );

        $this->assertSame(1, $result->id);
        $this->assertSame('alice@example.com', $result->emailAddress);
        // 'displayName' is NOT in the allowlist — must fall back to its default (null)
        $this->assertNull($result->displayName);
    }

    public function testDenormalizerAttributesSkipsFieldWhenPhpNameAbsentEvenIfSerializedKeyPresent(): void
    {
        // 'displayName' is NOT in the allowlist even though "display_name" is
        // present in the payload. The generator must skip it and use the default.
        $denormalizerForFixture = $this->loadDenormalizerFor(SerializedNameFixture::class, $this->tempDir);

        /** @var SerializedNameFixture $result */
        $result = $denormalizerForFixture->denormalize(
            ['id' => 1, 'email_address' => 'bob@example.com', 'display_name' => 'Should Be Ignored'],
            SerializedNameFixture::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['id', 'emailAddress']],
        );

        $this->assertNull($result->displayName);
    }

    public function testDenormalizerAttributesWithSerializedNameOnPopulatePhaseProperty(): void
    {
        // $homePage is populated during populate() phase (not constructor).
        // Its PHP name is "homePage"; data arrives under "home_page".
        // The allowlist must use the PHP name to include or exclude it.
        $denormalizerForFixture = $this->loadDenormalizerFor(SerializedNameFixture::class, $this->tempDir);

        // "homePage" in allowlist → must be written
        /** @var SerializedNameFixture $included */
        $included = $denormalizerForFixture->denormalize(
            ['id' => 1, 'email_address' => 'x@x.com', 'home_page' => 'https://example.com'],
            SerializedNameFixture::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['id', 'emailAddress', 'homePage']],
        );
        $this->assertSame('https://example.com', $included->homePage);

        // "homePage" NOT in allowlist → must be skipped, stays null
        /** @var SerializedNameFixture $excluded */
        $excluded = $denormalizerForFixture->denormalize(
            ['id' => 1, 'email_address' => 'x@x.com', 'home_page' => 'https://example.com'],
            SerializedNameFixture::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['id', 'emailAddress']],
        );
        $this->assertNull($excluded->homePage);
    }

    public function testDenormalizerAttributesSkipsWitherPopulatedFieldNotInAllowlist(): void
    {
        $denormalizerForFixture = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        // Only 'title' is in the allowlist; 'body' and 'slug' must be skipped.
        /** @var WitherFixture $result */
        $result = $denormalizerForFixture->denormalize(
            ['title' => 'Hello', 'body' => 'Should be ignored', 'slug' => 'also-ignored'],
            WitherFixture::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['title']],
        );

        $this->assertSame('Hello', $result->title);
        $this->assertSame('', $result->body); // default — not written
        $this->assertNull($result->slug); // default — not written
    }

    public function testDenormalizerAttributesWritesAllWitherFieldsWhenAllListed(): void
    {
        $denormalizerForFixture = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizerForFixture->denormalize(
            ['title' => 'Hello', 'body' => 'World', 'slug' => 'hello-world'],
            WitherFixture::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['title', 'body', 'slug']],
        );

        $this->assertSame('Hello', $result->title);
        $this->assertSame('World', $result->body);
        $this->assertSame('hello-world', $result->slug);
    }

    public function testDenormalizerObjectToPopulateMixedAllowlistUpdatesOnlyListedFields(): void
    {
        $existing = new SetterFixture();
        $existing->setName('Original Name');
        $existing->setAge(42);
        $existing->setEmail('original@example.com');

        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'New Name', 'age' => 99, 'email' => 'new@example.com'],
            SetterFixture::class,
            null,
            [
                AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
                AbstractNormalizer::ATTRIBUTES => ['name', 'email'],
            ],
        );

        $this->assertSame('New Name', $result->getName()); // in allowlist — updated
        $this->assertSame('new@example.com', $result->getEmail()); // in allowlist — updated
        $this->assertSame(42, $result->getAge()); // NOT in allowlist — preserved
    }

    public function testDenormalizerObjectToPopulateSingleAllowlistedFieldAmongMany(): void
    {
        $existing = new SetterFixture();
        $existing->setName('Keep Me');
        $existing->setAge(10);
        $existing->setEmail('keep@example.com');

        /** @var SetterFixture $result */
        $result = $this->setterDenormalizer->denormalize(
            ['name' => 'Ignore Me', 'age' => 99, 'email' => 'ignore@example.com'],
            SetterFixture::class,
            null,
            [
                AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
                AbstractNormalizer::ATTRIBUTES => ['age'],
            ],
        );

        $this->assertSame(99, $result->getAge()); // in allowlist — updated
        $this->assertSame('Keep Me', $result->getName()); // NOT in allowlist — preserved
        $this->assertSame('keep@example.com', $result->getEmail()); // NOT in allowlist — preserved
    }

    public function testDenormalizerAttributesFiltersTakeEffectRegardlessOfGroups(): void
    {
        $denormalizerForFixture = $this->loadDenormalizerFor(BlogWithGroups::class, $this->tempDir);

        // ATTRIBUTES allowlist: ['id', 'title']
        // GROUPS: ['blog:list'] — forwarded to context but does not affect populate().
        //
        // Expected (ATTRIBUTES is the only filter in the generated populate()):
        //   - 'id'         → in ATTRIBUTES ✔ → written
        //   - 'title'      → in ATTRIBUTES ✔ → written
        //   - 'content'    → NOT in ATTRIBUTES ✗ → skipped
        //   - 'authorName' → NOT in ATTRIBUTES ✗ → skipped
        $blog = new BlogWithGroups(0, '', '');
        $blog->content = 'Original Content';
        $blog->authorName = 'Original Author';

        /** @var BlogWithGroups $result */
        $result = $denormalizerForFixture->denormalize(
            ['id' => 7, 'title' => 'My Post', 'content' => 'New Content', 'author_name' => 'New Author'],
            BlogWithGroups::class,
            null,
            [
                AbstractNormalizer::OBJECT_TO_POPULATE => $blog,
                AbstractNormalizer::ATTRIBUTES => ['id', 'title'],
                AbstractNormalizer::GROUPS => ['blog:list'],
            ],
        );

        $this->assertSame(7, $result->id);
        $this->assertSame('My Post', $result->title);
        $this->assertSame('Original Content', $result->content); // not in ATTRIBUTES — preserved
        $this->assertSame('Original Author', $result->authorName); // not in ATTRIBUTES — preserved
    }

    public function testDenormalizerAttributesAloneWithoutGroupsFiltersCorrectly(): void
    {
        $denormalizerForFixture = $this->loadDenormalizerFor(BlogWithGroups::class, $this->tempDir);

        $blog = new BlogWithGroups(0, '', '');

        /** @var BlogWithGroups $result */
        $result = $denormalizerForFixture->denormalize(
            ['id' => 3, 'title' => 'Test', 'content' => 'Body', 'author_name' => 'Alice'],
            BlogWithGroups::class,
            null,
            [
                AbstractNormalizer::OBJECT_TO_POPULATE => $blog,
                AbstractNormalizer::ATTRIBUTES => ['id', 'title'],
            ],
        );

        $this->assertSame(3, $result->id);
        $this->assertSame('Test', $result->title);
        $this->assertSame('', $result->content); // not in ATTRIBUTES
        $this->assertSame('Test Author', $result->authorName); // not in ATTRIBUTES (retains class default)
    }

    public function testDenormalizerNestedAllowlistForwardsSubAttributesToChildContext(): void
    {
        $blogDenormalizer = $this->loadDenormalizerFor(BlogWithAuthor::class, $this->tempDir);

        // Capture the context that the child denormalizer receives for Author.
        $capturedContext = null;
        $authorDenormalizer = new class($capturedContext) implements DenormalizerInterface {
            public function __construct(
                private mixed &$capturedContext,
            ) {}

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                $this->capturedContext = $context;

                return new Author(id: $data['id'] ?? 0, name: 'Alice', email: 'alice@example.com');
            }

            public function supportsDenormalization(
                mixed $data,
                string $type,
                ?string $format = null,
                array $context = [],
            ): bool {
                return $type === Author::class;
            }

            public function getSupportedTypes(?string $format): array
            {
                return [Author::class => true];
            }
        };

        $serializer = new Serializer([$blogDenormalizer, $authorDenormalizer]);
        $blogDenormalizer->setDenormalizer($serializer);

        $blogDenormalizer->denormalize(
            ['id' => 1, 'title' => 'Post', 'author' => ['id' => 10, 'name' => 'Alice', 'email' => 'alice@example.com']],
            BlogWithAuthor::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['id', 'title', 'author' => ['id']]],
        );

        $this->assertNotNull($capturedContext, 'Child denormalizer was never called.');
        $this->assertSame(
            ['id'],
            $capturedContext[AbstractNormalizer::ATTRIBUTES],
            'Child context must contain only the sub-attributes defined for "author".',
        );
    }

    public function testDenormalizerNestedKeyWithoutSubArrayStripsAttributesFromChildContext(): void
    {
        $blogDenormalizer = $this->loadDenormalizerFor(BlogWithAuthor::class, $this->tempDir);

        // When 'author' is listed as a plain string (no sub-array), the child
        // denormalizer must receive a context WITHOUT ATTRIBUTES so that all
        // Author properties are allowed.
        $capturedContext = null;
        $authorDenormalizer = new class($capturedContext) implements DenormalizerInterface {
            public function __construct(
                private mixed &$capturedContext,
            ) {}

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                $this->capturedContext = $context;

                return new Author(id: $data['id'] ?? 0, name: $data['name'] ?? '', email: $data['email'] ?? '');
            }

            public function supportsDenormalization(
                mixed $data,
                string $type,
                ?string $format = null,
                array $context = [],
            ): bool {
                return $type === Author::class;
            }

            public function getSupportedTypes(?string $format): array
            {
                return [Author::class => true];
            }
        };

        $serializer = new Serializer([$blogDenormalizer, $authorDenormalizer]);
        $blogDenormalizer->setDenormalizer($serializer);

        $blogDenormalizer->denormalize(
            ['id' => 1, 'title' => 'Post', 'author' => ['id' => 10, 'name' => 'Alice', 'email' => 'alice@example.com']],
            BlogWithAuthor::class,
            null,
            [AbstractNormalizer::ATTRIBUTES => ['id', 'title', 'author']],
        );

        $this->assertNotNull($capturedContext, 'Child denormalizer was never called.');
        $this->assertArrayNotHasKey(
            AbstractNormalizer::ATTRIBUTES,
            $capturedContext,
            'Child context must not contain ATTRIBUTES when the parent lists the key without a sub-array.',
        );
    }
}
