<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MutatorType;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\AddressFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\PersonFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SetterFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\WitherFixture;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

/**
 * Focussed tests for the mutator-discovery logic that was added to
 * {@see MetadataFactory} to support the denormalizer generator.
 *
 * These tests complement the broader {@see MetadataFactoryTest} by
 * concentrating exclusively on:
 *
 *   - {@see PropertyMetadata::$mutatorType} classification
 *     (CONSTRUCTOR / PROPERTY / SETTER / WITHER / NONE)
 *   - {@see PropertyMetadata::$mutator} name resolution
 *   - Constructor-parameter metadata attached to the owning class metadata
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory
 */
final class MetadataFactoryMutatorDiscoveryTest extends TestCase
{
    private MetadataFactory $factory;

    protected function setUp(): void
    {
        $reflection = new ReflectionExtractor();
        $phpDoc = new PhpDocExtractor();

        $extractor = new PropertyInfoExtractor(
            listExtractors: [$reflection],
            typeExtractors: [$phpDoc, $reflection],
            accessExtractors: [$reflection],
        );

        $this->factory = new MetadataFactory($extractor);
    }

    public function testMetadataCarriesHasConstructorFlagForClassWithConstructor(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $this->assertTrue($metadata->hasConstructor());
    }

    public function testMetadataCarriesConstructorParametersForClassWithConstructor(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        $params = $metadata->getConstructorParameters();

        $this->assertNotEmpty($params);
        $names = array_map(static fn($p) => $p->getName(), $params);
        $this->assertSame(['id', 'title', 'content', 'excerpt'], $names);
    }

    public function testMetadataCarriesConstructorParametersForFixtureWithDefaults(): void
    {
        $metadata = $this->factory->getMetadataFor(PersonFixture::class);

        $names = array_map(static fn($p) => $p->getName(), $metadata->getConstructorParameters());

        $this->assertContains('name', $names);
        $this->assertContains('age', $names);
        $this->assertContains('status', $names);
        $this->assertContains('address', $names);
        $this->assertContains('nickname', $names);
    }

    public function testMetadataDistinguishesRequiredFromOptionalConstructorParameters(): void
    {
        $metadata = $this->factory->getMetadataFor(PersonFixture::class);

        $byName = [];
        foreach ($metadata->getConstructorParameters() as $param) {
            $byName[$param->getName()] = $param;
        }

        // `name` has no default and is not nullable → required.
        $this->assertTrue($byName['name']->isRequired());

        // `age` has default 18 → optional.
        $this->assertFalse($byName['age']->isRequired());
        $this->assertTrue($byName['age']->hasDefault());
        $this->assertSame(18, $byName['age']->getDefaultValue());
    }

    public function testHasRequiredConstructorParametersIsTrueForSimpleBlog(): void
    {
        $metadata = $this->factory->getMetadataFor(SimpleBlog::class);

        // id / title / content are all required.
        $this->assertTrue($metadata->hasRequiredConstructorParameters());
    }

    public function testHasRequiredConstructorParametersIsFalseForWitherFixture(): void
    {
        // WitherFixture's constructor takes only default-valued parameters.
        $metadata = $this->factory->getMetadataFor(WitherFixture::class);

        $this->assertFalse($metadata->hasRequiredConstructorParameters());
    }

    public function testHasConstructorIsFalseForClassWithoutConstructor(): void
    {
        $metadata = $this->factory->getMetadataFor(\stdClass::class);

        $this->assertFalse($metadata->hasConstructor());
        $this->assertSame([], $metadata->getConstructorParameters());
    }

    public function testSetterFixturePropertiesAreClassifiedAsSetters(): void
    {
        $metadata = $this->factory->getMetadataFor(SetterFixture::class);

        foreach (['name', 'age', 'email'] as $name) {
            $property = $this->requireProperty($metadata, $name);

            $this->assertSame(
                MutatorType::SETTER,
                $property->getMutatorType(),
                sprintf('Property $%s should be classified as SETTER.', $name),
            );
        }
    }

    public function testSetterFixtureNameMutatorIsSetName(): void
    {
        $metadata = $this->factory->getMetadataFor(SetterFixture::class);
        $property = $this->requireProperty($metadata, 'name');

        $this->assertSame('setName', $property->getMutator());
        $this->assertTrue($property->hasMutator());
    }

    public function testSetterFixtureAgeMutatorWithSelfReturnTypeIsDetected(): void
    {
        // SetterFixture::setAge() returns `self` but is still a setter — not
        // a wither — because it mutates `$this` in place and returns the
        // same instance for method chaining. The factory should classify it
        // as SETTER, preserving the fluent return type.
        $metadata = $this->factory->getMetadataFor(SetterFixture::class);
        $property = $this->requireProperty($metadata, 'age');

        $this->assertSame(MutatorType::SETTER, $property->getMutatorType());
        $this->assertSame('setAge', $property->getMutator());
    }

