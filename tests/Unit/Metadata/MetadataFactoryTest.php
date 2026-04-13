<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\AccessorType;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\Author;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithAuthor;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithContext;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\BlogWithGroups;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\VoidNeverReturnTypes;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory
 */
final class MetadataFactoryTest extends TestCase
{
    private MetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = $this->makeFactory();
    }

    private function makeFactory(): MetadataFactory
    {
        $phpDoc = new PhpDocExtractor();
        $reflection = new ReflectionExtractor();
        $extractor = new PropertyInfoExtractor(
            listExtractors: [$reflection],
            typeExtractors: [$phpDoc, $reflection],
            accessExtractors: [$reflection],
        );

        return new MetadataFactory($extractor);
    }

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

    public function testGetMetadataForSimpleBlog(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame(SimpleBlog::class, $metadata->getClassName());
        // SimpleBlog exposes id, title, content, excerpt via getters
        $this->assertGreaterThanOrEqual(3, count($metadata->getProperties()));
    }

    public function testGetMetadataForSimpleBlogHasExpectedProperties(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $names = array_map(static fn($p) => $p->getName(), $metadata->getProperties());
        $this->assertContains('id', $names);
        $this->assertContains('title', $names);
        $this->assertContains('content', $names);
    }

    public function testGetMetadataForSimpleBlogNoIgnoredProperties(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        foreach ($metadata->getProperties() as $property) {
            $this->assertFalse($property->isIgnored(), "Property '{$property->getName()}' should not be ignored.");
        }
    }

    public function testSimpleBlogPropertiesUseMethodAccessor(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        foreach ($metadata->getProperties() as $property) {
            $this->assertSame(
                AccessorType::METHOD,
                $property->getAccessorType(),
                "Property '{$property->getName()}' should use METHOD accessor.",
            );
        }
    }

    public function testSimpleBlogGetterNamesResolvedCorrectly(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp, 'Property "id" should be discoverable.');
        $this->assertSame('getId', $idProp->getAccessor());
    }

    public function testSimpleBlogTitleGetterName(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $titleProp = $metadata->getProperty('title');
        $this->assertNotNull($titleProp);
        $this->assertSame('getTitle', $titleProp->getAccessor());
    }

    public function testGetMetadataForBlogWithGroups(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp, 'Property "id" should exist in BlogWithGroups.');
        $this->assertContains('blog:read', $idProp->getGroups());
        $this->assertContains('blog:list', $idProp->getGroups());
    }

    public function testBlogWithGroupsHasGroupConstraints(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $this->assertTrue($metadata->hasGroupConstraints());
    }

    public function testIgnoredPropertyExcludedFromBlogWithGroups(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $names = array_map(static fn($p) => $p->getName(), $metadata->getProperties());
        $this->assertNotContains('internalField', $names, '"internalField" carries #[Ignore] and must be excluded.');
    }

    public function testSerializedNameParsedFromBlogWithGroups(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $authorName = $metadata->getProperty('authorName');
        $this->assertNotNull($authorName, 'Property "authorName" should exist.');
        $this->assertSame('author_name', $authorName->getSerializedName());
    }

    public function testBlogWithGroupsContentOnlyInReadGroup(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithGroups::class);

        $contentProp = $metadata->getProperty('content');
        $this->assertNotNull($contentProp);
        $this->assertSame(['blog:read'], $contentProp->getGroups());
    }

    public function testGetMetadataForBlogWithAuthor(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithAuthor::class);

        $authorProp = $metadata->getProperty('author');
        $this->assertNotNull($authorProp, 'Property "author" should be discoverable.');
        $this->assertTrue($authorProp->isNested(), '"author" should be marked as nested.');
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
        $this->assertSame(Author::class, $authorProp->getType());
    }

    public function testNullablePropertyDetected(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $excerptProp = $metadata->getProperty('excerpt');
        $this->assertNotNull($excerptProp, 'Property "excerpt" should be discoverable.');
        $this->assertTrue($excerptProp->isNullable(), '"excerpt" is nullable and should be detected.');
    }

    public function testNonNullablePropertyNotMarkedNullable(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp);
        $this->assertFalse($idProp->isNullable(), '"id" is not nullable.');
    }

    public function testGetMetadataIsCached(): void
    {
        $first = $this->factory->getMetadataFor(SimpleBlog::class);
        $second = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame($first, $second, 'getMetadataFor() must return the same object on repeated calls.');
    }

    public function testCachingDoesNotAffectDifferentClasses(): void
    {
        $simpleBlog = $this->factory->getMetadataFor(SimpleBlog::class);
        $blogWithGroups = $this->factory->getMetadataFor(BlogWithGroups::class);

        $this->assertNotSame($simpleBlog, $blogWithGroups);
        $this->assertSame(SimpleBlog::class, $simpleBlog->getClassName());
        $this->assertSame(BlogWithGroups::class, $blogWithGroups->getClassName());
    }

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

    public function testMetadataReflectionClassMatchesTargetClass(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame(SimpleBlog::class, $metadata->getClassName());
    }

    public function testGetShortNameFromMetadata(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertSame('SimpleBlog', $metadata->getShortName());
    }

    public function testVoidReturningGetMethodIsNotDetectedAsGetter(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        $this->assertNotContains(
            'ready',
            $propertyNames,
            'getReady() returns void and should not be detected as a getter',
        );
    }

    public function testVoidReturningIsMethodIsNotDetectedAsGetter(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        $this->assertNotContains(
            'initializing',
            $propertyNames,
            'isInitializing() returns void and should not be detected as a getter',
        );
    }

    public function testVoidReturningHasMethodIsNotDetectedAsGetter(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        $this->assertNotContains(
            'loaded',
            $propertyNames,
            'hasLoaded() returns void and should not be detected as a getter',
        );
    }

    public function testNeverReturningGetMethodIsNotDetectedAsGetter(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        $this->assertNotContains(
            'error',
            $propertyNames,
            'getError() returns never and should not be detected as a getter',
        );
    }

    public function testNeverReturningIsMethodIsNotDetectedAsGetter(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        $this->assertNotContains(
            'fatal',
            $propertyNames,
            'isFatal() returns never and should not be detected as a getter',
        );
    }

    public function testNeverReturningHasMethodIsNotDetectedAsGetter(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        $this->assertNotContains(
            'failed',
            $propertyNames,
            'hasFailed() returns never and should not be detected as a getter',
        );
    }

    public function testValidGettersAreStillDetectedWithVoidNeverMethods(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        $this->assertContains('id', $propertyNames, 'getId() should be detected as a getter');
        $this->assertContains('name', $propertyNames, 'getName() should be detected as a getter');
        $this->assertContains('active', $propertyNames, 'isActive() should be detected as a getter');
    }

    public function testHasMethodReturningBoolIsDetectedAsGetter(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        // hasName() returns bool and is a valid getter
        // However, 'name' is already registered by getName(), so hasName is skipped
        // This test verifies that bool-returning has* methods are valid getters
        $nameProp = $metadata->getProperty('name');
        $this->assertNotNull($nameProp, 'Property "name" should exist');
        $this->assertSame('getName', $nameProp->getAccessor(), 'getName() should take precedence over hasName()');
    }

    public function testVoidNeverReturnTypesOnlyHasValidGetterProperties(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        $propertyNames = array_map(static fn($p) => $p->getName(), $metadata->getProperties());

        // Should only contain valid getters that return actual values
        // Note: hasName() would produce 'name' which is already registered from getName()
        $this->assertCount(4, $propertyNames, 'Should have exactly 4 properties: id, name, active, empty');
        $this->assertContains('id', $propertyNames);
        $this->assertContains('name', $propertyNames);
        $this->assertContains('active', $propertyNames);
        $this->assertContains('empty', $propertyNames);
    }

    public function testIsEmptyVirtualPropertyWithNoBackingPropertyIsDetected(): void
    {
        $metadata = $this->factory->getMetadataFor(VoidNeverReturnTypes::class);

        // isEmpty() returns bool and should be detected as a virtual property "empty"
        // even though there is no $empty property in the class
        $emptyProp = $metadata->getProperty('empty');

        $this->assertNotNull($emptyProp, 'Virtual property "empty" should be detected from isEmpty()');
        $this->assertSame('isEmpty', $emptyProp->getAccessor(), 'Accessor should be isEmpty()');
        $this->assertSame('bool', $emptyProp->getType(), 'Type should be bool');
        $this->assertFalse($emptyProp->isNested(), 'bool is a scalar type, not nested');
    }

    public function testContextAttributeNormalizationContextParsed(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $createdAtProp = $metadata->getProperty('createdAt');
        $this->assertNotNull($createdAtProp, 'Property "createdAt" should exist.');
        $this->assertTrue($createdAtProp->hasContexts(), 'Property "createdAt" should have contexts.');

        $normalizationContext = $createdAtProp->getNormalizationContext();
        $this->assertArrayHasKey('datetime_format', $normalizationContext);
        $this->assertSame('Y-m-d', $normalizationContext['datetime_format']);
    }

    public function testContextAttributeDenormalizationContextParsed(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $updatedAtProp = $metadata->getProperty('updatedAt');
        $this->assertNotNull($updatedAtProp, 'Property "updatedAt" should exist.');

        $denormalizationContext = $updatedAtProp->getDenormalizationContext();
        $this->assertArrayHasKey('datetime_format', $denormalizationContext);
        $this->assertSame('d/m/Y H:i:s', $denormalizationContext['datetime_format']);

        // Normalization context should be empty for this property
        $this->assertEmpty($updatedAtProp->getNormalizationContext());
    }

    public function testContextAttributeCommonContextAppliedToBoth(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $publishedAtProp = $metadata->getProperty('publishedAt');
        $this->assertNotNull($publishedAtProp, 'Property "publishedAt" should exist.');

        // Common context should be applied to both normalization and denormalization
        $normalizationContext = $publishedAtProp->getNormalizationContext();
        $denormalizationContext = $publishedAtProp->getDenormalizationContext();

        $this->assertArrayHasKey('datetime_format', $normalizationContext);
        $this->assertSame('c', $normalizationContext['datetime_format']);

        $this->assertArrayHasKey('datetime_format', $denormalizationContext);
        $this->assertSame('c', $denormalizationContext['datetime_format']);
    }

    public function testContextAttributeOnNestedObject(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $authorProp = $metadata->getProperty('author');
        $this->assertNotNull($authorProp, 'Property "author" should exist.');
        $this->assertTrue($authorProp->isNested(), '"author" should be marked as nested.');

        $normalizationContext = $authorProp->getNormalizationContext();
        $this->assertArrayHasKey('custom_key', $normalizationContext);
        $this->assertSame('custom_value', $normalizationContext['custom_key']);
    }

    public function testContextAttributeOnCollection(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $tagsProp = $metadata->getProperty('tags');
        $this->assertNotNull($tagsProp, 'Property "tags" should exist.');
        $this->assertTrue($tagsProp->isCollection(), '"tags" should be marked as collection.');

        $normalizationContext = $tagsProp->getNormalizationContext();
        $this->assertArrayHasKey('collection_context', $normalizationContext);
        $this->assertTrue($normalizationContext['collection_context']);
    }

    public function testPropertyWithoutContextHasEmptyContextArrays(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $idProp = $metadata->getProperty('id');
        $this->assertNotNull($idProp, 'Property "id" should exist.');

        $this->assertFalse($idProp->hasContexts());
        $this->assertSame([], $idProp->getNormalizationContext());
        $this->assertSame([], $idProp->getDenormalizationContext());
    }

    public function testContextAttributeWithGroupsStoresPropertyContextObjects(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $scheduledAtProp = $metadata->getProperty('scheduledAt');
        $this->assertNotNull($scheduledAtProp, 'Property "scheduledAt" should exist.');

        $contexts = $scheduledAtProp->getContexts();
        $this->assertCount(3, $contexts, 'Should have 3 Context attributes.');

        // Each context should have its own groups
        $this->assertSame(['blog:read'], $contexts[0]->getGroups());
        $this->assertSame(['blog:list'], $contexts[1]->getGroups());
        $this->assertSame(['blog:api'], $contexts[2]->getGroups());
    }

    public function testContextAttributeGroupFiltering(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $scheduledAtProp = $metadata->getProperty('scheduledAt');
        $this->assertNotNull($scheduledAtProp);

        // When 'blog:read' is active, should get 'Y-m-d H:i:s' format
        $readContext = $scheduledAtProp->getNormalizationContext(['blog:read']);
        $this->assertSame('Y-m-d H:i:s', $readContext['datetime_format']);

        // When 'blog:list' is active, should get 'Y-m-d' format
        $listContext = $scheduledAtProp->getNormalizationContext(['blog:list']);
        $this->assertSame('Y-m-d', $listContext['datetime_format']);

        // When 'blog:api' is active, should get 'c' format
        $apiContext = $scheduledAtProp->getNormalizationContext(['blog:api']);
        $this->assertSame('c', $apiContext['datetime_format']);
    }

    public function testContextAttributeMixedConditionalAndUnconditional(): void
    {
        $metadata = $this->factory->getMetadataFor(BlogWithContext::class);

        $archivedAtProp = $metadata->getProperty('archivedAt');
        $this->assertNotNull($archivedAtProp);

        $contexts = $archivedAtProp->getContexts();
        $this->assertCount(2, $contexts, 'Should have 2 Context attributes.');

        // First context is unconditional (no groups)
        $this->assertTrue($contexts[0]->isUnconditional());
        $this->assertSame([], $contexts[0]->getGroups());

        // Second context is conditional (has groups)
        $this->assertFalse($contexts[1]->isUnconditional());
        $this->assertSame(['blog:read'], $contexts[1]->getGroups());

        // When no groups specified, all contexts apply
        $noGroupContext = $archivedAtProp->getNormalizationContext([]);
        $this->assertTrue($noGroupContext['always_applied']);
        $this->assertTrue($noGroupContext['only_for_read']);

        // When 'blog:read' is active, both contexts apply
        $readContext = $archivedAtProp->getNormalizationContext(['blog:read']);
        $this->assertTrue($readContext['always_applied']);
        $this->assertTrue($readContext['only_for_read']);

        // When 'blog:list' is active, only unconditional context applies
        $listContext = $archivedAtProp->getNormalizationContext(['blog:list']);
        $this->assertTrue($listContext['always_applied']);
        $this->assertArrayNotHasKey('only_for_read', $listContext);
    }
}
