<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorParameterMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyContext;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorParameterMetadata
 */
final class ConstructorParameterMetadataTest extends TestCase
{
    public function testDefaultConstructionYieldsRequiredNonNullableNonPromotedParam(): void
    {
        $param = new ConstructorParameterMetadata(name: 'name', serializedName: 'name');

        $this->assertSame('name', $param->getName());
        $this->assertSame('name', $param->getSerializedName());
        $this->assertNull($param->getType());
        $this->assertFalse($param->isNested());
        $this->assertFalse($param->isCollection());
        $this->assertNull($param->getCollectionValueType());
        $this->assertTrue($param->isRequired());
        $this->assertFalse($param->hasDefault());
        $this->assertNull($param->getDefaultValue());
        $this->assertFalse($param->isNullable());
        $this->assertFalse($param->isPromoted());
        $this->assertFalse($param->isVariadic());
    }

    public function testStoresBasicScalarType(): void
    {
        $param = new ConstructorParameterMetadata(name: 'age', serializedName: 'age', type: 'int');

        $this->assertSame('int', $param->getType());
        $this->assertFalse($param->isNested());
        $this->assertFalse($param->isCollection());
    }

    public function testDifferentSerializedNameIsPreserved(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'emailAddress',
            serializedName: 'email_address',
            type: 'string',
        );

