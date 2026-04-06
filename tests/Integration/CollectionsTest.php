<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Integration;

use Buildable\SerializerBundle\Tests\AbstractTestCase;
use Buildable\SerializerBundle\Tests\Fixtures\Model\Author;
use Buildable\SerializerBundle\Tests\Fixtures\Model\BlogWithCollections;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Integration tests for collection normalization in generated normalizers.
 *
 * Generates a normalizer for BlogWithCollections, wires up a mock delegate
 * for Author objects, and verifies that:
 *   - Scalar collections (string[]) pass through as-is.
 *   - Typed object collections (Author[]) are delegated per-item.
 */
final class CollectionsTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated BlogWithCollectionsNormalizer */
    private string $normalizerFqcn;

    /** @var object The instantiated generated normalizer */
    private object $normalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $generator = $this->makeGenerator($this->tempDir);
        $factory = $generator->getMetadataFactory();
        $metadata = $factory->getMetadataFor(BlogWithCollections::class);

        $this->normalizerFqcn = $generator->resolveNormalizerFqcn($metadata);

        if (!class_exists($this->normalizerFqcn, false)) {
            $filePath = $generator->generateAndWrite($metadata);
            require_once $filePath;
        }

        $this->normalizer = new $this->normalizerFqcn();

        // Wire up a mock NormalizerInterface that normalizes Author objects
        // by returning a predictable array representation.
        // Since collections are now delegated as a whole array to the normalizer,
        // the mock must also handle arrays by recursively normalizing each element.
        $mockNormalizer = $this->createMock(NormalizerInterface::class);
        $mockNormalizer
            ->method("normalize")
            ->willReturnCallback(static function (mixed $data) use (
                &$mockNormalizer,
            ): mixed {
                if ($data instanceof Author) {
                    return [
                        "id" => $data->getId(),
                        "name" => $data->getName(),
                        "email" => $data->getEmail(),
                    ];
                }

                // When the generated normalizer delegates an entire array collection,
                // simulate Symfony Serializer's behaviour: normalize each element.
                if (is_array($data)) {
                    $result = [];
                    foreach ($data as $key => $item) {
                        $result[$key] = $mockNormalizer->normalize($item);
                    }
                    return $result;
                }

                return $data;
            });

        $this->normalizer->setNormalizer($mockNormalizer);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Implements NormalizerAwareInterface (needs delegate for Author[])
    // -------------------------------------------------------------------------

    public function testNormalizerImplementsNormalizerAwareInterface(): void
    {
        $this->assertInstanceOf(
            \Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface::class,
            $this->normalizer,
        );
    }

    public function testNormalizerImplementsGeneratedNormalizerInterface(): void
    {
        $this->assertInstanceOf(
            \Buildable\SerializerBundle\Normalizer\GeneratedNormalizerInterface::class,
            $this->normalizer,
        );
    }

    // -------------------------------------------------------------------------
    // Scalar tags collection (string[]) passes through as-is
    // -------------------------------------------------------------------------

    public function testNormalizeScalarTagsPassThroughAsIs(): void
    {
        $blog = new BlogWithCollections(1, "My Blog", [
            "php",
            "symfony",
            "testing",
        ]);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey("tags", $result);
        $this->assertSame(["php", "symfony", "testing"], $result["tags"]);
    }

    public function testNormalizeEmptyTagsCollection(): void
    {
        $blog = new BlogWithCollections(1, "Blog", []);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertArrayHasKey("tags", $result);
        $this->assertSame([], $result["tags"]);
    }

    public function testNormalizeSingleTagInCollection(): void
    {
        $blog = new BlogWithCollections(1, "Blog", ["only-tag"]);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertSame(["only-tag"], $result["tags"]);
    }

    public function testNormalizeTagsPreservesOrder(): void
    {
        $tags = ["z-tag", "a-tag", "m-tag"];
        $blog = new BlogWithCollections(1, "Blog", $tags);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertSame($tags, $result["tags"]);
    }

    // -------------------------------------------------------------------------
    // Typed Author[] collection — each item delegated to normalizer
    // -------------------------------------------------------------------------

    public function testNormalizeTypedAuthorCollectionDelegatesEachItem(): void
    {
        $authors = [
            new Author(1, "Alice", "alice@example.com"),
            new Author(2, "Bob", "bob@example.com"),
        ];
        $blog = new BlogWithCollections(1, "Blog", [], $authors);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey("authors", $result);
        $this->assertCount(2, $result["authors"]);
    }

    public function testNormalizeFirstAuthorHasCorrectData(): void
    {
        $authors = [new Author(10, "Alice", "alice@test.com")];
        $blog = new BlogWithCollections(1, "Blog", [], $authors);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertSame(10, $result["authors"][0]["id"]);
        $this->assertSame("Alice", $result["authors"][0]["name"]);
        $this->assertSame("alice@test.com", $result["authors"][0]["email"]);
    }

    public function testNormalizeMultipleAuthorsPreservesOrder(): void
    {
        $authors = [
            new Author(1, "First", "first@test.com"),
            new Author(2, "Second", "second@test.com"),
            new Author(3, "Third", "third@test.com"),
        ];
        $blog = new BlogWithCollections(1, "Blog", [], $authors);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertSame(1, $result["authors"][0]["id"]);
        $this->assertSame(2, $result["authors"][1]["id"]);
        $this->assertSame(3, $result["authors"][2]["id"]);
    }

    public function testNormalizeEmptyAuthorsCollection(): void
    {
        $blog = new BlogWithCollections(1, "Blog", [], []);
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertArrayHasKey("authors", $result);
        $this->assertSame([], $result["authors"]);
    }

    // -------------------------------------------------------------------------
    // Both tags and authors together
    // -------------------------------------------------------------------------

    public function testNormalizeBothCollectionsPresent(): void
    {
        $blog = new BlogWithCollections(
            id: 5,
            title: "Full Blog",
            tags: ["tag1", "tag2"],
            authors: [new Author(7, "Writer", "writer@test.com")],
        );

        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertSame(5, $result["id"]);
        $this->assertSame("Full Blog", $result["title"]);
        $this->assertSame(["tag1", "tag2"], $result["tags"]);
        $this->assertCount(1, $result["authors"]);
        $this->assertSame(7, $result["authors"][0]["id"]);
    }

    // -------------------------------------------------------------------------
    // Scalar properties are also present
    // -------------------------------------------------------------------------

    public function testNormalizeScalarPropertiesIncluded(): void
    {
        $blog = new BlogWithCollections(42, "Test Title");
        $result = $this->normalizer->normalize($blog, "json", []);

        $this->assertSame(42, $result["id"]);
        $this->assertSame("Test Title", $result["title"]);
    }

    // -------------------------------------------------------------------------
    // supportsNormalization
    // -------------------------------------------------------------------------

    public function testSupportsNormalizationReturnsTrueForBlogWithCollections(): void
    {
        $blog = new BlogWithCollections(1, "Blog");

        $this->assertTrue($this->normalizer->supportsNormalization($blog));
    }

    public function testSupportsNormalizationReturnsFalseForOtherObject(): void
    {
        $this->assertFalse(
            $this->normalizer->supportsNormalization(new \stdClass()),
        );
    }

    // -------------------------------------------------------------------------
    // getSupportedTypes
    // -------------------------------------------------------------------------

    public function testGetSupportedTypesIncludesBlogWithCollections(): void
    {
        $types = $this->normalizer->getSupportedTypes("json");

        $this->assertArrayHasKey(BlogWithCollections::class, $types);
        $this->assertTrue($types[BlogWithCollections::class]);
    }
}
