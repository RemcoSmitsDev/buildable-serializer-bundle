<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\AccessorType;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorParameterMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyMetadata;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata
 */
final class ClassMetadataTest extends TestCase
{
    /** @return ClassMetadata<object> */
    private function makeClassMetadata(): ClassMetadata
    {
        return new ClassMetadata(
            reflectionClass: new \ReflectionClass(ClassMetadata::class),
            className: ClassMetadata::class,
        );
    }

    private function makeProperty(string $name, array $overrides = []): PropertyMetadata
    {
        return new PropertyMetadata(
            name: $name,
            serializedName: $overrides['serializedName'] ?? null,
            groups: $overrides['groups'] ?? [],
            ignored: $overrides['ignored'] ?? false,
            type: $overrides['type'] ?? null,
            isNested: $overrides['isNested'] ?? false,
            isCollection: $overrides['isCollection'] ?? false,
            collectionValueType: $overrides['collectionValueType'] ?? null,
            accessor: $overrides['accessor'] ?? '',
            accessorType: $overrides['accessorType'] ?? AccessorType::METHOD,
            maxDepth: $overrides['maxDepth'] ?? null,
            nullable: $overrides['nullable'] ?? false,
            isReadonly: $overrides['isReadonly'] ?? false,
        );
    }

    public function testGetShortNameReturnsShortName(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertSame('ClassMetadata', $cm->getShortName());
    }

    public function testGetShortNameUsesReflectionClass(): void
    {
        $cm = new ClassMetadata(new \ReflectionClass(\stdClass::class), \stdClass::class);

        $this->assertSame('stdClass', $cm->getShortName());
    }

    public function testGetNamespaceReturnsNamespace(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertSame("RemcoSmitsDev\BuildableSerializerBundle\Metadata", $cm->getNamespace());
    }

    public function testGetNamespaceReturnsEmptyStringForRootClass(): void
    {
        $cm = new ClassMetadata(new \ReflectionClass(\stdClass::class), \stdClass::class);

        // stdClass has no namespace
        $this->assertSame('', $cm->getNamespace());
    }

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
        $cm->addProperty($this->makeProperty('foo'));

        $result = $cm->getProperty('foo');

