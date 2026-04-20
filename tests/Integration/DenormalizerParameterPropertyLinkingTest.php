<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NonPromotedAddressFixture;

/**
 * Integration tests for the parameter-to-property attribute-linking feature
 * of the denormalizer generator.
 *
 * When a class declares a constructor whose parameters are NOT promoted but
 * whose names match existing public class properties, the generator must
 * honour serializer attributes (`#[SerializedName]`, `#[Groups]`,
 * `#[Ignore]`, `#[MaxDepth]`, `#[Context]`) placed on those properties as if
 * they had been declared on the constructor parameters themselves.
 *
 * This mirrors the common Symfony convention of declaring all attributes on
 * the property side and keeping the constructor signature purely
 * positional, which is typical in domain models such as:
 *
 *   ```php
 *   final class Address
 *   {
 *       #[SerializedName('postal_code')]
 *       public string $postalCode;
 *
 *       public function __construct(string $postalCode)
 *       {
 *           $this->postalCode = $postalCode;
 *       }
 *   }
 *   ```
 *
 * The tests in this class prove that:
 *
 *   1. The generated `construct()` method looks up the canonical
 *      (serialized) alias in the payload, falling back to the PHP
 *      parameter name via the chained `extract*` pattern.
 *   2. The generated `populate()` method accepts either alias via its
 *      OR-chain of `array_key_exists` checks.
 *   3. Errors produced by the extractor chain quote the canonical
 *      (serialized) key so API consumers receive stable diagnostics.
 *   4. The fixture's class-level metadata (groups, ignored, max-depth,
 *      contexts) is propagated to the corresponding
 *      {@see \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorParameterMetadata}
 *      instance and therefore influences generator decisions that depend on
 *      those attributes.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorMetadataExtractor
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorParameterMetadata
 */
final class DenormalizerParameterPropertyLinkingTest extends AbstractTestCase
{
    private string $tempDir;

