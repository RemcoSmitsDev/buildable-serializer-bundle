<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\Metadata;

use Buildable\SerializerBundle\Metadata\AccessorType;
use Buildable\SerializerBundle\Metadata\ClassMetadata;
use Buildable\SerializerBundle\Metadata\PropertyMetadata;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buildable\SerializerBundle\Metadata\ClassMetadata
 */
final class ClassMetadataTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClassMetadata(): ClassMetadata
    {
        $cm = new ClassMetadata();
        $cm->className       = ClassMetadata::class;
        $cm->reflectionClass = new \ReflectionClass(ClassMetadata::class);

        return $cm;
    }

    private function makeProperty(string $name, array $overrides = []): PropertyMetadata
    {
        $pm       = new PropertyMetadata();
        $pm->name = $name;

        foreach ($overrides as $key => $value) {
            $pm->{$key} = $value;
        }

        return $pm;
    }

    // -------------------------------------------------------------------------
    // getShortName()
    // -------------------------------------------------------------------------

    public function testGetShortNameReturnsShortName(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertSame('ClassMetadata', $cm->getShortName());
    }

    public function testGetShortNameUsesReflectionClass(): void
    {
        $cm = new ClassMetadata();
        $cm->reflectionClass = new \ReflectionClass(\stdClass::class);

        $this->assertSame('stdClass', $cm->getShortName());
    }

    // -------------------------------------------------------------------------
    // getNamespace()
    // -------------------------------------------------------------------------

    public function testGetNamespaceReturnsNamespace(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertSame('Buildable\SerializerBundle\Metadata', $cm->getNamespace());
    }

    public function testGetNamespaceReturnsEmptyStringForRootClass(): void
    {
        $cm = new ClassMetadata();
        $cm->reflectionClass = new \ReflectionClass(\stdClass::class);

        // stdClass has no namespace
        $this->assertSame('', $cm->getNamespace());
    }

    // -------------------------------------------------------------------------
    // getProperty()
    // -------------------------------------------------------------------------

    public function testGetPropertyReturnsNullForUnknownProperty(): void
    {
        $cm = $this->makeClassMetadata();
        // no properties added

        $this->assertNull($cm->getProperty('nonExistent'));
        $this->assertNull($cm->getProperty('x'));
    }

    public function testGetPropertyReturnsPropertyByName(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('foo');

        $result = $cm->getProperty('foo');

        $this->assertNotNull($result);
        $this->assertSame('foo', $result->name);
    }

    public function testGetPropertyReturnsNullWhenNameDoesNotMatch(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('foo');

        $this->assertNull($cm->getProperty('bar'));
    }

    public function testGetPropertyReturnsCorrectPropertyAmongMultiple(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('alpha');
        $cm->properties[] = $this->makeProperty('beta');
        $cm->properties[] = $this->makeProperty('gamma');

        $result = $cm->getProperty('beta');

        $this->assertNotNull($result);
        $this->assertSame('beta', $result->name);
    }

    // -------------------------------------------------------------------------
    // hasGroupConstraints()
    // -------------------------------------------------------------------------

    public function testHasGroupConstraintsReturnsFalseWhenNoProperties(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertFalse($cm->hasGroupConstraints());
    }

    public function testHasGroupConstraintsReturnsFalseWhenNoGroups(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['groups' => []]);

        $this->assertFalse($cm->hasGroupConstraints());
    }

    public function testHasGroupConstraintsReturnsTrueWhenAnyPropertyHasGroups(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['groups' => []]);
        $cm->properties[] = $this->makeProperty('y', ['groups' => ['group:read']]);

        $this->assertTrue($cm->hasGroupConstraints());
    }

    public function testHasGroupConstraintsReturnsTrueWhenAllPropertiesHaveGroups(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('a', ['groups' => ['g1']]);
        $cm->properties[] = $this->makeProperty('b', ['groups' => ['g2']]);

        $this->assertTrue($cm->hasGroupConstraints());
    }

    // -------------------------------------------------------------------------
    // hasMaxDepthConstraints()
    // -------------------------------------------------------------------------

    public function testHasMaxDepthConstraintsReturnsFalseWhenNoMaxDepth(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['maxDepth' => null]);

        $this->assertFalse($cm->hasMaxDepthConstraints());
    }

    public function testHasMaxDepthConstraintsReturnsTrueWhenAnyPropertyHasMaxDepth(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['maxDepth' => null]);
        $cm->properties[] = $this->makeProperty('y', ['maxDepth' => 3]);

        $this->assertTrue($cm->hasMaxDepthConstraints());
    }

    // -------------------------------------------------------------------------
    // hasNestedObjects()
    // -------------------------------------------------------------------------

    public function testHasNestedObjectsReturnsFalseByDefault(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['isNested' => false]);

        $this->assertFalse($cm->hasNestedObjects());
    }

    public function testHasNestedObjectsReturnsFalseWhenNoProperties(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertFalse($cm->hasNestedObjects());
    }

    public function testHasNestedObjectsReturnsTrueWhenAnyPropertyIsNested(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['isNested' => false]);
        $cm->properties[] = $this->makeProperty('y', ['isNested' => true, 'type' => 'App\Entity\User']);

        $this->assertTrue($cm->hasNestedObjects());
    }

    // -------------------------------------------------------------------------
    // hasCollections()
    // -------------------------------------------------------------------------

    public function testHasCollectionsReturnsFalseByDefault(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['isCollection' => false]);

        $this->assertFalse($cm->hasCollections());
    }

    public function testHasCollectionsReturnsFalseWhenNoProperties(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertFalse($cm->hasCollections());
    }

    public function testHasCollectionsReturnsTrueWhenAnyPropertyIsCollection(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['isCollection' => false]);
        $cm->properties[] = $this->makeProperty('tags', ['isCollection' => true, 'type' => 'array']);

        $this->assertTrue($cm->hasCollections());
    }

    // -------------------------------------------------------------------------
    // getNestedClassTypes()
    // -------------------------------------------------------------------------

    public function testGetNestedClassTypesReturnsEmptyWhenNoNested(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('x', ['isNested' => false]);

        $this->assertSame([], $cm->getNestedClassTypes());
    }

    public function testGetNestedClassTypesReturnsEmptyWhenNoProperties(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertSame([], $cm->getNestedClassTypes());
    }

    public function testGetNestedClassTypesReturnsNestedTypes(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('author', [
            'isNested' => true,
            'type'     => 'App\Entity\Author',
        ]);

        $types = $cm->getNestedClassTypes();

        $this->assertContains('App\Entity\Author', $types);
    }

    public function testGetNestedClassTypesReturnsUniqueTypes(): void
    {
        $cm = $this->makeClassMetadata();
        // Two nested properties with the same type
        $cm->properties[] = $this->makeProperty('author', [
            'isNested' => true,
            'type'     => 'App\Entity\Author',
        ]);
        $cm->properties[] = $this->makeProperty('coAuthor', [
            'isNested' => true,
            'type'     => 'App\Entity\Author',
        ]);

        $types = $cm->getNestedClassTypes();

        $this->assertCount(1, $types);
        $this->assertContains('App\Entity\Author', $types);
    }

    public function testGetNestedClassTypesIncludesCollectionValueTypes(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('authors', [
            'isCollection'       => true,
            'collectionValueType' => 'App\Entity\Author',
        ]);

        $types = $cm->getNestedClassTypes();

        $this->assertContains('App\Entity\Author', $types);
    }

    public function testGetNestedClassTypesDeduplicatesAcrossNestedAndCollections(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('author', [
            'isNested' => true,
            'type'     => 'App\Entity\Author',
        ]);
        $cm->properties[] = $this->makeProperty('authors', [
            'isCollection'       => true,
            'collectionValueType' => 'App\Entity\Author',
        ]);

        $types = $cm->getNestedClassTypes();

        $this->assertCount(1, $types);
    }

    // -------------------------------------------------------------------------
    // __toString()
    // -------------------------------------------------------------------------

    public function testToStringContainsClassName(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertStringContainsString('ClassMetadata', (string) $cm);
    }

    public function testToStringContainsPropertyCount(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->properties[] = $this->makeProperty('a');
        $cm->properties[] = $this->makeProperty('b');

        $str = (string) $cm;

        $this->assertStringContainsString('2', $str);
    }

    public function testToStringReturnsNonEmptyString(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertGreaterThan(0, strlen((string) $cm));
    }
}