        $this->assertNotNull($result);
        $this->assertSame('foo', $result->getName());
    }

    public function testGetPropertyReturnsNullWhenNameDoesNotMatch(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('foo'));

        $this->assertNull($cm->getProperty('bar'));
    }

    public function testGetPropertyReturnsCorrectPropertyAmongMultiple(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('alpha'));
        $cm->addProperty($this->makeProperty('beta'));
        $cm->addProperty($this->makeProperty('gamma'));

        $result = $cm->getProperty('beta');

        $this->assertNotNull($result);
        $this->assertSame('beta', $result->getName());
    }

    public function testHasGroupConstraintsReturnsFalseWhenNoProperties(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertFalse($cm->hasGroupConstraints());
    }

    public function testHasGroupConstraintsReturnsFalseWhenNoGroups(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('x', ['groups' => []]));

        $this->assertFalse($cm->hasGroupConstraints());
    }

    public function testHasGroupConstraintsReturnsTrueWhenAnyPropertyHasGroups(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('x', ['groups' => []]));
        $cm->addProperty($this->makeProperty('y', [
            'groups' => ['group:read'],
        ]));

        $this->assertTrue($cm->hasGroupConstraints());
    }

    public function testHasGroupConstraintsReturnsTrueWhenAllPropertiesHaveGroups(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('a', ['groups' => ['g1']]));
        $cm->addProperty($this->makeProperty('b', ['groups' => ['g2']]));

        $this->assertTrue($cm->hasGroupConstraints());
    }

    public function testHasMaxDepthConstraintsReturnsFalseWhenNoMaxDepth(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('x', ['maxDepth' => null]));

        $this->assertFalse($cm->hasMaxDepthConstraints());
    }

    public function testHasMaxDepthConstraintsReturnsTrueWhenAnyPropertyHasMaxDepth(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('x', ['maxDepth' => null]));
        $cm->addProperty($this->makeProperty('y', ['maxDepth' => 3]));

        $this->assertTrue($cm->hasMaxDepthConstraints());
    }

    public function testHasNestedObjectsReturnsFalseByDefault(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('x', ['isNested' => false]));

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
        $cm->addProperty($this->makeProperty('x', ['isNested' => false]));
        $cm->addProperty($this->makeProperty('y', [
            'isNested' => true,
            'type' => "App\Entity\User",
        ]));

        $this->assertTrue($cm->hasNestedObjects());
    }

    public function testHasCollectionsReturnsFalseByDefault(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('x', ['isCollection' => false]));

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
        $cm->addProperty($this->makeProperty('x', ['isCollection' => false]));
        $cm->addProperty($this->makeProperty('tags', [
            'isCollection' => true,
            'type' => 'array',
        ]));

        $this->assertTrue($cm->hasCollections());
    }

    public function testGetNestedClassTypesReturnsEmptyWhenNoNested(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('x', ['isNested' => false]));

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
        $cm->addProperty($this->makeProperty('author', [
            'isNested' => true,
            'type' => "App\Entity\Author",
        ]));

        $types = $cm->getNestedClassTypes();

        $this->assertContains("App\Entity\Author", $types);
    }

    public function testGetNestedClassTypesReturnsUniqueTypes(): void
    {
        $cm = $this->makeClassMetadata();
        // Two nested properties with the same type
        $cm->addProperty($this->makeProperty('author', [
            'isNested' => true,
            'type' => "App\Entity\Author",
        ]));
        $cm->addProperty($this->makeProperty('coAuthor', [
            'isNested' => true,
            'type' => "App\Entity\Author",
        ]));

        $types = $cm->getNestedClassTypes();

        $this->assertCount(1, $types);
        $this->assertContains("App\Entity\Author", $types);
    }

    public function testGetNestedClassTypesIncludesCollectionValueTypes(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('authors', [
            'isCollection' => true,
            'collectionValueType' => "App\Entity\Author",
        ]));

        $types = $cm->getNestedClassTypes();

        $this->assertContains("App\Entity\Author", $types);
    }

    public function testGetNestedClassTypesDeduplicatesAcrossNestedAndCollections(): void
    {
        $cm = $this->makeClassMetadata();
        $cm->addProperty($this->makeProperty('author', [
            'isNested' => true,
            'type' => "App\Entity\Author",
        ]));
        $cm->addProperty($this->makeProperty('authors', [
            'isCollection' => true,
            'collectionValueType' => "App\Entity\Author",
        ]));

        $types = $cm->getNestedClassTypes();

        $this->assertCount(1, $types);
    }

    public function testHasConstructorDefaultsToFalse(): void
    {
        $cm = $this->makeClassMetadata();

        // A freshly-constructed ClassMetadata must default to "no constructor"
        // so that callers who don't explicitly call setHasConstructor() fall
        // through to the `new Foo()` branch of the generator instead of
        // trying to pass arguments.
        $this->assertFalse($cm->hasConstructor());
    }

    public function testSetAndGetHasConstructor(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setHasConstructor(true);
        $this->assertTrue($cm->hasConstructor());

        $cm->setHasConstructor(false);
        $this->assertFalse($cm->hasConstructor());
    }

    public function testGetConstructorParametersDefaultsToEmptyArray(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertSame([], $cm->getConstructorParameters());
    }

    public function testSetAndGetConstructorParameters(): void
    {
        $cm = $this->makeClassMetadata();

        $id = new ConstructorParameterMetadata(name: 'id', serializedName: 'id', type: 'int');
        $name = new ConstructorParameterMetadata(name: 'name', serializedName: 'name', type: 'string');

        $cm->setConstructorParameters([$id, $name]);

        $this->assertSame([$id, $name], $cm->getConstructorParameters());
    }

    public function testSetConstructorParametersOverwritesPreviousValues(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(name: 'old', serializedName: 'old'),
        ]);

        $replacement = new ConstructorParameterMetadata(name: 'new', serializedName: 'new');
        $cm->setConstructorParameters([$replacement]);

        $this->assertSame([$replacement], $cm->getConstructorParameters());
    }

    public function testHasConstructorParametersReturnsFalseWhenEmpty(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertFalse($cm->hasConstructorParameters());
    }

    public function testHasConstructorParametersReturnsTrueWhenNotEmpty(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(name: 'x', serializedName: 'x'),
        ]);

        $this->assertTrue($cm->hasConstructorParameters());
    }

    public function testHasConstructorAndHasConstructorParametersAreIndependent(): void
    {
        $cm = $this->makeClassMetadata();

        // Empty constructor: class has a constructor, but zero parameters.
        $cm->setHasConstructor(true);
        $cm->setConstructorParameters([]);

        $this->assertTrue($cm->hasConstructor());
        $this->assertFalse($cm->hasConstructorParameters());
    }

    public function testHasRequiredConstructorParametersReturnsFalseWhenEmpty(): void
    {
        $cm = $this->makeClassMetadata();

        $this->assertFalse($cm->hasRequiredConstructorParameters());
    }

    public function testHasRequiredConstructorParametersReturnsFalseWhenAllOptional(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(
                name: 'age',
                serializedName: 'age',
                type: 'int',
                isRequired: false,
                hasDefault: true,
                defaultValue: 18,
            ),
            new ConstructorParameterMetadata(
                name: 'bio',
                serializedName: 'bio',
                type: 'string',
                isRequired: false,
                hasDefault: true,
                defaultValue: null,
                isNullable: true,
            ),
        ]);

        $this->assertFalse($cm->hasRequiredConstructorParameters());
    }

    public function testHasRequiredConstructorParametersReturnsTrueWhenAtLeastOneRequired(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(
                name: 'age',
                serializedName: 'age',
                type: 'int',
                isRequired: false,
                hasDefault: true,
                defaultValue: 18,
            ),
            new ConstructorParameterMetadata(name: 'name', serializedName: 'name', type: 'string', isRequired: true),
        ]);

        $this->assertTrue($cm->hasRequiredConstructorParameters());
    }

    public function testGetConstructorReferencedClassesReturnsEmptyWhenNoNested(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(name: 'age', serializedName: 'age', type: 'int'),
            new ConstructorParameterMetadata(name: 'name', serializedName: 'name', type: 'string'),
        ]);

        $this->assertSame([], $cm->getConstructorReferencedClasses());
    }

    public function testGetConstructorReferencedClassesIncludesNestedObjects(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(
                name: 'address',
                serializedName: 'address',
                type: "App\\Entity\\Address",
                isNested: true,
            ),
        ]);

        $this->assertSame(["App\\Entity\\Address"], $cm->getConstructorReferencedClasses());
    }

    public function testGetConstructorReferencedClassesIncludesCollectionValueTypes(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(
                name: 'tags',
                serializedName: 'tags',
                type: 'array',
                isCollection: true,
                collectionValueType: "App\\Entity\\Tag",
            ),
        ]);

        $this->assertSame(["App\\Entity\\Tag"], $cm->getConstructorReferencedClasses());
    }

    public function testGetConstructorReferencedClassesDeduplicatesTypes(): void
    {
        $cm = $this->makeClassMetadata();

        // A nested object and a typed collection referring to the same FQCN
        // must yield a single entry in the referenced-classes list so the
        // generator emits a single `use` statement.
        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(
                name: 'author',
                serializedName: 'author',
                type: "App\\Entity\\Author",
                isNested: true,
            ),
            new ConstructorParameterMetadata(
                name: 'coAuthors',
                serializedName: 'coAuthors',
                type: 'array',
                isCollection: true,
                collectionValueType: "App\\Entity\\Author",
            ),
        ]);

        $this->assertSame(["App\\Entity\\Author"], $cm->getConstructorReferencedClasses());
    }

    public function testGetConstructorReferencedClassesIgnoresScalarParameters(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(name: 'age', serializedName: 'age', type: 'int'),
            new ConstructorParameterMetadata(name: 'name', serializedName: 'name', type: 'string'),
            new ConstructorParameterMetadata(
                name: 'address',
                serializedName: 'address',
                type: "App\\Entity\\Address",
                isNested: true,
            ),
        ]);

        $this->assertSame(["App\\Entity\\Address"], $cm->getConstructorReferencedClasses());
    }

    public function testGetConstructorReferencedClassesIgnoresUntypedCollection(): void
    {
        $cm = $this->makeClassMetadata();

        // A collection with no resolvable value type (e.g. a plain `array`
        // without a docblock) must not contribute a bogus entry.
        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(
                name: 'tags',
                serializedName: 'tags',
                type: 'array',
                isCollection: true,
                collectionValueType: null,
            ),
        ]);

        $this->assertSame([], $cm->getConstructorReferencedClasses());
    }

    public function testGetConstructorReferencedClassesReturnsListWithSequentialKeys(): void
    {
        $cm = $this->makeClassMetadata();

        $cm->setConstructorParameters([
            new ConstructorParameterMetadata(
                name: 'author',
                serializedName: 'author',
                type: "App\\Entity\\Author",
                isNested: true,
            ),
            new ConstructorParameterMetadata(
                name: 'address',
                serializedName: 'address',
                type: "App\\Entity\\Address",
                isNested: true,
            ),
        ]);

        $result = $cm->getConstructorReferencedClasses();

        // The method must return a list, not a map keyed by FQCN, so that
        // it can be safely passed to array_merge / iteration.
        $this->assertSame([0, 1], array_keys($result));
    }
}
