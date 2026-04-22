<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Full pipeline integration tests: generate a normalizer for SimpleBlog,
 * require the file, instantiate the class, and run normalization.
 */
final class GeneratedNormalizerTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated SimpleBlogNormalizer class */
    private string $normalizerFqcn;

    /** @var NormalizerInterface The instantiated generated normalizer */
    private NormalizerInterface $normalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $writer = $this->makeWriter($this->tempDir);
        $pathResolver = $this->makePathResolver($this->tempDir);
        $generator = $this->makeGenerator();
        $factory = $generator->getMetadataFactory();
        $metadata = $factory->getMetadataFor(SimpleBlog::class);

        $this->normalizerFqcn = $pathResolver->resolveNormalizerFqcn($metadata);

        if (!class_exists($this->normalizerFqcn, false)) {
            $filePath = $writer->write($metadata);
            require_once $filePath;
        }

        $this->normalizer = new $this->normalizerFqcn();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testNormalizeSimpleObject(): void
    {
        $blog = new SimpleBlog(1, 'Test Title', 'Test Content');
        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Test Title', $result['title']);
        $this->assertSame('Test Content', $result['content']);
    }

    public function testNormalizeIncludesNullableFieldWhenNull(): void
    {
        // excerpt is null (default), skipNullValues is false by default
        $blog = new SimpleBlog(1, 'Title', 'Body');
        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertArrayHasKey('excerpt', $result);
        $this->assertNull($result['excerpt']);
    }

    public function testNormalizeIncludesNullableFieldWhenSet(): void
    {
        $blog = new SimpleBlog(1, 'Title', 'Body', 'Short excerpt');
        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertSame('Short excerpt', $result['excerpt']);
    }

    public function testNormalizeOmitsNullFieldWhenSkipNullValuesEnabled(): void
    {
        $blog = new SimpleBlog(1, 'Title', 'Body');
        $context = [\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true];
        $result = $this->normalizer->normalize($blog, 'json', $context);

        $this->assertArrayNotHasKey('excerpt', $result);
    }

    public function testNormalizeReturnsArray(): void
    {
        $blog = new SimpleBlog(42, 'A', 'B');
        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertIsArray($result);
    }

    public function testNormalizeWithDifferentFormatReturnsArray(): void
    {
        $blog = new SimpleBlog(1, 'Title', 'Content');
        $result = $this->normalizer->normalize($blog, 'xml', []);

        $this->assertIsArray($result);
    }

    public function testNormalizeWithNullFormatReturnsArray(): void
    {
        $blog = new SimpleBlog(1, 'Title', 'Content');
        $result = $this->normalizer->normalize($blog, null, []);

        $this->assertIsArray($result);
    }

    public function testNormalizeIdIsCorrectType(): void
    {
        $blog = new SimpleBlog(99, 'Title', 'Content');
        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertSame(99, $result['id']);
        $this->assertIsInt($result['id']);
    }

    public function testSupportsNormalizationReturnsTrueForCorrectClass(): void
    {
        $blog = new SimpleBlog(1, 'Title', 'Content');
        $result = $this->normalizer->supportsNormalization($blog);

        $this->assertTrue($result);
    }

    public function testSupportsNormalizationReturnsFalseForOtherClass(): void
    {
        $result = $this->normalizer->supportsNormalization(new \stdClass());

        $this->assertFalse($result);
    }

    public function testSupportsNormalizationReturnsFalseForNull(): void
    {
        $result = $this->normalizer->supportsNormalization(null);

        $this->assertFalse($result);
    }

    public function testSupportsNormalizationReturnsFalseForScalar(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization('string'));
        $this->assertFalse($this->normalizer->supportsNormalization(42));
        $this->assertFalse($this->normalizer->supportsNormalization([]));
    }

    public function testGetSupportedTypesReturnsMappingForClass(): void
    {
        $result = $this->normalizer->getSupportedTypes('json');

        $this->assertIsArray($result);
        $this->assertArrayHasKey(SimpleBlog::class, $result);
        $this->assertTrue($result[SimpleBlog::class]);
    }

    public function testGetSupportedTypesReturnsConsistentResultForDifferentFormats(): void
    {
        $jsonResult = $this->normalizer->getSupportedTypes('json');
        $xmlResult = $this->normalizer->getSupportedTypes('xml');
        $nullResult = $this->normalizer->getSupportedTypes(null);

        $this->assertArrayHasKey(SimpleBlog::class, $jsonResult);
        $this->assertArrayHasKey(SimpleBlog::class, $xmlResult);
        $this->assertArrayHasKey(SimpleBlog::class, $nullResult);
    }

    public function testNormalizerImplementsGeneratedNormalizerInterface(): void
    {
        $this->assertInstanceOf(
            \RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\GeneratedNormalizerInterface::class,
            $this->normalizer,
        );
    }

    public function testNormalizerImplementsNormalizerInterface(): void
    {
        $this->assertInstanceOf(NormalizerInterface::class, $this->normalizer);
    }
}
