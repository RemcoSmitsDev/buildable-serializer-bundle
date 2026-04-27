<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\Author;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Minimal fixture defined inline: a blog with a MaxDepth(1) author property.
 *
 * Kept in this file intentionally — it is a test-only model that is only
 * meaningful in the context of these max-depth integration tests.
 */
final class MaxDepthBlog
{
    #[MaxDepth(1)]
    private Author $author;

    private string $title;

    public function __construct(string $title, Author $author)
    {
        $this->title = $title;
        $this->author = $author;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }
}

/**
 * Integration tests for #[MaxDepth] support in generated normalizers.
 *
 * ### What ENABLE_MAX_DEPTH does
 *
 * `AbstractObjectNormalizer::ENABLE_MAX_DEPTH` is a runtime context flag that
 * gates whether the `#[MaxDepth]` metadata on a property is actually enforced.
 * When the flag is absent or `false` the depth guard is bypassed entirely and
 * the property is always normalized — matching Symfony's own behaviour in
 * `AbstractObjectNormalizer::isMaxDepthReached()`.
 *
 * The `max_depth` **feature flag** at code-generation time still controls
 * whether the depth-checking infrastructure is emitted at all (for classes that
 * carry `#[MaxDepth]` annotations). `ENABLE_MAX_DEPTH` is the complementary
 * *runtime* switch.
 *
 * ### What MAX_DEPTH_HANDLER does
 *
 * `AbstractObjectNormalizer::MAX_DEPTH_HANDLER` is an optional callable stored
 * in the context. It is only consulted when `ENABLE_MAX_DEPTH` is `true` **and**
 * the depth counter has reached the property's limit (i.e. the normal depth guard
 * would otherwise skip the property entirely).
 *
 * When the handler is present its return value replaces the property's normal
 * normalized output — even `null` is used as-is. When no handler is present the
 * property is simply omitted from the output, matching Symfony's default
 * behaviour.
 *
 * The callable receives five arguments, matching Symfony's contract exactly:
 *   1. `mixed  $attributeValue` — the raw (un-normalized) property value
 *   2. `object $object`         — the object being normalized
 *   3. `string $attributeName`  — the PHP property name
 *   4. `string|null $format`    — the requested format
 *   5. `array  $context`        — the full serialization context
 *
 * ### Fixture anatomy ({@see MaxDepthBlog}, {@see Author})
 *
 * `MaxDepthBlog` has two properties:
 *   - `$title`  — plain string, no depth constraint.
 *   - `$author` — nested `Author` object annotated with `#[MaxDepth(1)]`.
 *
 * ### Coverage
 *
 *   1.  Generated source contains the depth-check infrastructure variables.
 *   2.  Generated source gates the guard on `ENABLE_MAX_DEPTH`.
 *   3.  Generated source hard-codes the `MaxDepth(1)` limit as a literal `< 1`.
 *   4.  Generated source contains the max-depth comment.
 *   5.  Generated source references `MAX_DEPTH_HANDLER`.
 *   6.  Without `ENABLE_MAX_DEPTH` the author is always normalized (no guard).
 *   7.  With `ENABLE_MAX_DEPTH => false` the author is always normalized (no guard).
 *   8.  With `ENABLE_MAX_DEPTH => true` and depth at the limit, property is omitted
 *       when no handler is set.
 *   9.  With `ENABLE_MAX_DEPTH => true` and depth below the limit, the author is
 *       normalized normally.
 *  10.  `ENABLE_MAX_DEPTH => true`, depth at limit, handler present — handler return
 *       value is used as the property value.
 *  11.  Handler receives the correct five arguments in the correct order.
 *  12.  Handler returning `null` results in `null` being stored (not omitted).
 *  13.  Handler is NOT called when depth is within the limit.
 *  14.  Handler is NOT called when `ENABLE_MAX_DEPTH` is `false`.
 *  15.  Scalar properties (`$title`) are always present regardless of depth state.
 *  16.  The generated class implements `NormalizerAwareInterface`.
 *  17.  `supportsNormalization()` returns `true` for `MaxDepthBlog`.
 *  18.  `supportsNormalization()` returns `false` for other objects.
 *  19.  `getSupportedTypes()` includes `MaxDepthBlog`.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory
 */
