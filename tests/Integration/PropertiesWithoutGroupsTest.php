<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithMixedGroups;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Integration tests for properties without groups being properly excluded
 * when serialization groups are specified in context.
 *
 * This tests the fix for the issue where properties without #[Groups] attributes
 * were incorrectly included in the output even when groups were specified.
 *
 * Expected behavior (matching Symfony's native serializer):
 * - Properties WITH groups: serialize when no groups specified OR when one of their groups matches
 * - Properties WITHOUT groups: serialize ONLY when no groups are specified in context
 * - Ignored properties: never serialize
 */
final class PropertiesWithoutGroupsTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated normalizer */
    private string $normalizerFqcn;

    /** @var object The instantiated generated normalizer */
    private object $normalizer;

    private BlogWithMixedGroups $blog;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $writer = $this->makeWriter($this->tempDir);
        $pathResolver = $this->makePathResolver($this->tempDir);
        $generator = $this->makeGenerator();
        $factory = $generator->getMetadataFactory();
        $metadata = $factory->getMetadataFor(BlogWithMixedGroups::class);

        $this->normalizerFqcn = $pathResolver->resolveNormalizerFqcn($metadata);

        if (!class_exists($this->normalizerFqcn, false)) {
            $filePath = $writer->write($metadata);
            require_once $filePath;
        }

        $this->normalizer = new $this->normalizerFqcn();
        $this->blog = new BlogWithMixedGroups(1, 'Test Title', 'Test Content');
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testNoGroupsContextIncludesPropertiesWithGroups(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', []);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testNoGroupsContextIncludesPropertiesWithoutGroups(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', []);

        $this->assertArrayHasKey('internalNote', $result);
        $this->assertArrayHasKey('debug_info', $result);
    }

    public function testNoGroupsContextExcludesIgnoredProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', []);

        $this->assertArrayNotHasKey('secret', $result);
    }

    public function testEmptyGroupsArrayIncludesPropertiesWithGroups(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => [],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testEmptyGroupsArrayIncludesPropertiesWithoutGroups(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => [],
        ]);

        $this->assertArrayHasKey('internalNote', $result);
        $this->assertArrayHasKey('debug_info', $result);
    }

    public function testSpecificGroupExcludesPropertiesWithoutGroups(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read'],
        ]);

        $this->assertArrayNotHasKey('internalNote', $result);
        $this->assertArrayNotHasKey('debug_info', $result);
    }

    public function testBlogListGroupExcludesPropertiesWithoutGroups(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertArrayNotHasKey('internalNote', $result);
        $this->assertArrayNotHasKey('debug_info', $result);
    }

    public function testBlogReadGroupIncludesMatchingProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read'],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testBlogListGroupIncludesOnlyListProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        // content only has 'blog:read', not 'blog:list'
        $this->assertArrayNotHasKey('content', $result);
    }

    public function testSpecificGroupStillExcludesIgnoredProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read'],
        ]);

        $this->assertArrayNotHasKey('secret', $result);
    }

    public function testUnknownGroupExcludesAllNonIgnoredProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
        ]);

        // Properties with groups don't match 'nonexistent:group'
        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('title', $result);
        $this->assertArrayNotHasKey('content', $result);

        // Properties without groups should also be excluded when any group is specified
        $this->assertArrayNotHasKey('internalNote', $result);
        $this->assertArrayNotHasKey('debug_info', $result);

        // Ignored properties never appear
        $this->assertArrayNotHasKey('secret', $result);
    }

    public function testUnknownGroupReturnsEmptyArray(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
        ]);

        $this->assertSame([], $result);
    }

    public function testMultipleGroupsStillExcludesPropertiesWithoutGroups(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read', 'blog:list'],
        ]);

        // Properties without groups should be excluded even with multiple groups specified
        $this->assertArrayNotHasKey('internalNote', $result);
        $this->assertArrayNotHasKey('debug_info', $result);
    }

    public function testMultipleGroupsIncludesUnionOfMatchingProperties(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read', 'blog:list'],
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testNoGroupsContextReturnsCorrectValues(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', []);

        $this->assertSame(1, $result['id']);
        $this->assertSame('Test Title', $result['title']);
        $this->assertSame('Test Content', $result['content']);
        $this->assertSame('internal data', $result['internalNote']);
        $this->assertSame('debug data', $result['debug_info']);
    }

    public function testSpecificGroupReturnsCorrectValues(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        $this->assertSame(1, $result['id']);
        $this->assertSame('Test Title', $result['title']);
    }

    /**
     * This test reproduces the exact issue reported: when serializing with groups,
     * properties without groups were incorrectly included in the output.
     *
     * Before the fix, specifying groups would still output properties without groups.
     * After the fix, only properties matching the specified groups are output.
     */
    public function testRegressionPropertiesWithoutGroupsExcludedWhenGroupsSpecified(): void
    {
        $result = $this->normalizer->normalize($this->blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:list'],
        ]);

        // Only id and title have 'blog:list' group
        $this->assertCount(2, $result, 'Result should only contain properties matching the blog:list group');
        $this->assertSame(['id', 'title'], array_keys($result));
    }

    public function testSupportsNormalizationReturnsTrueForBlogWithMixedGroups(): void
    {
        $this->assertTrue($this->normalizer->supportsNormalization($this->blog));
    }

    public function testSupportsNormalizationReturnsFalseForOtherObject(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testGetSupportedTypesIncludesBlogWithMixedGroups(): void
    {
        $types = $this->normalizer->getSupportedTypes('json');

        $this->assertArrayHasKey(BlogWithMixedGroups::class, $types);
        $this->assertTrue($types[BlogWithMixedGroups::class]);
    }
}