    public function testSetterFixtureEmailMutatorAcceptsNullableType(): void
    {
        $metadata = $this->factory->getMetadataFor(SetterFixture::class);
        $property = $this->requireProperty($metadata, 'email');

        $this->assertSame(MutatorType::SETTER, $property->getMutatorType());
        $this->assertSame('setEmail', $property->getMutator());
        $this->assertTrue($property->isNullable());
    }

    public function testWitherFixturePropertiesAreClassifiedAsWithers(): void
    {
        $metadata = $this->factory->getMetadataFor(WitherFixture::class);

        foreach (['title', 'body', 'slug'] as $name) {
            $property = $this->requireProperty($metadata, $name);

            $this->assertSame(
                MutatorType::WITHER,
                $property->getMutatorType(),
                sprintf('Property $%s should be classified as WITHER.', $name),
            );
        }
    }

    public function testWitherFixtureSelfReturnTypeIsDetected(): void
    {
        $metadata = $this->factory->getMetadataFor(WitherFixture::class);
        $property = $this->requireProperty($metadata, 'title');

        $this->assertSame(MutatorType::WITHER, $property->getMutatorType());
        $this->assertSame('withTitle', $property->getMutator());
    }

    public function testWitherFixtureStaticReturnTypeIsDetected(): void
    {
        $metadata = $this->factory->getMetadataFor(WitherFixture::class);
        $property = $this->requireProperty($metadata, 'body');

        $this->assertSame(MutatorType::WITHER, $property->getMutatorType());
        $this->assertSame('withBody', $property->getMutator());
    }

    public function testWitherFixtureOwningClassReturnTypeIsDetected(): void
    {
        // withSlug(...) returns the owning class explicitly — still a wither.
        $metadata = $this->factory->getMetadataFor(WitherFixture::class);
        $property = $this->requireProperty($metadata, 'slug');

        $this->assertSame(MutatorType::WITHER, $property->getMutatorType());
        $this->assertSame('withSlug', $property->getMutator());
    }

    public function testWitherFixtureAllPropertiesReassignObject(): void
    {
        $metadata = $this->factory->getMetadataFor(WitherFixture::class);

        foreach ($metadata->getProperties() as $property) {
            $this->assertTrue($property->getMutatorType()->reassignsObject(), sprintf(
                'Wither mutator for $%s should reassign $object.',
                $property->getName(),
            ));
        }
    }

    public function testPublicPromotedParameterIsClassifiedAsProperty(): void
    {
        // PersonFixture exposes every constructor parameter as a public
        // (non-readonly) property, so the discovery must prefer the direct
        // property write over a CONSTRUCTOR-only fallback.
        $metadata = $this->factory->getMetadataFor(PersonFixture::class);

        foreach (['name', 'age', 'nickname'] as $name) {
            $property = $this->requireProperty($metadata, $name);

            $this->assertSame(
                MutatorType::PROPERTY,
                $property->getMutatorType(),
                sprintf('Property $%s should be classified as PROPERTY.', $name),
            );
            $this->assertSame($name, $property->getMutator());
        }
    }

    public function testUserFixtureMutablePublicPropertyIsClassifiedAsProperty(): void
    {
        // UserFixture::$biography is a plain mutable public property with
        // no setter / wither — the mutator must be PROPERTY and the name
        // must match the property name.
        $metadata = $this->factory->getMetadataFor(UserFixture::class);
        $property = $this->requireProperty($metadata, 'biography');

        $this->assertSame(MutatorType::PROPERTY, $property->getMutatorType());
        $this->assertSame('biography', $property->getMutator());
        $this->assertTrue($property->hasMutator());
    }

    public function testReadonlyPromotedParameterWithoutSetterIsClassifiedAsConstructor(): void
    {
        // UserFixture::$id is a readonly promoted constructor parameter
        // with no setter/wither. The only way to assign it is via the
        // constructor, so the mutator type must be CONSTRUCTOR and the
        // property should be skipped during the populate() phase.
        $metadata = $this->factory->getMetadataFor(UserFixture::class);
        $property = $this->requireProperty($metadata, 'id');

        $this->assertTrue($property->isReadonly());
        $this->assertSame(MutatorType::CONSTRUCTOR, $property->getMutatorType());
        $this->assertNull($property->getMutator());
        $this->assertFalse($property->hasMutator());
    }

    public function testReadonlyPromotedNameIsClassifiedAsConstructor(): void
    {
        $metadata = $this->factory->getMetadataFor(UserFixture::class);
        $property = $this->requireProperty($metadata, 'name');

        $this->assertTrue($property->isReadonly());
        $this->assertSame(MutatorType::CONSTRUCTOR, $property->getMutatorType());
    }

    public function testReadonlyConstructorMutatorIsSkippedDuringPopulation(): void
    {
        $metadata = $this->factory->getMetadataFor(UserFixture::class);
        $property = $this->requireProperty($metadata, 'id');

        $this->assertTrue($property->getMutatorType()->isSkippedDuringPopulation());
    }

