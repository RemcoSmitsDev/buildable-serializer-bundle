<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\Metadata;

use Buildable\SerializerBundle\Metadata\AccessorType;
use Buildable\SerializerBundle\Metadata\MetadataFactory;
use Buildable\SerializerBundle\Tests\Fixtures\Model\Author;
use Buildable\SerializerBundle\Tests\Fixtures\Model\BlogWithAuthor;
use Buildable\SerializerBundle\Tests\Fixtures\Model\BlogWithGroups;
use Buildable\SerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * @covers \Buildable\SerializerBundle\Metadata\MetadataFactory
 */
final class MetadataFactoryTest extends TestCase
{
    private MetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = $this->makeFactory();
    }

    // -------------------------------------------------------------------------
    // Factory helper
    // -------------------------------------------------------------------------

    private function makeFactory(): MetadataFactory
    {
        $phpDoc     = new PhpDocExtractor();
        $reflection = new ReflectionExtractor();
        $extractor  = new PropertyInfoExtractor(
            listExtractors: [$reflection],
            typeExtractors: [$phpDoc, $reflection],
            accessExtractors: [$reflection],
        );

        return new MetadataFactory($extractor);
    }

    // -------------------------------------------------------------------------
    // hasMetadataFor()
    // -------------------------------------------------------------------------

    public function testHasMetadataForReturnsFalseForUnknownClass(): void
    {
        $this->assertFalse($this->factory->hasMetadataFor('NonExistent\Class\That\DoesNotExist'));
    }

    public function testHasMetadataForReturnsTrueForKnownClass(): void
    {
        $this->assertTrue($this->factory->hasMetadataFor(SimpleBlog::class));
    }

    public function testHasMetadataForReturnsTrueForStdClass(): void
    {
        $this->assertTrue($this->factory->hasMetadataFor(\stdClass::class));
    }

    // -------------------------------------------------------------------------
    // getMetadataFor() — basic structure
    // -------------------------------------------------------------------------

    public function testGetMetadataForSimpleBlog(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame(SimpleBlog::class, $metadata->className);
        // SimpleBlog exposes id, title, content, excerpt via getters
        $this->assertGreaterThanOrEqual(3, count($metadata->properties));
    }

    public function testGetMetadataForSimpleBlogHasExpectedProperties(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $names = array_map(static fn($p) => $p->name, $metadata->properties);
        $this->assertContains('id', $names);
        $this->assertContains('title', $names);
        $this->assertContains('content', $names);
    }

    public function testGetMetadataForSimpleBlogNoIgnoredProperties(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        foreach ($metadata->properties as $property) {
            $this->assertFalse($property->ignored, "Property '{$property->name}' should not be ignored.");
        }
    }

    // -------------------------------------------------------------------------
    // Accessor type for SimpleBlog (private promoted params → getters)
    // -------------------------------------------------------------------------

    public function testSimpleBlogPropertiesUseMethodAccessor(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        foreach ($metadata->properties as $property) {
            $this->assertSame(
                AccessorType::METHOD,
                $property->accessorType,
                "Property '{$property->name}' should use METHOD accessor.",
            );
        }
    }

    public function testSimpleBlogGetterNamesResolvedCorrectly(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp, 'Property "id" should be discoverable.');
        $this->assertSame('getId', $idProp->accessor);
    }

    public function testSimpleBlogTitleGetterName(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $titleProp = $metadata->getProperty('title');
        $this->assertNotNull($titleProp);
        $this->assertSame('getTitle', $titleProp->accessor);
    }

    // -------------------------------------------------------------------------
    // Groups — BlogWithGroups
    // -------------------------------------------------------------------------

    public function testGetMetadataForBlogWithGroups(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp, 'Property "id" should exist in BlogWithGroups.');
        $this->assertContains('blog:read', $idProp->groups);
        $this->assertContains('blog:list', $idProp->groups);
    }

    public function testBlogWithGroupsHasGroupConstraints(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $this->assertTrue($metadata->hasGroupConstraints());
    }

    public function testIgnoredPropertyExcludedFromBlogWithGroups(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $names = array_map(static fn($p) => $p->name, $metadata->properties);
        $this->assertNotContains('internalField', $names, '"internalField" carries #[Ignore] and must be excluded.');
    }

    public function testSerializedNameParsedFromBlogWithGroups(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $authorName = $metadata->getProperty('authorName');
        $this->assertNotNull($authorName, 'Property "authorName" should exist.');
        $this->assertSame('author_name', $authorName->serializedName);
    }

    public function testBlogWithGroupsContentOnlyInReadGroup(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $contentProp = $metadata->getProperty('content');
        $this->assertNotNull($contentProp);
        $this->assertSame(['blog:read'], $contentProp->groups);
    }

    // -------------------------------------------------------------------------
    // Nested objects — BlogWithAuthor
    // -------------------------------------------------------------------------

    public function testGetMetadataForBlogWithAuthor(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithAuthor::class);

        $authorProp = $metadata->getProperty('author');
        $this->assertNotNull($authorProp, 'Property "author" should be discoverable.');
        $this->assertTrue($authorProp->isNested, '"author" should be marked as nested.');
    }

    public function testBlogWithAuthorHasNestedObjects(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithAuthor::class);

        $this->assertTrue($metadata->hasNestedObjects());
    }

    public function testBlogWithAuthorAuthorTypeIsAuthorClass(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithAuthor::class);

        $authorProp = $metadata->getProperty('author');
        $this->assertNotNull($authorProp);
        $this->assertSame(Author::class, $authorProp->type);
    }

    // -------------------------------------------------------------------------
    // Nullable detection — SimpleBlog::excerpt
    // -------------------------------------------------------------------------

    public function testNullablePropertyDetected(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $excerptProp = $metadata->getProperty('excerpt');
        $this->assertNotNull($excerptProp, 'Property "excerpt" should be discoverable.');
        $this->assertTrue($excerptProp->nullable, '"excerpt" is nullable and should be detected.');
    }

    public function testNonNullablePropertyNotMarkedNullable(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp);
        $this->assertFalse($idProp->nullable, '"id" is not nullable.');
    }

    // -------------------------------------------------------------------------
    // In-memory caching
    // -------------------------------------------------------------------------

    public function testGetMetadataIsCached(): void
    {
        $first  = $this->factory->getMetadataFor(SimpleBlog::class);
        $second = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame($first, $second, 'getMetadataFor() must return the same object on repeated calls.');
    }

    public function testCachingDoesNotAffectDifferentClasses(): void
    {
        $simpleBlog  = $this->factory->getMetadataFor(SimpleBlog::class);
        $blogWithGroups = $this->factory->getMetadataFor(BlogWithGroups::class);

        $this->assertNotSame($simpleBlog, $blogWithGroups);
        $this->assertSame(SimpleBlog::class, $simpleBlog->className);
        $this->assertSame(BlogWithGroups::class, $blogWithGroups->className);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testThrowsOnNonExistentClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->getMetadataFor('App\NonExistent\Totally\Missing\Class');
    }

    public function testThrowsMessageContainsClassName(): void
    {
        $className = 'App\NonExistent\Ghost';

        try {
            $this->factory->getMetadataFor($className);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($className, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // reflectionClass reference
    // -------------------------------------------------------------------------

    public function testMetadataReflectionClassMatchesTargetClass(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame(SimpleBlog::class, $metadata->reflectionClass->getName());
    }

    public function testGetShortNameFromMetadata(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame('SimpleBlog', $metadata->getShortName());
    }
}