final class MaxDepthTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated MaxDepthBlogNormalizer */
    private string $normalizerFqcn;

    /** @var NormalizerInterface The instantiated generated normalizer */
    private NormalizerInterface $normalizer;

    /** @var string Absolute path of the generated PHP file */
    private string $generatedFilePath;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $writer = $this->makeWriter($this->tempDir);
        $pathResolver = $this->makePathResolver($this->tempDir);
        $generator = $this->makeGenerator();
        $factory = $generator->getMetadataFactory();
        $metadata = $factory->getMetadataFor(MaxDepthBlog::class);

        $this->normalizerFqcn = $pathResolver->resolveNormalizerFqcn($metadata);

        // Always (re)generate so that the file path is valid and readable for
        // the source-inspection tests.
        $this->generatedFilePath = $writer->write($metadata);

        if (!class_exists($this->normalizerFqcn, false)) {
            require_once $this->generatedFilePath;
        }

        $this->normalizer = new $this->normalizerFqcn();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testGeneratedCodeContainsDepthCheckVariables(): void
    {
        $source = (string) file_get_contents($this->generatedFilePath);

        $this->assertTrue(
            str_contains($source, '_depthKey'),
            'Generated source must contain the $_depthKey variable for the depth counter lookup.',
        );
        $this->assertTrue(
            str_contains($source, '_currentDepth'),
            'Generated source must track the current depth in a $_currentDepth variable.',
        );
    }

    public function testGeneratedCodeGatesDepthCheckOnEnableMaxDepth(): void
    {
        $source = (string) file_get_contents($this->generatedFilePath);

        $this->assertTrue(
            str_contains($source, 'ENABLE_MAX_DEPTH'),
            'Generated source must gate the depth guard on AbstractObjectNormalizer::ENABLE_MAX_DEPTH.',
        );
    }

    public function testGeneratedCodeReferencesMaxDepthHandler(): void
    {
        $source = (string) file_get_contents($this->generatedFilePath);

        $this->assertTrue(
            str_contains($source, 'MAX_DEPTH_HANDLER'),
            'Generated source must reference AbstractObjectNormalizer::MAX_DEPTH_HANDLER for the handler branch.',
        );
    }

    public function testGeneratedCodeContainsMaxDepthLimit(): void
    {
        $source = (string) file_get_contents($this->generatedFilePath);

        // The generator emits the literal comparison `< 1` for #[MaxDepth(1)].
        $this->assertTrue(
            str_contains($source, '< 1'),
            'Generated source must contain the literal max-depth comparison "< 1" for MaxDepth(1).',
        );
    }

    public function testGeneratedCodeContainsMaxDepthComment(): void
    {
        $source = (string) file_get_contents($this->generatedFilePath);

        $this->assertTrue(
            str_contains($source, 'max-depth'),
            'Generated source must contain a "max-depth" comment to aid readability.',
        );
    }

    public function testWithoutEnableMaxDepthNestedPropertyIsAlwaysNormalized(): void
    {
        // When ENABLE_MAX_DEPTH is absent the depth guard is bypassed; the
        // nested author must always be delegated to the inner normalizer.
        $author = new Author(3, 'Carol', 'carol@example.com');
        $blog = new MaxDepthBlog('Fresh Blog', $author);

        $mockData = ['id' => 3, 'name' => 'Carol', 'email' => 'carol@example.com'];
        $normalizeCalls = 0;

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturnCallback(static function () use ($mockData, &$normalizeCalls): array {
            ++$normalizeCalls;

            return $mockData;
        });
        $this->normalizer->setNormalizer($mock);

        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertIsArray($result);
        $this->assertGreaterThan(
            0,
            $normalizeCalls,
            'The nested normalizer must be called when ENABLE_MAX_DEPTH is absent.',
        );
        $this->assertArrayHasKey('author', $result);
        $this->assertSame($mockData, $result['author']);
    }

    public function testWithEnableMaxDepthFalseNestedPropertyIsAlwaysNormalized(): void
    {
        // Explicitly passing ENABLE_MAX_DEPTH => false must also bypass the guard,
        // even when the depth counter is already at the limit.
        $author = new Author(4, 'Dave', 'dave@example.com');
        $blog = new MaxDepthBlog('False Flag Blog', $author);

        $mockData = ['id' => 4, 'name' => 'Dave', 'email' => 'dave@example.com'];
        $normalizeCalls = 0;

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturnCallback(static function () use ($mockData, &$normalizeCalls): array {
            ++$normalizeCalls;

            return $mockData;
        });
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => false,
            $depthKey => 1, // counter at the limit — guard must still be skipped
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(
            0,
            $normalizeCalls,
            'The nested normalizer must be called when ENABLE_MAX_DEPTH is false, regardless of depth counter.',
        );
        $this->assertArrayHasKey('author', $result);
    }

    public function testWithEnableMaxDepthTrueAndDepthAtLimitPropertyIsOmitted(): void
    {
        // ENABLE_MAX_DEPTH => true + depth counter already at the limit (1 == MaxDepth(1)):
        // the guard fires and the author property must be omitted entirely.
        $author = new Author(1, 'Alice', 'alice@example.com');
        $blog = new MaxDepthBlog('My Blog', $author);

        $normalizeCalls = 0;

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturnCallback(static function () use (&$normalizeCalls): array {
            ++$normalizeCalls;

            return ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'];
        });
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            $depthKey => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertSame(
            0,
            $normalizeCalls,
            'The nested normalizer must not be called when ENABLE_MAX_DEPTH is true and max depth is reached.',
        );
        $this->assertArrayNotHasKey(
            'author',
            $result,
            'The author property must be omitted when ENABLE_MAX_DEPTH is true and its max depth is exceeded.',
        );
    }

    public function testWithEnableMaxDepthTrueAndDepthBelowLimitPropertyIsNormalized(): void
    {
        // ENABLE_MAX_DEPTH => true + depth counter at 0 — within the MaxDepth(1) limit:
        // the guard passes and the author must be delegated normally.
        $author = new Author(2, 'Bob', 'bob@example.com');
        $blog = new MaxDepthBlog('Another Blog', $author);

        $mockData = ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'];
        $normalizeCalls = 0;

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturnCallback(static function () use ($mockData, &$normalizeCalls): array {
            ++$normalizeCalls;

            return $mockData;
        });
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            $depthKey => 0,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(
            0,
            $normalizeCalls,
            'The nested normalizer must be called when ENABLE_MAX_DEPTH is true and depth is within the limit.',
        );
        $this->assertArrayHasKey('author', $result);
        $this->assertSame($mockData, $result['author']);
    }

    public function testWithEnableMaxDepthTrueAndNoDepthContextCounterDefaultsToZero(): void
    {
        // When ENABLE_MAX_DEPTH is true but no depth counter exists in context,
        // the generated code defaults $_currentDepth to 0, which is < 1 (the limit),
        // so the author must be normalized.
        $author = new Author(5, 'Eve', 'eve@example.com');
        $blog = new MaxDepthBlog('Eve Blog', $author);

        $mockData = ['id' => 5, 'name' => 'Eve', 'email' => 'eve@example.com'];
        $normalizeCalls = 0;

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturnCallback(static function () use ($mockData, &$normalizeCalls): array {
            ++$normalizeCalls;

            return $mockData;
        });
        $this->normalizer->setNormalizer($mock);

        // No depth counter in context — defaults to 0, which is within the limit.
        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(
            0,
            $normalizeCalls,
            'The nested normalizer must be called when ENABLE_MAX_DEPTH is true and no prior depth counter exists.',
        );
        $this->assertArrayHasKey('author', $result);
    }

    public function testScalarPropertiesArePresentWhenEnableMaxDepthAbsent(): void
    {
        $author = new Author(1, 'Alice', 'alice@example.com');
        $blog = new MaxDepthBlog('Scalar Test', $author);

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturn([]);
        $this->normalizer->setNormalizer($mock);

        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertSame('Scalar Test', $result['title']);
    }

    public function testHandlerReturnValueIsUsedWhenDepthLimitExceeded(): void
    {
        // When ENABLE_MAX_DEPTH is true, depth is at the limit, and a handler
        // is present, the handler's return value must become the property value.
        $author = new Author(1, 'Alice', 'alice@example.com');
        $blog = new MaxDepthBlog('Handler Blog', $author);

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturn([]);
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');
        $handlerValue = ['id' => 1, 'name' => 'stub'];

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            AbstractObjectNormalizer::MAX_DEPTH_HANDLER => static fn() => $handlerValue,
            $depthKey => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('author', $result);
        $this->assertSame($handlerValue, $result['author']);
    }

    public function testHandlerReceivesCorrectArguments(): void
    {
        // Verify all five arguments are forwarded to the handler in the right order.
        $author = new Author(2, 'Bob', 'bob@example.com');
        $blog = new MaxDepthBlog('Args Blog', $author);

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturn([]);
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');
        $capturedArgs = null;
        $capturedContext = null;

        $handler = static function (
            mixed $attributeValue,
            object $object,
            string $attributeName,
            ?string $format,
            array $context,
        ) use (&$capturedArgs, &$capturedContext, $author, $blog): array {
            $capturedArgs = [
                'attributeValue' => $attributeValue,
                'object' => $object,
                'attributeName' => $attributeName,
                'format' => $format,
            ];
            $capturedContext = $context;

            return [];
        };

        $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            AbstractObjectNormalizer::MAX_DEPTH_HANDLER => $handler,
            $depthKey => 1,
        ]);

        $this->assertNotNull($capturedArgs, 'Handler must have been called.');
        $this->assertSame($author, $capturedArgs['attributeValue'], 'First arg must be the raw attribute value.');
        $this->assertSame($blog, $capturedArgs['object'], 'Second arg must be the object being normalized.');
        $this->assertSame('author', $capturedArgs['attributeName'], 'Third arg must be the PHP property name.');
        $this->assertSame('json', $capturedArgs['format'], 'Fourth arg must be the format string.');
        $this->assertIsArray($capturedContext, 'Fifth arg must be the context array.');
    }

    public function testHandlerReturningNullStoresNullNotOmitsProperty(): void
    {
        // A handler returning null must result in null being stored under the key,
        // NOT the property being omitted (Symfony's contract: return value is used
        // even if null).
        $author = new Author(3, 'Carol', 'carol@example.com');
        $blog = new MaxDepthBlog('Null Handler Blog', $author);

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturn([]);
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            AbstractObjectNormalizer::MAX_DEPTH_HANDLER => static fn() => null,
            $depthKey => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey(
            'author',
            $result,
            'Property must be present (with null value) when handler returns null.',
        );
        $this->assertNull($result['author']);
    }

    public function testHandlerIsNotCalledWhenDepthIsWithinLimit(): void
    {
        // When depth is within the limit the handler must never be invoked;
        // the property should be normalized normally.
        $author = new Author(4, 'Dave', 'dave@example.com');
        $blog = new MaxDepthBlog('Within Limit Blog', $author);

        $handlerCalled = false;
        $normalizeCalls = 0;

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturnCallback(static function () use (&$normalizeCalls): array {
            ++$normalizeCalls;

            return ['id' => 4];
        });
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            AbstractObjectNormalizer::MAX_DEPTH_HANDLER => static function () use (&$handlerCalled): array {
                $handlerCalled = true;

                return ['stub' => true];
            },
            $depthKey => 0, // within limit
        ]);

        $this->assertFalse($handlerCalled, 'Handler must not be called when depth is within the limit.');
        $this->assertGreaterThan(
            0,
            $normalizeCalls,
            'The nested normalizer must be called when depth is within the limit.',
        );
        $this->assertSame(['id' => 4], $result['author']);
    }

    public function testHandlerIsNotCalledWhenEnableMaxDepthIsFalse(): void
    {
        // When ENABLE_MAX_DEPTH is false the guard is bypassed entirely;
        // the handler must never be called even if depth is at the limit.
        $author = new Author(5, 'Eve', 'eve@example.com');
        $blog = new MaxDepthBlog('Disabled Guard Blog', $author);

        $handlerCalled = false;
        $normalizeCalls = 0;

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturnCallback(static function () use (&$normalizeCalls): array {
            ++$normalizeCalls;

            return ['id' => 5];
        });
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => false,
            AbstractObjectNormalizer::MAX_DEPTH_HANDLER => static function () use (&$handlerCalled): array {
                $handlerCalled = true;

                return ['stub' => true];
            },
            $depthKey => 1, // at limit, but guard is disabled
        ]);

        $this->assertFalse($handlerCalled, 'Handler must not be called when ENABLE_MAX_DEPTH is false.');
        $this->assertGreaterThan(
            0,
            $normalizeCalls,
            'The nested normalizer must be called when the guard is disabled.',
        );
        $this->assertSame(['id' => 5], $result['author']);
    }

    public function testScalarPropertiesArePresentWhenDepthLimitExceeded(): void
    {
        // Even when the depth guard fires and omits 'author', scalar properties
        // must still be present in the output.
        $author = new Author(1, 'Alice', 'alice@example.com');
        $blog = new MaxDepthBlog('Scalar With Limit', $author);

        $mock = $this->createMock(NormalizerInterface::class);
        $mock->method('normalize')->willReturn([]);
        $this->normalizer->setNormalizer($mock);

        $depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, MaxDepthBlog::class, 'author');

        $result = $this->normalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            $depthKey => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertSame('Scalar With Limit', $result['title']);
        $this->assertArrayNotHasKey('author', $result);
    }

    public function testNormalizerImplementsNormalizerAwareInterface(): void
    {
        $this->assertInstanceOf(NormalizerAwareInterface::class, $this->normalizer);
    }

    public function testNormalizerImplementsNormalizerInterface(): void
    {
        $this->assertInstanceOf(NormalizerInterface::class, $this->normalizer);
    }

    public function testNormalizerImplementsGeneratedNormalizerInterface(): void
    {
        $this->assertInstanceOf(
            \RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\GeneratedNormalizerInterface::class,
            $this->normalizer,
        );
    }

    public function testSupportsNormalizationReturnsTrueForMaxDepthBlog(): void
    {
        $author = new Author(1, 'Alice', 'alice@example.com');
        $blog = new MaxDepthBlog('Blog', $author);

        $this->assertTrue($this->normalizer->supportsNormalization($blog));
    }

    public function testSupportsNormalizationReturnsFalseForOtherObject(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testGetSupportedTypesIncludesMaxDepthBlog(): void
    {
        $types = $this->normalizer->getSupportedTypes('json');

        $this->assertIsArray($types);
        $this->assertArrayHasKey(MaxDepthBlog::class, $types);
        $this->assertTrue($types[MaxDepthBlog::class]);
    }
}
