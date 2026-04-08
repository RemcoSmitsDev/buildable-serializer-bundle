<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Integration;

use BuildableSerializerBundle\Tests\AbstractTestCase;
use BuildableSerializerBundle\Tests\Fixtures\Model\Author;
use BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithAuthor;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Integration tests for nested object normalization via NormalizerAwareTrait delegate.
 *
 * Generates a normalizer for BlogWithAuthor, wires up a mock delegate that
 * handles Author normalization, and verifies that nested objects are correctly
 * delegated and the result assembled.
 */
final class NestedObjectsTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated BlogWithAuthorNormalizer */
    private string $normalizerFqcn;

    /** @var object The instantiated generated normalizer */
    private object $normalizer;

    /** Normalised representation returned by the mock for any Author instance */
    private const AUTHOR_DATA = ['id' => 99, 'name' => 'John', 'email' => 'j@test.com'];

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $generator = $this->makeGenerator($this->tempDir);
        $factory = $generator->getMetadataFactory();
        $metadata = $factory->getMetadataFor(BlogWithAuthor::class);

        $this->normalizerFqcn = $generator->resolveNormalizerFqcn($metadata);

        if (!class_exists($this->normalizerFqcn, false)) {
            $filePath = $generator->generateAndWrite($metadata);
            require_once $filePath;
        }

        $this->normalizer = new $this->normalizerFqcn();

        // Wire up a mock NormalizerInterface that returns AUTHOR_DATA for Author objects.
        $mockNormalizer = $this->createMock(NormalizerInterface::class);
        $mockNormalizer
            ->method('normalize')
            ->willReturnCallback(static function (mixed $data): mixed {
                if ($data instanceof Author) {
                    return self::AUTHOR_DATA;
                }

                return null;
            });

        $this->normalizer->setNormalizer($mockNormalizer);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Implements NormalizerAwareInterface
    // -------------------------------------------------------------------------

    public function testNormalizerImplementsNormalizerAwareInterface(): void
    {
        $this->assertInstanceOf(
            \Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface::class,
            $this->normalizer,
        );
    }

    public function testNormalizerImplementsNormalizerInterface(): void
    {
        $this->assertInstanceOf(NormalizerInterface::class, $this->normalizer);
    }

    // -------------------------------------------------------------------------
    // Nested author is delegated and result assembled
    // -------------------------------------------------------------------------

    public function testNormalizeAuthorIsDelegatedToMockNormalizer(): void
    {
        $author = new Author(99, 'John', 'j@test.com');
        $blog = new BlogWithAuthor(1, 'Test Blog', $author);

        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('author', $result);
        $this->assertSame(self::AUTHOR_DATA, $result['author']);
    }

    public function testNormalizeScalarPropertiesAreIncluded(): void
    {
        $author = new Author(1, 'Jane', 'jane@example.com');
        $blog = new BlogWithAuthor(42, 'My Blog', $author);

        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertSame(42, $result['id']);
        $this->assertSame('My Blog', $result['title']);
    }

    public function testNormalizeAuthorDataMatchesMockReturn(): void
    {
        $author = new Author(99, 'John', 'j@test.com');
        $blog = new BlogWithAuthor(1, 'Blog', $author);

        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertSame(99, $result['author']['id']);
        $this->assertSame('John', $result['author']['name']);
        $this->assertSame('j@test.com', $result['author']['email']);
    }

    // -------------------------------------------------------------------------
    // Nullable co-author
    // -------------------------------------------------------------------------

    public function testNormalizeNullCoAuthorIsIncludedAsNullWhenSkipNullValuesFalse(): void
    {
        $author = new Author(1, 'Main Author', 'main@example.com');
        $blog = new BlogWithAuthor(1, 'Blog', $author); // coAuthor = null

        $result = $this->normalizer->normalize($blog, 'json', []);

        // With skipNullValues = false (default), null coAuthor must appear as null
        $this->assertArrayHasKey('coAuthor', $result);
        $this->assertNull($result['coAuthor']);
    }

    public function testNormalizeNullCoAuthorIsOmittedWhenSkipNullValuesTrue(): void
    {
        $author = new Author(1, 'Main Author', 'main@example.com');
        $blog = new BlogWithAuthor(1, 'Blog', $author);
        $context = [\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true];

        $result = $this->normalizer->normalize($blog, 'json', $context);

        $this->assertArrayNotHasKey('coAuthor', $result);
    }

    public function testNormalizeNonNullCoAuthorIsDelegated(): void
    {
        $author = new Author(1, 'Main', 'main@test.com');
        $coAuthor = new Author(2, 'Co-Author', 'co@test.com');
        $blog = new BlogWithAuthor(1, 'Blog', $author, $coAuthor);

        $result = $this->normalizer->normalize($blog, 'json', []);

        $this->assertArrayHasKey('coAuthor', $result);
        // The mock normalizer returns AUTHOR_DATA for any Author
        $this->assertSame(self::AUTHOR_DATA, $result['coAuthor']);
    }

    // -------------------------------------------------------------------------
    // supportsNormalization
    // -------------------------------------------------------------------------

    public function testSupportsNormalizationReturnsTrueForBlogWithAuthor(): void
    {
        $author = new Author(1, 'A', 'a@b.com');
        $blog = new BlogWithAuthor(1, 'Blog', $author);

        $this->assertTrue($this->normalizer->supportsNormalization($blog));
    }

    public function testSupportsNormalizationReturnsFalseForOtherObject(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testSupportsNormalizationReturnsFalseForAuthorDirectly(): void
    {
        $author = new Author(1, 'A', 'a@b.com');

        $this->assertFalse($this->normalizer->supportsNormalization($author));
    }

    // -------------------------------------------------------------------------
    // getSupportedTypes
    // -------------------------------------------------------------------------

    public function testGetSupportedTypesIncludesBlogWithAuthor(): void
    {
        $types = $this->normalizer->getSupportedTypes('json');

        $this->assertArrayHasKey(BlogWithAuthor::class, $types);
        $this->assertTrue($types[BlogWithAuthor::class]);
    }

    // -------------------------------------------------------------------------
    // GeneratedNormalizerInterface
    // -------------------------------------------------------------------------

    public function testNormalizerImplementsGeneratedNormalizerInterface(): void
    {
        $this->assertInstanceOf(
            \BuildableSerializerBundle\Normalizer\GeneratedNormalizerInterface::class,
            $this->normalizer,
        );
    }
}