        $this->assertSame('emailAddress', $param->getName());
        $this->assertSame('email_address', $param->getSerializedName());
    }

    public function testNestedObjectParameter(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'address',
            serializedName: 'address',
            type: 'App\\Entity\\Address',
            isNested: true,
            isNullable: true,
        );

        $this->assertTrue($param->isNested());
        $this->assertSame('App\\Entity\\Address', $param->getType());
        $this->assertTrue($param->isNullable());
        $this->assertFalse($param->isCollection());
    }

    public function testCollectionParameter(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'tags',
            serializedName: 'tags',
            type: 'array',
            isCollection: true,
            collectionValueType: 'App\\Entity\\Tag',
        );

        $this->assertTrue($param->isCollection());
        $this->assertSame('array', $param->getType());
        $this->assertSame('App\\Entity\\Tag', $param->getCollectionValueType());
        $this->assertFalse($param->isNested());
    }

    public function testRequiredParameterWithoutDefault(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'name',
            serializedName: 'name',
            type: 'string',
            isRequired: true,
            hasDefault: false,
        );

        $this->assertTrue($param->isRequired());
        $this->assertFalse($param->hasDefault());
    }

    public function testOptionalParameterWithIntDefault(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'age',
            serializedName: 'age',
            type: 'int',
            isRequired: false,
            hasDefault: true,
            defaultValue: 18,
        );

        $this->assertFalse($param->isRequired());
        $this->assertTrue($param->hasDefault());
        $this->assertSame(18, $param->getDefaultValue());
    }

    public function testOptionalParameterWithStringDefault(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'role',
            serializedName: 'role',
            type: 'string',
            isRequired: false,
            hasDefault: true,
            defaultValue: 'user',
        );

        $this->assertSame('user', $param->getDefaultValue());
    }

    public function testOptionalParameterWithNullDefault(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'bio',
            serializedName: 'bio',
            type: 'string',
            isRequired: false,
            hasDefault: true,
            defaultValue: null,
            isNullable: true,
        );

        $this->assertTrue($param->hasDefault());
        $this->assertNull($param->getDefaultValue());
        $this->assertTrue($param->isNullable());
    }

    public function testOptionalParameterWithArrayDefault(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'tags',
            serializedName: 'tags',
            type: 'array',
            isCollection: true,
            isRequired: false,
            hasDefault: true,
            defaultValue: [],
        );

        $this->assertSame([], $param->getDefaultValue());
    }

    public function testOptionalParameterWithBoolDefault(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'active',
            serializedName: 'active',
            type: 'bool',
            isRequired: false,
            hasDefault: true,
            defaultValue: true,
        );

        $this->assertTrue($param->getDefaultValue());
    }

    public function testNullableParameterWithoutDefaultIsStillFlaggedAsNullable(): void
    {
        // Nullable without default: `?string $nickname`.
        // The generator treats this as optional-but-required-in-data because
        // the field must be present, but null is an accepted value.
        $param = new ConstructorParameterMetadata(
            name: 'nickname',
            serializedName: 'nickname',
            type: 'string',
            isRequired: false,
            hasDefault: false,
            isNullable: true,
        );

        $this->assertTrue($param->isNullable());
        $this->assertFalse($param->hasDefault());
        $this->assertFalse($param->isRequired());
    }

    public function testPromotedFlag(): void
    {
        $param = new ConstructorParameterMetadata(name: 'id', serializedName: 'id', type: 'int', isPromoted: true);

        $this->assertTrue($param->isPromoted());
    }

    public function testVariadicFlag(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'children',
            serializedName: 'children',
            type: 'string',
            isVariadic: true,
        );

        $this->assertTrue($param->isVariadic());
    }

    public function testAllFieldsTogether(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'status',
            serializedName: 'status_code',
            type: 'App\\Enum\\Status',
            isNested: true,
            isCollection: false,
            collectionValueType: null,
            isRequired: false,
            hasDefault: true,
            defaultValue: 'PENDING',
            isNullable: false,
            isPromoted: true,
            isVariadic: false,
        );

        $this->assertSame('status', $param->getName());
        $this->assertSame('status_code', $param->getSerializedName());
        $this->assertSame('App\\Enum\\Status', $param->getType());
        $this->assertTrue($param->isNested());
        $this->assertFalse($param->isCollection());
        $this->assertNull($param->getCollectionValueType());
        $this->assertFalse($param->isRequired());
        $this->assertTrue($param->hasDefault());
        $this->assertSame('PENDING', $param->getDefaultValue());
        $this->assertFalse($param->isNullable());
        $this->assertTrue($param->isPromoted());
        $this->assertFalse($param->isVariadic());
    }

    public function testDefaultConstructionYieldsEmptyAttributeFields(): void
    {
        // Freshly-constructed metadata must default every attribute-derived
        // field to its empty sentinel so that consumers (e.g. the generator)
        // can safely iterate / inspect them without null-guards.
        $param = new ConstructorParameterMetadata(name: 'value', serializedName: 'value');

        $this->assertSame([], $param->getGroups());
        $this->assertFalse($param->isIgnored());
        $this->assertNull($param->getMaxDepth());
        $this->assertSame([], $param->getContexts());
        $this->assertFalse($param->hasContexts());
    }

    public function testStoresGroupsList(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'email',
            serializedName: 'email',
            type: 'string',
            groups: ['user:read', 'user:write'],
        );

        $this->assertSame(['user:read', 'user:write'], $param->getGroups());
    }

    public function testEmptyGroupsListIsPreservedAsEmptyArray(): void
    {
        $param = new ConstructorParameterMetadata(name: 'email', serializedName: 'email', type: 'string', groups: []);

        $this->assertSame([], $param->getGroups());
    }

    public function testIgnoredFlagIsStoredVerbatim(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'secret',
            serializedName: 'secret',
            type: 'string',
            ignored: true,
        );

        $this->assertTrue($param->isIgnored());
    }

    public function testIgnoredFlagDefaultsToFalse(): void
    {
        $param = new ConstructorParameterMetadata(name: 'value', serializedName: 'value');

        $this->assertFalse($param->isIgnored());
    }

    public function testMaxDepthIsStoredVerbatim(): void
    {
        $param = new ConstructorParameterMetadata(
            name: 'address',
            serializedName: 'address',
            type: "App\\Entity\\Address",
            isNested: true,
            maxDepth: 3,
        );

        $this->assertSame(3, $param->getMaxDepth());
    }

    public function testMaxDepthDefaultsToNull(): void
    {
        $param = new ConstructorParameterMetadata(name: 'value', serializedName: 'value');

        $this->assertNull($param->getMaxDepth());
    }

    public function testStoresContextsList(): void
    {
        $ctxA = new PropertyContext(
            context: ['trim' => true],
            normalizationContext: [],
            denormalizationContext: [],
            groups: [],
        );

        $ctxB = new PropertyContext(
            context: [],
            normalizationContext: ['format' => 'json'],
            denormalizationContext: [],
            groups: ['user:read'],
        );

        $param = new ConstructorParameterMetadata(
            name: 'value',
            serializedName: 'value',
            type: 'string',
            contexts: [$ctxA, $ctxB],
        );

        $this->assertSame([$ctxA, $ctxB], $param->getContexts());
        $this->assertTrue($param->hasContexts());
    }

    public function testHasContextsReturnsFalseForEmptyContexts(): void
    {
        $param = new ConstructorParameterMetadata(name: 'value', serializedName: 'value', contexts: []);

        $this->assertFalse($param->hasContexts());
    }

    public function testHasContextsReturnsTrueWhenAtLeastOneContextPresent(): void
    {
        $context = new PropertyContext(
            context: ['trim' => true],
            normalizationContext: [],
            denormalizationContext: [],
            groups: [],
        );

        $param = new ConstructorParameterMetadata(name: 'value', serializedName: 'value', contexts: [$context]);

        $this->assertTrue($param->hasContexts());
    }

    public function testAllAttributeFieldsCoexistWithStructuralFields(): void
    {
        // A single parameter carrying every kind of metadata at once,
        // verifying that the structural fields (type, default value,
        // required flag, etc.) do not clash with the attribute-derived
        // fields that were added later.
        $context = new PropertyContext(
            context: ['trim' => true],
            normalizationContext: [],
            denormalizationContext: [],
            groups: [],
        );

        $param = new ConstructorParameterMetadata(
            name: 'postalCode',
            serializedName: 'postal_code',
            type: 'string',
            isNested: false,
            isCollection: false,
            collectionValueType: null,
            isRequired: true,
            hasDefault: false,
            defaultValue: null,
            isNullable: false,
            isPromoted: false,
            isVariadic: false,
            groups: ['address:read', 'user:read'],
            ignored: false,
            maxDepth: 2,
            contexts: [$context],
        );

        $this->assertSame('postalCode', $param->getName());
        $this->assertSame('postal_code', $param->getSerializedName());
        $this->assertSame('string', $param->getType());
        $this->assertTrue($param->isRequired());
        $this->assertFalse($param->isPromoted());
        $this->assertSame(['address:read', 'user:read'], $param->getGroups());
        $this->assertFalse($param->isIgnored());
        $this->assertSame(2, $param->getMaxDepth());
        $this->assertSame([$context], $param->getContexts());
        $this->assertTrue($param->hasContexts());
    }
}
