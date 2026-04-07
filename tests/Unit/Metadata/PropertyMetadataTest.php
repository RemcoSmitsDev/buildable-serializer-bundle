<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Unit\Metadata;

use Buildable\SerializerBundle\Metadata\AccessorType;
use Buildable\SerializerBundle\Metadata\PropertyMetadata;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buildable\SerializerBundle\Metadata\PropertyMetadata
 */
final class PropertyMetadataTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Default values
    // -------------------------------------------------------------------------

    public function testDefaultValues(): void
    {
        $pm = new PropertyMetadata();

        $this->assertSame('', $pm->name);
        $this->assertNull($pm->serializedName);
        $this->assertSame([], $pm->groups);
        $this->assertFalse($pm->ignored);
        $this->assertNull($pm->type);
        $this->assertFalse($pm->isNested);
        $this->assertFalse($pm->isCollection);
        $this->assertNull($pm->collectionValueType);
        $this->assertSame('', $pm->accessor);
        $this->assertSame(AccessorType::METHOD, $pm->accessorType);
        $this->assertNull($pm->maxDepth);
        $this->assertFalse($pm->nullable);
        $this->assertFalse($pm->isReadonly);
    }

    // -------------------------------------------------------------------------
    // getSerializedKey()
    // -------------------------------------------------------------------------

    public function testGetSerializedKeyReturnsNameWhenNoSerializedName(): void
    {
        $pm = new PropertyMetadata();
        $pm->name = 'foo';

        $this->assertSame('foo', $pm->getSerializedKey());
    }

    public function testGetSerializedKeyReturnsSerializedNameWhenSet(): void
    {
        $pm = new PropertyMetadata();
        $pm->name = 'foo';
        $pm->serializedName = 'bar';

        $this->assertSame('bar', $pm->getSerializedKey());
    }

    public function testGetSerializedKeyReturnsSerializedNameOverName(): void
    {
        $pm = new PropertyMetadata();
        $pm->name = 'myProperty';
        $pm->serializedName = 'my_property';

        $this->assertSame('my_property', $pm->getSerializedKey());
    }

    // -------------------------------------------------------------------------
    // isInGroup()
    // -------------------------------------------------------------------------

    public function testIsInGroupReturnsTrueWhenGroupsEmpty(): void
    {
        $pm = new PropertyMetadata();
        $pm->groups = [];

        $this->assertTrue($pm->isInGroup('any_group'));
        $this->assertTrue($pm->isInGroup('another'));
    }

    public function testIsInGroupReturnsTrueWhenGroupMatches(): void
    {
        $pm = new PropertyMetadata();
        $pm->groups = ['a', 'b'];

        $this->assertTrue($pm->isInGroup('a'));
        $this->assertTrue($pm->isInGroup('b'));
    }

    public function testIsInGroupReturnsFalseWhenGroupNotMatches(): void
    {
        $pm = new PropertyMetadata();
        $pm->groups = ['a', 'b'];

        $this->assertFalse($pm->isInGroup('c'));
        $this->assertFalse($pm->isInGroup(''));
    }

    // -------------------------------------------------------------------------
    // isEligibleForGroups()
    // -------------------------------------------------------------------------

    public function testIsEligibleForGroupsReturnsFalseWhenIgnored(): void
    {
        $pm = new PropertyMetadata();
        $pm->ignored = true;
        $pm->groups = [];

        $this->assertFalse($pm->isEligibleForGroups([]));
        $this->assertFalse($pm->isEligibleForGroups(['any']));
    }

    public function testIsEligibleForGroupsReturnsTrueWhenActiveGroupsEmpty(): void
    {
        $pm = new PropertyMetadata();
        $pm->groups = ['a', 'b'];

        $this->assertTrue($pm->isEligibleForGroups([]));
    }

    public function testIsEligibleForGroupsReturnsTrueWhenPropertyGroupsEmpty(): void
    {
        $pm = new PropertyMetadata();
        $pm->groups = [];

        $this->assertTrue($pm->isEligibleForGroups(['a', 'b']));
    }

    public function testIsEligibleForGroupsReturnsTrueWhenGroupIntersects(): void
    {
        $pm = new PropertyMetadata();
        $pm->groups = ['a', 'b'];

        $this->assertTrue($pm->isEligibleForGroups(['b', 'c']));
    }

    public function testIsEligibleForGroupsReturnsFalseWhenNoIntersection(): void
    {
        $pm = new PropertyMetadata();
        $pm->groups = ['a'];

        $this->assertFalse($pm->isEligibleForGroups(['b']));
        $this->assertFalse($pm->isEligibleForGroups(['c', 'd']));
    }

    public function testIsEligibleForGroupsReturnsFalseWhenIgnoredEvenWithMatchingGroups(): void
    {
        $pm = new PropertyMetadata();
        $pm->ignored = true;
        $pm->groups = ['a'];

        $this->assertFalse($pm->isEligibleForGroups(['a']));
    }

    // -------------------------------------------------------------------------
    // __toString()
    // -------------------------------------------------------------------------

    public function testToStringReturnsNonEmptyString(): void
    {
        $pm = new PropertyMetadata();

        $this->assertGreaterThan(0, strlen((string) $pm));
    }

    public function testToStringContainsPropertyName(): void
    {
        $pm = new PropertyMetadata();
        $pm->name = 'myField';

        $this->assertStringContainsString('myField', (string) $pm);
    }

    public function testToStringContainsAccessorType(): void
    {
        $pm = new PropertyMetadata();
        $pm->accessorType = AccessorType::PROPERTY;

        $this->assertStringContainsString('PROPERTY', (string) $pm);
    }
}
