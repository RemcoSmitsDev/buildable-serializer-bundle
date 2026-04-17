<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithMixedGroups;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Integration tests for the {@see AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS}
 * context key in generated normalizers.
 *
 * When the context key is set to true and the resulting data would otherwise
 * be an empty array (which serializes to "[]" in JSON), the generated
 * normalizer must instead return an {@see \ArrayObject} so it is serialized
 * as an empty JSON object ("{}").
 */
final class PreserveEmptyObjectsTest extends AbstractTestCase
{
    private string $tempDir;

    /** @var string FQCN of the generated BlogWithMixedGroupsNormalizer */
    private string $mixedNormalizerFqcn;

    /** @var object The instantiated generated normalizer for BlogWithMixedGroups */
    private object $mixedNormalizer;

    /** @var string FQCN of the generated SimpleBlogNormalizer */
    private string $simpleNormalizerFqcn;

    /** @var object The instantiated generated normalizer for SimpleBlog */
    private object $simpleNormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $writer = $this->makeWriter($this->tempDir);
        $pathResolver = $this->makePathResolver($this->tempDir);
        $generator = $this->makeGenerator();
        $factory = $generator->getMetadataFactory();

        // BlogWithMixedGroups - can produce an empty $data at runtime when
        // a non-matching group is specified.
        $mixedMetadata = $factory->getMetadataFor(BlogWithMixedGroups::class);
        $this->mixedNormalizerFqcn = $pathResolver->resolveNormalizerFqcn($mixedMetadata);

        if (!class_exists($this->mixedNormalizerFqcn, false)) {
            $filePath = $writer->write($mixedMetadata);
            require_once $filePath;
        }

        $this->mixedNormalizer = new $this->mixedNormalizerFqcn();

        // SimpleBlog - always produces a non-empty $data, used to verify that
        // preserve_empty_objects does NOT affect non-empty results.
        $simpleMetadata = $factory->getMetadataFor(SimpleBlog::class);
        $this->simpleNormalizerFqcn = $pathResolver->resolveNormalizerFqcn($simpleMetadata);

        if (!class_exists($this->simpleNormalizerFqcn, false)) {
            $filePath = $writer->write($simpleMetadata);
            require_once $filePath;
        }

        $this->simpleNormalizer = new $this->simpleNormalizerFqcn();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testEmptyDataReturnsArrayObjectWhenPreserveEmptyObjectsEnabled(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        ]);

        $this->assertInstanceOf(\ArrayObject::class, $result);
    }

    public function testEmptyDataReturnsEmptyArrayObjectWhenPreserveEmptyObjectsEnabled(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        ]);

        $this->assertInstanceOf(\ArrayObject::class, $result);
        $this->assertCount(0, $result);
    }

    public function testEmptyDataWithPreserveEmptyObjectsEncodesAsEmptyJsonObject(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        ]);

        // json_encode on ArrayObject produces "{}" (empty object),
        // while json_encode on [] produces "[]" (empty array).
        $this->assertSame('{}', json_encode($result));
    }

    public function testEmptyDataReturnsArrayWhenPreserveEmptyObjectsDisabled(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => false,
        ]);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function testEmptyDataReturnsArrayWhenPreserveEmptyObjectsNotSet(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function testEmptyDataWithoutPreserveEmptyObjectsEncodesAsEmptyJsonArray(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
        ]);

        $this->assertSame('[]', json_encode($result));
    }

    public function testNonEmptyDataIsReturnedAsArrayEvenWhenPreserveEmptyObjectsEnabled(): void
    {
        $blog = new SimpleBlog(1, 'Title', 'Content', 'Excerpt');

        $result = $this->simpleNormalizer->normalize($blog, 'json', [
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        ]);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Title', $result['title']);
        $this->assertSame('Content', $result['content']);
        $this->assertSame('Excerpt', $result['excerpt']);
    }

    public function testNonEmptyDataWithMixedGroupsIsReturnedAsArrayEvenWhenPreserveEmptyObjectsEnabled(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['blog:read'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testPreserveEmptyObjectsAcceptsTruthyValue(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => 1,
        ]);

        $this->assertInstanceOf(\ArrayObject::class, $result);
    }

    public function testPreserveEmptyObjectsAcceptsFalsyZeroValue(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => 0,
        ]);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function testPreserveEmptyObjectsAcceptsFalsyNullValue(): void
    {
        $blog = new BlogWithMixedGroups(1, 'Title', 'Content');

        $result = $this->mixedNormalizer->normalize($blog, 'json', [
            AbstractNormalizer::GROUPS => ['nonexistent:group'],
            AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => null,
        ]);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }
}