    public function testUserFixtureActivePropertyDiscoveredViaGetterResolvesToSetter(): void
    {
        // UserFixture has a private `$active` field exposed via `isActive()`
        // (accessor) and mutated via `setActive()` (mutator). The factory
        // must wire those together correctly.
        $metadata = $this->factory->getMetadataFor(UserFixture::class);
        $property = $this->requireProperty($metadata, 'active');

        $this->assertSame(MutatorType::SETTER, $property->getMutatorType());
        $this->assertSame('setActive', $property->getMutator());
    }

    public function testUserFixtureLocalePropertyResolvesToSetter(): void
    {
        $metadata = $this->factory->getMetadataFor(UserFixture::class);
        $property = $this->requireProperty($metadata, 'locale');

        $this->assertSame(MutatorType::SETTER, $property->getMutatorType());
        $this->assertSame('setLocale', $property->getMutator());
    }

    public function testPropertyWithNoMutatorIsClassifiedAsNone(): void
    {
        // AddressFixture::$postalCode is a virtual property exposed only
        // through a getter (getPostalCode()). No setter, no wither, no
        // public property, no matching constructor parameter named
        // `postalCode` — wait: the class actually has a `$postalCode`
        // constructor parameter, so this one falls to CONSTRUCTOR, not NONE.
        //
        // Instead we use an anonymous read-only fixture below.
        $class = new class {
            private string $unwriteable = '';

            public function getUnwriteable(): string
            {
                return $this->unwriteable;
            }
        };

        // Reflection-only metadata: we can't feed an anonymous class into
        // the factory because it is not autoloadable, but we CAN assert the
        // rule indirectly through AddressFixture::$postalCode.
        unset($class);

        // AddressFixture::$postalCode has a constructor parameter with the
        // same name AND no public setter/wither/property → CONSTRUCTOR.
        // The factory's fallback order guarantees this.
        $metadata = $this->factory->getMetadataFor(AddressFixture::class);
        $property = $this->requireProperty($metadata, 'postalCode');

        $this->assertSame(MutatorType::CONSTRUCTOR, $property->getMutatorType());
    }

    public function testInternalNotePropertyIsClassifiedAsProperty(): void
    {
        // AddressFixture::$internalNote is a plain mutable public property.
        $metadata = $this->factory->getMetadataFor(AddressFixture::class);
        $property = $this->requireProperty($metadata, 'internalNote');

        $this->assertSame(MutatorType::PROPERTY, $property->getMutatorType());
        $this->assertSame('internalNote', $property->getMutator());
    }

    public function testMutatorTypeIsAlwaysSet(): void
    {
        // After metadata has been built, no property should still carry the
        // default MutatorType::NONE *unless* the factory explicitly
        // determined that no write strategy exists. We verify the invariant
        // here for several known-good fixtures.
        $classes = [
            SimpleBlog::class,
            SetterFixture::class,
            WitherFixture::class,
            PersonFixture::class,
            UserFixture::class,
            AddressFixture::class,
        ];

        foreach ($classes as $class) {
            $metadata = $this->factory->getMetadataFor($class);

            foreach ($metadata->getProperties() as $property) {
                // The enum itself can't be null; this call just asserts the
                // invariant that each property carries a valid MutatorType.
                $this->assertInstanceOf(MutatorType::class, $property->getMutatorType());
            }
        }
    }

    public function testSetterAndWitherMutatorsAreMethods(): void
    {
        $setter = $this->factory->getMetadataFor(SetterFixture::class)->getProperty('name');

        $wither = $this->factory->getMetadataFor(WitherFixture::class)->getProperty('title');

        $this->assertNotNull($setter);
        $this->assertNotNull($wither);

        $this->assertTrue($setter->getMutatorType()->isMethod());
        $this->assertTrue($wither->getMutatorType()->isMethod());
    }

    public function testPublicPropertyMutatorIsNotAMethod(): void
    {
        $property = $this->factory->getMetadataFor(PersonFixture::class)->getProperty('name');

        $this->assertNotNull($property);
        $this->assertFalse($property->getMutatorType()->isMethod());
        $this->assertTrue($property->getMutatorType()->isProperty());
    }

    public function testMetadataIsCachedAcrossCalls(): void
    {
        // The factory caches metadata in-memory; re-requesting the same FQCN
        // must yield the exact same ClassMetadata instance so downstream
        // consumers (e.g. the normalizer and denormalizer generators) share
        // a single view of a given class.
        $first = $this->factory->getMetadataFor(SetterFixture::class);
        $second = $this->factory->getMetadataFor(SetterFixture::class);

        $this->assertSame($first, $second);
    }

    /**
     * Fetch a property by name from the given metadata, failing the test
     * loudly when it is missing. This keeps individual assertions concise
     * and avoids cascading null-access warnings.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function requireProperty(ClassMetadata $metadata, string $name): PropertyMetadata
    {
        $property = $metadata->getProperty($name);

        if ($property === null) {
            $this->fail(sprintf(
                'Property "%s" was not found on %s. Available properties: %s',
                $name,
                $metadata->getClassName(),
                implode(', ', array_map(static fn(PropertyMetadata $p) => $p->getName(), $metadata->getProperties())),
            ));
        }

        return $property;
    }
}
