<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorMetadataExtractor;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorParameterMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\AddressFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\PersonFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SetterFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\StatusFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\WitherFixture;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorMetadataExtractor
 */
final class ConstructorMetadataExtractorTest extends TestCase
{
    private ConstructorMetadataExtractor $extractor;

    protected function setUp(): void
    {
        $reflection = new ReflectionExtractor();
        $phpDoc = new PhpDocExtractor();

        $extractor = new PropertyInfoExtractor(
            listExtractors: [$reflection],
            typeExtractors: [$phpDoc, $reflection],
            accessExtractors: [$reflection],
        );

        $this->extractor = new ConstructorMetadataExtractor($extractor);
    }

    public function testExtractReturnsEmptyArrayForClassWithoutConstructor(): void
    {
        $reflection = new \ReflectionClass(new class {});

        $this->assertSame([], $this->extractor->extract($reflection));
    }

    public function testHasConstructorReturnsFalseForClassWithoutConstructor(): void
    {
        $reflection = new \ReflectionClass(new class {});

        $this->assertFalse($this->extractor->hasConstructor($reflection));
    }

    public function testHasConstructorReturnsTrueForClassWithConstructor(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);

        $this->assertTrue($this->extractor->hasConstructor($reflection));
    }

    public function testHasConstructorParametersReturnsFalseForClassWithoutConstructor(): void
    {
        $reflection = new \ReflectionClass(new class {});

        $this->assertFalse($this->extractor->hasConstructorParameters($reflection));
    }

    public function testHasConstructorParametersReturnsFalseForEmptyConstructor(): void
    {
        $reflection = new \ReflectionClass(new class {
            public function __construct() {}
        });

        $this->assertFalse($this->extractor->hasConstructorParameters($reflection));
    }

    public function testHasConstructorParametersReturnsTrueForNonEmptyConstructor(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);

        $this->assertTrue($this->extractor->hasConstructorParameters($reflection));
    }

    public function testExtractReturnsEmptyArrayForEmptyConstructor(): void
    {
        $reflection = new \ReflectionClass(SetterFixture::class);

        $this->assertSame([], $this->extractor->extract($reflection));
    }

    public function testExtractSimpleBlogConstructor(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(4, $params);
        $this->assertContainsOnlyInstancesOf(ConstructorParameterMetadata::class, $params);

        $this->assertSame('id', $params[0]->getName());
        $this->assertSame('title', $params[1]->getName());
        $this->assertSame('content', $params[2]->getName());
        $this->assertSame('excerpt', $params[3]->getName());
    }

    public function testExtractPreservesParameterOrder(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);
        $params = $this->extractor->extract($reflection);

        $names = array_map(static fn(ConstructorParameterMetadata $p): string => $p->getName(), $params);

        $this->assertSame(['id', 'title', 'content', 'excerpt'], $names);
    }

    public function testExtractResolvesScalarTypes(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);
        $params = $this->extractor->extract($reflection);

        $this->assertSame('int', $params[0]->getType());
        $this->assertSame('string', $params[1]->getType());
        $this->assertSame('string', $params[2]->getType());
        $this->assertSame('string', $params[3]->getType());
    }

    public function testExtractMarksPromotedParameters(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);
        $params = $this->extractor->extract($reflection);

        foreach ($params as $param) {
            $this->assertTrue($param->isPromoted(), sprintf(
                'Parameter $%s should be flagged as promoted.',
                $param->getName(),
            ));
        }
    }

    public function testExtractMarksRequiredParametersWithoutDefault(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);
        $params = $this->extractor->extract($reflection);

        // id, title, content are required (no default, not nullable)
        $this->assertTrue($params[0]->isRequired());
        $this->assertTrue($params[1]->isRequired());
        $this->assertTrue($params[2]->isRequired());

        // excerpt is nullable with default null → not required
        $this->assertFalse($params[3]->isRequired());
    }

    public function testExtractDetectsHasDefault(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);
        $params = $this->extractor->extract($reflection);

        $this->assertFalse($params[0]->hasDefault());
        $this->assertFalse($params[1]->hasDefault());
        $this->assertFalse($params[2]->hasDefault());
        $this->assertTrue($params[3]->hasDefault());
    }

    public function testExtractCapturesNullDefault(): void
    {
        $reflection = new \ReflectionClass(SimpleBlog::class);
        $params = $this->extractor->extract($reflection);

        $excerpt = $params[3];
        $this->assertTrue($excerpt->hasDefault());
        $this->assertNull($excerpt->getDefaultValue());
        $this->assertTrue($excerpt->isNullable());
    }

    public function testExtractPersonFixtureReturnsAllParameters(): void
    {
        $reflection = new \ReflectionClass(PersonFixture::class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(5, $params);

        $byName = [];
        foreach ($params as $param) {
            $byName[$param->getName()] = $param;
        }

        $this->assertArrayHasKey('name', $byName);
        $this->assertArrayHasKey('age', $byName);
        $this->assertArrayHasKey('status', $byName);
        $this->assertArrayHasKey('address', $byName);
        $this->assertArrayHasKey('nickname', $byName);
    }

    public function testExtractCapturesIntDefault(): void
    {
        $reflection = new \ReflectionClass(PersonFixture::class);
        $params = $this->extractor->extract($reflection);

        $age = $this->findParam($params, 'age');

        $this->assertTrue($age->hasDefault());
        $this->assertSame(18, $age->getDefaultValue());
        $this->assertFalse($age->isRequired());
    }

    public function testExtractCapturesEnumDefault(): void
    {
        $reflection = new \ReflectionClass(PersonFixture::class);
        $params = $this->extractor->extract($reflection);

        $status = $this->findParam($params, 'status');

        $this->assertTrue($status->hasDefault());
        $this->assertSame(StatusFixture::PENDING, $status->getDefaultValue());
        $this->assertTrue($status->isNested());
        $this->assertSame(StatusFixture::class, $status->getType());
    }

    public function testExtractFlagsNestedObjectParameter(): void
    {
        $reflection = new \ReflectionClass(PersonFixture::class);
        $params = $this->extractor->extract($reflection);

        $address = $this->findParam($params, 'address');

        $this->assertTrue($address->isNested());
        $this->assertSame(AddressFixture::class, $address->getType());
        $this->assertTrue($address->isNullable());
        $this->assertFalse($address->isCollection());
    }

    public function testExtractFlagsRequiredStringParameter(): void
    {
        $reflection = new \ReflectionClass(PersonFixture::class);
        $params = $this->extractor->extract($reflection);

        $name = $this->findParam($params, 'name');

        $this->assertSame('string', $name->getType());
        $this->assertTrue($name->isRequired());
        $this->assertFalse($name->hasDefault());
        $this->assertFalse($name->isNullable());
    }

    public function testExtractNullableStringWithDefault(): void
    {
        $reflection = new \ReflectionClass(PersonFixture::class);
        $params = $this->extractor->extract($reflection);

        $nickname = $this->findParam($params, 'nickname');

        $this->assertSame('string', $nickname->getType());
        $this->assertTrue($nickname->isNullable());
        $this->assertTrue($nickname->hasDefault());
        $this->assertNull($nickname->getDefaultValue());
        $this->assertFalse($nickname->isRequired());
    }

    public function testExtractWitherFixtureHasNoRequiredParams(): void
    {
        $reflection = new \ReflectionClass(WitherFixture::class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(3, $params);

        foreach ($params as $param) {
            $this->assertFalse($param->isRequired(), sprintf(
                'Parameter $%s of WitherFixture should be optional.',
                $param->getName(),
            ));
            $this->assertTrue($param->hasDefault());
        }
    }

    public function testExtractSerializedNameFromAttribute(): void
    {
        $reflection = new \ReflectionClass(AddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $country = $this->findParam($params, 'country');

        // AddressFixture::$country carries #[SerializedName('country_code')]
        $this->assertSame('country_code', $country->getSerializedName());
    }

    public function testExtractSerializedNameDefaultsToParameterName(): void
    {
        $reflection = new \ReflectionClass(AddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $street = $this->findParam($params, 'street');

        $this->assertSame('street', $street->getSerializedName());
    }

    public function testExtractPromotedFlagIsTrueForAllPromotedParams(): void
    {
        $reflection = new \ReflectionClass(PersonFixture::class);
        $params = $this->extractor->extract($reflection);

        foreach ($params as $param) {
            $this->assertTrue($param->isPromoted(), sprintf(
                'Parameter $%s should be flagged as promoted.',
                $param->getName(),
            ));
        }
    }

    public function testExtractHandlesNonPromotedParameter(): void
    {
        // AddressFixture has a non-promoted `$postalCode` parameter
        $reflection = new \ReflectionClass(AddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $postalCode = $this->findParam($params, 'postalCode');

        $this->assertFalse($postalCode->isPromoted());
        $this->assertSame('string', $postalCode->getType());
    }

    public function testExtractReturnsPromotedAndNonPromotedInDeclarationOrder(): void
    {
        $reflection = new \ReflectionClass(AddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $names = array_map(static fn(ConstructorParameterMetadata $p): string => $p->getName(), $params);

        $this->assertSame(['street', 'city', 'country', 'state', 'postalCode'], $names);
    }

    public function testExtractHandlesUnionTypeWithNull(): void
    {
        $class = new class {
            public function __construct(
                public int|string|null $value = null,
            ) {}
        };

        $reflection = new \ReflectionClass($class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isNullable());
        $this->assertTrue($params[0]->hasDefault());
        $this->assertNull($params[0]->getDefaultValue());
    }

    public function testExtractHandlesIntersectionType(): void
    {
        // PHP 8.1 does NOT allow a nullable intersection type
        // (`Countable&Stringable|null`); that syntax was only added in 8.2
        // via DNF types. IntersectionTypeFixture therefore uses a plain,
        // non-nullable intersection which is valid on 8.1 and still exercises
        // the ReflectionIntersectionType branch of the extractor.
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\IntersectionTypeFixture::class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(1, $params);
        // Intersection types must be flagged as nested because the value
        // can only ever be an object that satisfies every listed constraint.
        $this->assertTrue($params[0]->isNested());
        $this->assertFalse($params[0]->isNullable());
    }

    public function testExtractHandlesVariadicParameter(): void
    {
        $class = new class {
            public function __construct(string ...$tags) {}
        };

        $reflection = new \ReflectionClass($class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isVariadic());
        $this->assertSame('tags', $params[0]->getName());
    }

    public function testExtractHandlesArrayTypeAsCollection(): void
    {
        $class = new class {
            /**
             * @param list<string> $tags
             */
            public function __construct(
                public array $tags = [],
            ) {}
        };

        $reflection = new \ReflectionClass($class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isCollection());
        $this->assertSame('array', $params[0]->getType());
    }

    public function testExtractHandlesBoolDefault(): void
    {
        $class = new class {
            public function __construct(
                public bool $active = true,
            ) {}
        };

        $reflection = new \ReflectionClass($class);
        $params = $this->extractor->extract($reflection);

        $this->assertTrue($params[0]->hasDefault());
        $this->assertTrue($params[0]->getDefaultValue());
        $this->assertFalse($params[0]->isRequired());
    }

    public function testExtractHandlesFloatDefault(): void
    {
        $class = new class {
            public function __construct(
                public float $ratio = 1.5,
            ) {}
        };

        $reflection = new \ReflectionClass($class);
        $params = $this->extractor->extract($reflection);

        $this->assertTrue($params[0]->hasDefault());
        $this->assertSame(1.5, $params[0]->getDefaultValue());
    }

    public function testExtractHandlesArrayDefault(): void
    {
        $class = new class {
            public function __construct(
                public array $tags = ['a', 'b'],
            ) {}
        };

        $reflection = new \ReflectionClass($class);
        $params = $this->extractor->extract($reflection);

        $this->assertTrue($params[0]->hasDefault());
        $this->assertSame(['a', 'b'], $params[0]->getDefaultValue());
    }

    public function testExtractCachesNothingBetweenCalls(): void
    {
        // The extractor is stateless; calling extract() twice should produce
        // equivalent but independent arrays.
        $reflection = new \ReflectionClass(SimpleBlog::class);

        $first = $this->extractor->extract($reflection);
        $second = $this->extractor->extract($reflection);

        $this->assertCount(\count($first), $second);

        foreach ($first as $index => $param) {
            $this->assertSame($param->getName(), $second[$index]->getName());
            $this->assertSame($param->getType(), $second[$index]->getType());
        }
    }

    /**
     * @param list<ConstructorParameterMetadata> $params
     */
    private function findParam(array $params, string $name): ConstructorParameterMetadata
    {
        foreach ($params as $param) {
            if ($param->getName() === $name) {
                return $param;
            }
        }

        $this->fail(sprintf('Parameter "%s" not found.', $name));
    }

    public function testNonPromotedParameterInheritsSerializedNameFromProperty(): void
    {
        // NonPromotedAddressFixture declares `$postalCode` as a plain
        // (non-promoted) constructor parameter; the `#[SerializedName]`
        // attribute lives on a same-named public property. The extractor
        // must link the two so the parameter metadata reports the
        // serialized alias.
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $postalCode = $this->findParam($params, 'postalCode');

        $this->assertFalse($postalCode->isPromoted());
        $this->assertSame('postal_code', $postalCode->getSerializedName());
    }

    public function testNonPromotedParameterInheritsGroupsFromProperty(): void
    {
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        foreach (['street', 'city', 'postalCode', 'country'] as $name) {
            $param = $this->findParam($params, $name);

            $this->assertContains(
                'address:read',
                $param->getGroups(),
                sprintf('Parameter $%s should inherit "address:read" from its backing property.', $name),
            );
            $this->assertContains(
                'user:read',
                $param->getGroups(),
                sprintf('Parameter $%s should inherit "user:read" from its backing property.', $name),
            );
        }
    }

    public function testNonPromotedParameterInheritsIgnoreFromProperty(): void
    {
        // `$internalCode` is optional, non-promoted, and its backing
        // property carries `#[Ignore]`. The extractor must surface the
        // ignored flag on the linked parameter metadata.
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $internalCode = $this->findParam($params, 'internalCode');

        $this->assertFalse($internalCode->isPromoted());
        $this->assertTrue($internalCode->isIgnored());
    }

    public function testNonPromotedParameterInheritsMaxDepthFromProperty(): void
    {
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $country = $this->findParam($params, 'country');

        $this->assertSame(2, $country->getMaxDepth());
    }

    public function testNonPromotedParameterInheritsContextFromProperty(): void
    {
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $street = $this->findParam($params, 'street');

        $this->assertTrue($street->hasContexts());
        $contexts = $street->getContexts();
        $this->assertCount(1, $contexts);

        $context = $contexts[0]->getContext();
        $this->assertArrayHasKey('trim', $context);
        $this->assertTrue($context['trim']);
    }

    public function testParameterWithoutBackingPropertyGetsEmptyAttributeData(): void
    {
        // A plain class with a non-promoted constructor whose parameters
        // have NO matching public properties must still extract cleanly:
        // every attribute-derived field defaults to its empty sentinel.
        $class = new class('x') {
            public function __construct(string $value) {}
        };

        $reflection = new \ReflectionClass($class);
        $params = $this->extractor->extract($reflection);

        $this->assertCount(1, $params);
        $value = $params[0];

        $this->assertSame('value', $value->getName());
        $this->assertSame('value', $value->getSerializedName());
        $this->assertSame([], $value->getGroups());
        $this->assertFalse($value->isIgnored());
        $this->assertNull($value->getMaxDepth());
        $this->assertSame([], $value->getContexts());
    }

    public function testParameterAttributesOverrideBackingPropertyNothing(): void
    {
        // When only the property carries the attribute, the parameter
        // inherits it. This test asserts the inverse: the parameter's
        // own attributes (which cannot be #[SerializedName] etc. because
        // those attributes disallow TARGET_PARAMETER, but CAN include
        // safely-ignored ones) do not erase property-derived values.
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $postalCode = $this->findParam($params, 'postalCode');

        // Property-derived attributes must survive the final merge.
        $this->assertSame('postal_code', $postalCode->getSerializedName());
        $this->assertContains('address:read', $postalCode->getGroups());
    }

    public function testPromotedParameterStillHasLinkedPropertyAttributes(): void
    {
        // Sanity check the existing promoted-parameter path still works
        // through the same attribute-linking pipeline: AddressFixture has
        // a promoted `#[SerializedName('country_code')] $country` and the
        // extractor must continue to surface the alias verbatim.
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\AddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $country = $this->findParam($params, 'country');

        $this->assertTrue($country->isPromoted());
        $this->assertSame('country_code', $country->getSerializedName());
    }

    public function testNonPromotedRequiredParameterRemainsRequiredRegardlessOfLinking(): void
    {
        // Linking attribute metadata to the parameter must NOT alter the
        // reflection-derived "required" flag: `$postalCode` has no default
        // and is not nullable, so it stays required even after inheriting
        // a serialized-name alias from its backing property.
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $postalCode = $this->findParam($params, 'postalCode');

        $this->assertTrue($postalCode->isRequired());
        $this->assertFalse($postalCode->hasDefault());
        $this->assertFalse($postalCode->isNullable());
    }

    public function testNonPromotedOptionalParameterKeepsDefaultAfterLinking(): void
    {
        // `$internalCode` is optional (nullable, default null) AND its
        // backing property carries `#[Ignore]`. Both pieces of metadata
        // must survive together.
        $reflection =
            new \ReflectionClass(\RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture::class);
        $params = $this->extractor->extract($reflection);

        $internalCode = $this->findParam($params, 'internalCode');

        $this->assertTrue($internalCode->isNullable());
        $this->assertTrue($internalCode->hasDefault());
        $this->assertNull($internalCode->getDefaultValue());
        $this->assertTrue($internalCode->isIgnored());
    }
}