    /** The instantiated generated denormalizer for NonPromotedAddressFixture. */
    private object $denormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $this->denormalizer = $this->loadDenormalizerFor(NonPromotedAddressFixture::class, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testCanonicalSerializedNameIsAcceptedForNonPromotedParameter(): void
    {
        // `$postalCode` is a plain (non-promoted) constructor parameter, but
        // the same-named class property carries `#[SerializedName('postal_code')]`.
        // The generator must link the two so a payload using the serialized
        // alias populates the constructor argument.
        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize([
            'street' => 'Main St',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ], NonPromotedAddressFixture::class);

        $this->assertSame('10115', $result->postalCode);
    }

    public function testPhpNameFallbackIsAcceptedForNonPromotedParameter(): void
    {
        // The same parameter must ALSO accept a payload keyed by its raw
        // PHP name so callers are not forced to choose between the two
        // conventions — the chained-call fallback looks up `postalCode`
        // when the canonical `postal_code` is absent.
        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize([
            'street' => 'Main St',
            'city' => 'Berlin',
            'postalCode' => '10115',
            'country' => 'DE',
        ], NonPromotedAddressFixture::class);

        $this->assertSame('10115', $result->postalCode);
    }

    public function testCanonicalAliasWinsWhenBothKeysAreSupplied(): void
    {
        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize([
            'street' => 'Main St',
            'city' => 'Berlin',
            'postal_code' => 'canonical',
            'postalCode' => 'php-loses',
            'country' => 'DE',
        ], NonPromotedAddressFixture::class);

        $this->assertSame('canonical', $result->postalCode);
    }

    public function testUnaliasedFieldsStillPopulateFromPhpName(): void
    {
        // The other three required parameters (`street`, `city`, `country`)
        // have no `#[SerializedName]` attribute on their backing properties.
        // Their only valid lookup key is the PHP parameter name, exactly as
        // for a class without any attribute linking at all.
        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize([
            'street' => 'Kaiserdamm',
            'city' => 'Berlin',
            'postal_code' => '14057',
            'country' => 'DE',
        ], NonPromotedAddressFixture::class);

        $this->assertSame('Kaiserdamm', $result->street);
        $this->assertSame('Berlin', $result->city);
        $this->assertSame('DE', $result->country);
    }

    public function testMissingBothAliasesProducesCanonicalRequiredError(): void
    {
        try {
            $this->denormalizer->denormalize([
                'street' => 'Main St',
                'city' => 'Berlin',
                'country' => 'DE',
            ], NonPromotedAddressFixture::class);
            $this->fail('Expected MissingRequiredFieldException.');
        } catch (MissingRequiredFieldException $e) {
            // The error must quote the canonical (serialized) alias even
            // though the attribute lives on the property, not the
            // parameter.
            $this->assertSame('postal_code', $e->getFieldName());
            $this->assertStringContainsString('postal_code', $e->getMessage());
        }
    }

    public function testFullPayloadWithCanonicalAliasRoundTrips(): void
    {
        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize([
            'street' => '1 Market',
            'city' => 'San Francisco',
            'postal_code' => '94105',
            'country' => 'US',
        ], NonPromotedAddressFixture::class);

        $this->assertSame('1 Market', $result->street);
        $this->assertSame('San Francisco', $result->city);
        $this->assertSame('94105', $result->postalCode);
        $this->assertSame('US', $result->country);
    }

    public function testFullPayloadWithPhpNameFallbackRoundTrips(): void
    {
        // Same assertions as above, but every aliased field is addressed
        // via its PHP-name fallback. The end state must be indistinguishable
        // from the canonical-alias case — linking attribute metadata to the
        // parameter ensures the two payload shapes are interchangeable.
        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize([
            'street' => '1 Market',
            'city' => 'San Francisco',
            'postalCode' => '94105',
            'country' => 'US',
        ], NonPromotedAddressFixture::class);

        $this->assertSame('1 Market', $result->street);
        $this->assertSame('San Francisco', $result->city);
        $this->assertSame('94105', $result->postalCode);
        $this->assertSame('US', $result->country);
    }

    public function testGroupsFromPropertyReachConstructorParameterMetadata(): void
    {
        // `#[Groups(['address:read', 'user:read'])]` is placed on every
        // public property. The constructor-metadata extractor must surface
        // those groups on the linked constructor-parameter metadata so the
        // generator can make group-aware decisions without forcing callers
        // to repeat the attribute on the parameter.
        $metadata = $this
            ->makeDenormalizerGenerator()
            ->getMetadataFactory()
            ->getMetadataFor(NonPromotedAddressFixture::class);

        $byName = [];
        foreach ($metadata->getConstructorParameters() as $param) {
            $byName[$param->getName()] = $param;
        }

        foreach (['street', 'city', 'postalCode', 'country'] as $paramName) {
            $this->assertArrayHasKey($paramName, $byName);
            $this->assertContains(
                'address:read',
                $byName[$paramName]->getGroups(),
                sprintf('Parameter $%s should inherit "address:read" from its linked property.', $paramName),
            );
            $this->assertContains(
                'user:read',
                $byName[$paramName]->getGroups(),
                sprintf('Parameter $%s should inherit "user:read" from its linked property.', $paramName),
            );
        }
    }

    public function testSerializedNameFromPropertyReachesConstructorParameterMetadata(): void
    {
        $metadata = $this
            ->makeDenormalizerGenerator()
            ->getMetadataFactory()
            ->getMetadataFor(NonPromotedAddressFixture::class);

        $postalCode = null;
        foreach ($metadata->getConstructorParameters() as $param) {
            if ($param->getName() === 'postalCode') {
                $postalCode = $param;
                break;
            }
        }

        $this->assertNotNull($postalCode, 'postalCode parameter metadata not found.');
        $this->assertSame('postal_code', $postalCode->getSerializedName());
    }

    public function testIgnoreFromPropertyReachesConstructorParameterMetadata(): void
    {
        // The fixture's `$internalCode` property carries `#[Ignore]` but
        // the constructor parameter does not — the extractor must link
        // the two and surface the ignored flag on the parameter metadata.
        $metadata = $this
            ->makeDenormalizerGenerator()
            ->getMetadataFactory()
            ->getMetadataFor(NonPromotedAddressFixture::class);

        $internalCode = null;
        foreach ($metadata->getConstructorParameters() as $param) {
            if ($param->getName() === 'internalCode') {
                $internalCode = $param;
                break;
            }
        }

        $this->assertNotNull($internalCode, 'internalCode parameter metadata not found.');
        $this->assertTrue(
            $internalCode->isIgnored(),
            '#[Ignore] on the backing property must propagate to the linked constructor parameter.',
        );
    }

    public function testMaxDepthFromPropertyReachesConstructorParameterMetadata(): void
    {
        $metadata = $this
            ->makeDenormalizerGenerator()
            ->getMetadataFactory()
            ->getMetadataFor(NonPromotedAddressFixture::class);

        $country = null;
        foreach ($metadata->getConstructorParameters() as $param) {
            if ($param->getName() === 'country') {
                $country = $param;
                break;
            }
        }

        $this->assertNotNull($country, 'country parameter metadata not found.');
        $this->assertSame(
            2,
            $country->getMaxDepth(),
            '#[MaxDepth(2)] on the backing property must propagate to the linked constructor parameter.',
        );
    }

    public function testContextFromPropertyReachesConstructorParameterMetadata(): void
    {
        $metadata = $this
            ->makeDenormalizerGenerator()
            ->getMetadataFactory()
            ->getMetadataFor(NonPromotedAddressFixture::class);

        $street = null;
        foreach ($metadata->getConstructorParameters() as $param) {
            if ($param->getName() === 'street') {
                $street = $param;
                break;
            }
        }

        $this->assertNotNull($street, 'street parameter metadata not found.');
        $this->assertTrue(
            $street->hasContexts(),
            '#[Context] on the backing property must propagate to the linked constructor parameter.',
        );

        $contexts = $street->getContexts();
        $this->assertNotEmpty($contexts);

        // Unpack the first context and verify its "trim" flag survived.
        $first = $contexts[0];
        $context = $first->getContext();
        $this->assertArrayHasKey('trim', $context);
        $this->assertTrue($context['trim']);
    }

    public function testUnaliasedParametersRetainTheirPhpNameAsSerializedName(): void
    {
        // The three parameters without a property-level `#[SerializedName]`
        // must fall back to their PHP names, proving that the linking logic
        // does not over-reach and invent aliases where none were declared.
        $metadata = $this
            ->makeDenormalizerGenerator()
            ->getMetadataFactory()
            ->getMetadataFor(NonPromotedAddressFixture::class);

        $byName = [];
        foreach ($metadata->getConstructorParameters() as $param) {
            $byName[$param->getName()] = $param;
        }

        $this->assertSame('street', $byName['street']->getSerializedName());
        $this->assertSame('city', $byName['city']->getSerializedName());
        $this->assertSame('country', $byName['country']->getSerializedName());
    }

    public function testUnaliasedParametersAreNotMarkedIgnored(): void
    {
        $metadata = $this
            ->makeDenormalizerGenerator()
            ->getMetadataFactory()
            ->getMetadataFor(NonPromotedAddressFixture::class);

        foreach ($metadata->getConstructorParameters() as $param) {
            if ($param->getName() === 'internalCode') {
                continue;
            }

            $this->assertFalse($param->isIgnored(), sprintf(
                'Parameter $%s has no #[Ignore] on its backing property and must not be marked ignored.',
                $param->getName(),
            ));
        }
    }

    public function testPopulatePhaseAcceptsCanonicalAliasWhenObjectToPopulateIsSet(): void
    {
        $existing = new NonPromotedAddressFixture('seed-street', 'seed-city', 'seed-zip', 'seed-country');

        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize(
            ['postal_code' => 'updated-via-alias'],
            NonPromotedAddressFixture::class,
            null,
            [\Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        $this->assertSame($existing, $result);
        $this->assertSame('updated-via-alias', $result->postalCode);
    }

    public function testPopulatePhaseAcceptsPhpNameFallbackWhenObjectToPopulateIsSet(): void
    {
        $existing = new NonPromotedAddressFixture('seed-street', 'seed-city', 'seed-zip', 'seed-country');

        /** @var NonPromotedAddressFixture $result */
        $result = $this->denormalizer->denormalize(
            ['postalCode' => 'updated-via-php'],
            NonPromotedAddressFixture::class,
            null,
            [\Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing],
        );

        $this->assertSame($existing, $result);
        $this->assertSame('updated-via-php', $result->postalCode);
    }
}
