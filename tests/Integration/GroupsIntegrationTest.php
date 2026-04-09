<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithGroups;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Integration tests for group-based filtering in generated normalizers.
 *
 * Generates a normalizer for BlogWithGroups, requires the file, and verifies
 * that the GROUPS context key correctly filters which properties are included.
 */
final class GroupsIntegrationTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated BlogWithGroupsNormalizer */
    private string $normalizerFqcn;

    /** @var object The instantiated generated normalizer */
    private object $normalizer;

    private BlogWithGroups $blog;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $generator = $this->makeGenerator($this->tempDir);
        $factory = $generator->getMetadataFactory();
        $metadata = $factory->getMetadataFor(BlogWithGroups::class);

        $this->normalizerFqcn = $generator->resolveNormalizerFqcn($metadata);

        if (!class_exists($this->normalizerFqcn, false)) {
            $filePath = $generator->generateAndWrite($metadata);
            require_once $filePath;
        }

        $this->normalizer = new $this->normalizerFqcn();
        $this->blog = new BlogWithGroups(1, 'Test Title', 'Test Content');
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testNormalizeWithNoGroupsContextIncludesAllNonIgnoredProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('author_name', $result);
    }

    public function testNormalizeWithNoGroupsContextNeverIncludesIgnoredField(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', []);

        $this->assertArrayNotHasKey('internalField', $result);
    }

    public function testNormalizeWithEmptyGroupsArrayIncludesAllNonIgnoredProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => [],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('author_name', $result);
    }

    public function testNormalizeWithBlogListGroupIncludesIdAndTitle(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
    }

    public function testNormalizeWithBlogListGroupExcludesContent(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertArrayNotHasKey('content', $result);
    }

    public function testNormalizeWithBlogListGroupExcludesAuthorName(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertArrayNotHasKey('author_name', $result);
    }

    public function testNormalizeWithBlogListGroupNeverIncludesIgnoredField(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertArrayNotHasKey('internalField', $result);
    }

    public function testNormalizeWithBlogListGroupReturnsCorrectValues(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertSame(1, $result['id']);
        $this->assertSame('Test Title', $result['title']);
    }

    public function testNormalizeWithBlogReadGroupIncludesAllReadProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read'],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('author_name', $result);
    }

    public function testNormalizeWithBlogReadGroupNeverIncludesIgnoredField(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read'],
        ]);

        $this->assertArrayNotHasKey('internalField', $result);
    }

    public function testNormalizeWithBlogReadGroupReturnsCorrectValues(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read'],
        ]);

        $this->assertSame(1, $result['id']);
        $this->assertSame('Test Title', $result['title']);
        $this->assertSame('Test Content', $result['content']);
        $this->assertSame('Test Author', $result['author_name']);
    }

    public function testNormalizeUsesSerializedNameForAuthorName(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', []);

        // The property 'authorName' must be serialized as 'author_name'
        $this->assertArrayHasKey('author_name', $result);
        $this->assertArrayNotHasKey('authorName', $result);
    }

    public function testNormalizeWithUnknownGroupReturnsEmptyOrMinimalResult(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
        ]);

        // No property belongs to 'nonexistent:group'
        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertArrayNotHasKey('author_name', $result);
        $this->assertArrayNotHasKey('internalField', $result);
    }

    public function testNormalizeWithMultipleGroupsIncludesUnionOfProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list', 'blog:read'],
        ]);

        // All groups combined should include all non-ignored properties
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('author_name', $result);
    }

    public function testSupportsNormalizationReturnsTrueForBlogWithGroups(): void
    {
        $this->assertTrue($this->normalizer->supportsNormalization($this->blog));
    }

    public function testSupportsNormalizationReturnsFalseForOtherObject(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testGetSupportedTypesIncludesBlogWithGroups(): void
    {
        $types = $this->normalizer->getSupportedTypes('json');

        $this->assertArrayHasKey(BlogWithGroups::class, $types);
        $this->assertTrue($types[BlogWithGroups::class]);
    }
}
