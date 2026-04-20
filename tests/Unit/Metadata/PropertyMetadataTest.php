<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\AccessorType;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MutatorType;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyMetadata;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyMetadata
 */
final class PropertyMetadataTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $pm = new PropertyMetadata();

        $this->assertSame('', $pm->getName());
        $this->assertNull($pm->getSerializedName());
        $this->assertSame([], $pm->getGroups());
        $this->assertFalse($pm->isIgnored());
        $this->assertNull($pm->getType());
        $this->assertFalse($pm->isNested());
        $this->assertFalse($pm->isCollection());
        $this->assertNull($pm->getCollectionValueType());
        $this->assertSame('', $pm->getAccessor());
        $this->assertSame(AccessorType::METHOD, $pm->getAccessorType());
        $this->assertNull($pm->getMaxDepth());
        $this->assertFalse($pm->isNullable());
        $this->assertFalse($pm->isReadonly());
        $this->assertSame(MutatorType::NONE, $pm->getMutatorType());
        $this->assertNull($pm->getMutator());
        $this->assertFalse($pm->hasMutator());
    }

    public function testGetSerializedKeyReturnsNameWhenNoSerializedName(): void
    {
        $pm = new PropertyMetadata(name: 'foo');

        $this->assertSame('foo', $pm->getSerializedKey());
    }

    public function testGetSerializedKeyReturnsSerializedNameWhenSet(): void
    {
        $pm = new PropertyMetadata(name: 'foo', serializedName: 'bar');

        $this->assertSame('bar', $pm->getSerializedKey());
    }

    public function testGetSerializedKeyReturnsSerializedNameOverName(): void
    {
        $pm = new PropertyMetadata(name: 'myProperty', serializedName: 'my_property');

        $this->assertSame('my_property', $pm->getSerializedKey());
    }

    public function testIsInGroupReturnsTrueWhenGroupsEmpty(): void
    {
        $pm = new PropertyMetadata(groups: []);

        $this->assertTrue($pm->isInGroup('any_group'));
        $this->assertTrue($pm->isInGroup('another'));
    }

    public function testIsInGroupReturnsTrueWhenGroupMatches(): void
    {
        $pm = new PropertyMetadata(groups: ['a', 'b']);

        $this->assertTrue($pm->isInGroup('a'));
        $this->assertTrue($pm->isInGroup('b'));
    }

    public function testIsInGroupReturnsFalseWhenGroupNotMatches(): void
    {
        $pm = new PropertyMetadata(groups: ['a', 'b']);

        $this->assertFalse($pm->isInGroup('c'));
        $this->assertFalse($pm->isInGroup(''));
    }

    public function testIsEligibleForGroupsReturnsFalseWhenIgnored(): void
    {
        $pm = new PropertyMetadata(groups: [], ignored: true);

        $this->assertFalse($pm->isEligibleForGroups([]));
        $this->assertFalse($pm->isEligibleForGroups(['any']));
    }

    public function testIsEligibleForGroupsReturnsTrueWhenActiveGroupsEmpty(): void
    {
        $pm = new PropertyMetadata(groups: ['a', 'b']);

        $this->assertTrue($pm->isEligibleForGroups([]));
    }

    public function testIsEligibleForGroupsReturnsTrueWhenPropertyGroupsEmpty(): void
    {
        $pm = new PropertyMetadata(groups: []);

        $this->assertTrue($pm->isEligibleForGroups(['a', 'b']));
    }

    public function testIsEligibleForGroupsReturnsTrueWhenGroupIntersects(): void
    {
        $pm = new PropertyMetadata(groups: ['a', 'b']);

        $this->assertTrue($pm->isEligibleForGroups(['b', 'c']));
    }

    public function testIsEligibleForGroupsReturnsFalseWhenNoIntersection(): void
    {
        $pm = new PropertyMetadata(groups: ['a']);

        $this->assertFalse($pm->isEligibleForGroups(['b']));
        $this->assertFalse($pm->isEligibleForGroups(['c', 'd']));
    }

    public function testIsEligibleForGroupsReturnsFalseWhenIgnoredEvenWithMatchingGroups(): void
    {
        $pm = new PropertyMetadata(groups: ['a'], ignored: true);

        $this->assertFalse($pm->isEligibleForGroups(['a']));
    }

    public function testMutatorTypeCanBeInjectedThroughConstructor(): void
    {
        $pm = new PropertyMetadata(name: 'name', mutatorType: MutatorType::SETTER, mutator: 'setName');

        $this->assertSame(MutatorType::SETTER, $pm->getMutatorType());
        $this->assertSame('setName', $pm->getMutator());
    }

    public function testSetAndGetMutatorType(): void
    {
        $pm = new PropertyMetadata(name: 'name');

        $pm->setMutatorType(MutatorType::WITHER);

        $this->assertSame(MutatorType::WITHER, $pm->getMutatorType());
    }

    public function testSetAndGetMutator(): void
    {
        $pm = new PropertyMetadata(name: 'name');

        $pm->setMutator('setName');
        $this->assertSame('setName', $pm->getMutator());

        $pm->setMutator(null);
        $this->assertNull($pm->getMutator());
    }

    public function testHasMutatorReturnsFalseForNone(): void
    {
        $pm = new PropertyMetadata(name: 'name', mutatorType: MutatorType::NONE);

        $this->assertFalse($pm->hasMutator());
    }

    public function testHasMutatorReturnsFalseForConstructor(): void
    {
        // CONSTRUCTOR means the property is only writable through the class
        // constructor. The population phase of the denormalizer must skip it,
        // which is exactly what hasMutator() == false signals.
        $pm = new PropertyMetadata(name: 'name', mutatorType: MutatorType::CONSTRUCTOR);

        $this->assertFalse($pm->hasMutator());
    }

    public function testHasMutatorReturnsTrueForProperty(): void
    {
        $pm = new PropertyMetadata(name: 'name', mutatorType: MutatorType::PROPERTY, mutator: 'name');

        $this->assertTrue($pm->hasMutator());
    }

    public function testHasMutatorReturnsTrueForSetter(): void
    {
        $pm = new PropertyMetadata(name: 'name', mutatorType: MutatorType::SETTER, mutator: 'setName');

        $this->assertTrue($pm->hasMutator());
    }

    public function testHasMutatorReturnsTrueForWither(): void
    {
        $pm = new PropertyMetadata(name: 'name', mutatorType: MutatorType::WITHER, mutator: 'withName');

        $this->assertTrue($pm->hasMutator());
    }

    public function testMutatorTypeIsIndependentOfAccessorType(): void
    {
        // AccessorType (read side) and MutatorType (write side) are
        // independent: a property can be read through a getter method but
        // written through a public property, for example.
        $pm = new PropertyMetadata(
            name: 'name',
            accessor: 'getName',
            accessorType: AccessorType::METHOD,
            mutatorType: MutatorType::PROPERTY,
            mutator: 'name',
        );

        $this->assertSame(AccessorType::METHOD, $pm->getAccessorType());
        $this->assertSame('getName', $pm->getAccessor());
        $this->assertSame(MutatorType::PROPERTY, $pm->getMutatorType());
        $this->assertSame('name', $pm->getMutator());
    }

    public function testMutatorTypeSetterDoesNotImplyNonNullMutator(): void
    {
        // It is legal to temporarily assign a mutator type without yet
        // having a mutator name (e.g. during incremental metadata building).
        // hasMutator() relies on the type, not the name, so the name may
        // still be null while the type is SETTER/WITHER/PROPERTY.
        $pm = new PropertyMetadata(name: 'name', mutatorType: MutatorType::SETTER);

        $this->assertSame(MutatorType::SETTER, $pm->getMutatorType());
        $this->assertNull($pm->getMutator());
        $this->assertTrue($pm->hasMutator());
    }
}
