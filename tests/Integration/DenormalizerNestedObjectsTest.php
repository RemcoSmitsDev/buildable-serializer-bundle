<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\AddressFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\PersonFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\StatusFixture;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Integration tests for nested-object delegation via the
 * {@see \Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface}
 * wiring.
 *
 * These tests prove that the generated denormalizer correctly forwards
 * non-scalar values (nested objects, enums, DateTime, collections of objects)
 * to whatever {@see DenormalizerInterface} has been injected via
 * `setDenormalizer()`. The injected denormalizer can be:
 *
 *   - A full Symfony {@see Serializer} chain that mixes built-in normalizers
 *     ({@see BackedEnumNormalizer}, {@see ObjectNormalizer}) with other
 *     generated denormalizers — mirroring how the bundle wires things up in
 *     production via the compiler pass.
 *   - A mock that captures the delegation contract without pulling in the
 *     real serializer — useful for asserting argument shape.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\ObjectExtractorTrait
 */
final class DenormalizerNestedObjectsTest extends AbstractTestCase
{
    private string $tempDir;

    /** The instantiated generated denormalizer for PersonFixture. */
    private DenormalizerInterface $denormalizer;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $this->denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testNestedEnumIsDelegatedToBackedEnumNormalizer(): void
    {
        // Wire a minimal chain: the generated denormalizer first, then the
        // built-in BackedEnumNormalizer so 'pending' → StatusFixture::PENDING.
        $this->wireSerializerChain([new BackedEnumNormalizer()]);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize(['name' => 'Alice', 'status' => 'active'], PersonFixture::class);

        $this->assertSame(StatusFixture::ACTIVE, $result->status);
    }

    public function testNestedEnumIsDelegatedForEveryCase(): void
    {
        $this->wireSerializerChain([new BackedEnumNormalizer()]);

        foreach ([
            'pending' => StatusFixture::PENDING,
            'active' => StatusFixture::ACTIVE,
            'archived' => StatusFixture::ARCHIVED,
        ] as $raw => $expected) {
            /** @var PersonFixture $result */
            $result = $this->denormalizer->denormalize(['name' => 'Alice', 'status' => $raw], PersonFixture::class);

            $this->assertSame(
                $expected,
                $result->status,
                sprintf('Raw value "%s" should denormalize to %s.', $raw, $expected->name),
            );
        }
    }

    public function testNestedObjectIsDelegatedToObjectNormalizer(): void
    {
        // ObjectNormalizer can handle the AddressFixture shape (scalar
        // constructor parameters + a public property). The generated
        // PersonDenormalizer only needs to forward the raw address array.
        //
        // AddressFixture::$country carries `#[SerializedName('country_code')]`,
        // so the inner ObjectNormalizer must be paired with a metadata-aware
        // name converter or the constructor call will fail to find `$country`.
        $this->wireSerializerChain([
            new BackedEnumNormalizer(),
            $this->makeAttributeAwareObjectNormalizer(),
        ]);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize([
            'name' => 'Alice',
            'address' => [
                'street' => 'Main St 1',
                'city' => 'Berlin',
                'country_code' => 'DE',
            ],
        ], PersonFixture::class);

        $this->assertInstanceOf(AddressFixture::class, $result->address);
        $this->assertSame('Main St 1', $result->address->street);
        $this->assertSame('Berlin', $result->address->city);
        $this->assertSame('DE', $result->address->country);
    }

    public function testNestedObjectDefaultsToNullWhenAbsent(): void
    {
        // When `address` is missing from the payload, the default (null)
        // applies and the chain must NOT be consulted — we therefore pass
        // an intentionally broken chain to prove the short-circuit path.
        $this->wireSerializerChain([
            new class implements DenormalizerInterface {
                public function denormalize(
                    mixed $data,
                    string $type,
                    ?string $format = null,
                    array $context = [],
                ): never {
                    throw new \LogicException('Chain must not be invoked when the field is absent.');
                }

                public function supportsDenormalization(
                    mixed $data,
                    string $type,
                    ?string $format = null,
                    array $context = [],
                ): bool {
                    return true;
                }

                public function getSupportedTypes(?string $format): array
                {
                    return ['*' => true];
                }
            },
        ]);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize(['name' => 'Alice'], PersonFixture::class);

        $this->assertNull($result->address);
    }

    public function testNestedObjectAcceptsExplicitNull(): void
    {
        $this->wireSerializerChain([new BackedEnumNormalizer(), new ObjectNormalizer()]);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize(['name' => 'Alice', 'address' => null], PersonFixture::class);

        $this->assertNull($result->address);
    }

    public function testFullPayloadRoundTripsThroughChain(): void
    {
        $this->wireSerializerChain([
            new BackedEnumNormalizer(),
            $this->makeAttributeAwareObjectNormalizer(),
        ]);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize([
            'name' => 'Alice',
            'age' => 30,
            'status' => 'active',
            'nickname' => 'Al',
            'address' => [
                'street' => '1 Main',
                'city' => 'Portland',
                'country_code' => 'US',
                'state' => 'OR',
            ],
        ], PersonFixture::class);

        $this->assertSame('Alice', $result->name);
        $this->assertSame(30, $result->age);
        $this->assertSame(StatusFixture::ACTIVE, $result->status);
        $this->assertSame('Al', $result->nickname);
        $this->assertInstanceOf(AddressFixture::class, $result->address);
        $this->assertSame('1 Main', $result->address->street);
        $this->assertSame('Portland', $result->address->city);
        $this->assertSame('US', $result->address->country);
        $this->assertSame('OR', $result->address->state);
    }

    public function testAlreadyInstantiatedNestedObjectShortCircuitsTheChain(): void
    {
        // If the payload ALREADY contains an instance of the expected type,
        // the extractObject helper must reuse it without dispatching to the
        // chain — we verify this by installing a throwing chain.
        $this->wireSerializerChain([
            new class implements DenormalizerInterface {
                public function denormalize(
                    mixed $data,
                    string $type,
                    ?string $format = null,
                    array $context = [],
                ): never {
                    throw new \LogicException('Chain must not be invoked for already-instantiated values.');
                }

                public function supportsDenormalization(
                    mixed $data,
                    string $type,
                    ?string $format = null,
                    array $context = [],
                ): bool {
                    return true;
                }

                public function getSupportedTypes(?string $format): array
                {
                    return ['*' => true];
                }
            },
        ]);

        $preExisting = new AddressFixture('1 Main', 'NY', 'US');

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize([
            'name' => 'Alice',
            'address' => $preExisting,
        ], PersonFixture::class);

        $this->assertSame($preExisting, $result->address);
    }

    public function testMockChainReceivesEnumValueVerbatim(): void
    {
        $mock = $this->createMock(DenormalizerInterface::class);
        $mock->method('denormalize')->willReturnCallback(static function (mixed $data, string $type): mixed {
            if ($type === StatusFixture::class) {
                return StatusFixture::from((string) $data);
            }

            $this->fail(sprintf('Unexpected delegate call for type "%s".', $type));
        });

        $this->denormalizer->setDenormalizer($mock);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize(['name' => 'Alice', 'status' => 'archived'], PersonFixture::class);

        $this->assertSame(StatusFixture::ARCHIVED, $result->status);
    }

    public function testMockChainReceivesNestedArrayVerbatim(): void
    {
        $capturedData = null;

        $mock = $this->createMock(DenormalizerInterface::class);
        $mock->method('denormalize')->willReturnCallback(function (mixed $data, string $type) use (
            &$capturedData,
        ): mixed {
            if ($type === AddressFixture::class) {
                $capturedData = $data;

                return new AddressFixture('captured', 'captured', 'XX');
            }

            return null;
        });

        $this->denormalizer->setDenormalizer($mock);

        $addressPayload = [
            'street' => 'First',
            'city' => 'Second',
            'country_code' => 'ZZ',
        ];

        $this->denormalizer->denormalize(['name' => 'Alice', 'address' => $addressPayload], PersonFixture::class);

        $this->assertSame(
            $addressPayload,
            $capturedData,
            'The raw address array must be forwarded to the chain without mutation.',
        );
    }

    public function testMockChainReceivesFormatArgument(): void
    {
        $capturedFormat = 'SENTINEL';

        $mock = $this->createMock(DenormalizerInterface::class);
        $mock->method('denormalize')->willReturnCallback(function (mixed $data, string $type, ?string $format) use (
            &$capturedFormat,
        ): mixed {
            $capturedFormat = $format;

            if ($type === AddressFixture::class) {
                return new AddressFixture('s', 'c', 'XX');
            }

            return null;
        });

        $this->denormalizer->setDenormalizer($mock);

        $this->denormalizer->denormalize(
            ['name' => 'Alice', 'address' => ['street' => 's', 'city' => 'c', 'country_code' => 'XX']],
            PersonFixture::class,
            'xml',
        );

        $this->assertSame('xml', $capturedFormat);
    }

    public function testMockChainReceivesContextArgument(): void
    {
        $capturedContext = null;

        $mock = $this->createMock(DenormalizerInterface::class);
        $mock->method('denormalize')->willReturnCallback(function (
            mixed $data,
            string $type,
            ?string $format,
            array $context,
        ) use (&$capturedContext): mixed {
            $capturedContext = $context;

            if ($type === AddressFixture::class) {
                return new AddressFixture('s', 'c', 'XX');
            }

            return null;
        });

        $this->denormalizer->setDenormalizer($mock);

        $this->denormalizer->denormalize(
            ['name' => 'Alice', 'address' => ['street' => 's', 'city' => 'c', 'country_code' => 'XX']],
            PersonFixture::class,
            null,
            ['groups' => ['read'], 'custom' => 'value'],
        );

        $this->assertIsArray($capturedContext);
        $this->assertSame(['read'], $capturedContext['groups'] ?? null);
        $this->assertSame('value', $capturedContext['custom'] ?? null);
    }

    public function testMockChainIsNotInvokedForScalarFields(): void
    {
        // Scalar fields (name, age, nickname) must be handled entirely by
        // the TypeExtractorTrait; the denormalizer chain is reserved for
        // objects/enums/collections. A throwing mock therefore proves the
        // isolation.
        $mock = $this->createMock(DenormalizerInterface::class);
        $mock->expects($this->never())->method('denormalize');

        $this->denormalizer->setDenormalizer($mock);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize([
            'name' => 'Alice',
            'age' => 30,
            'nickname' => 'Al',
        ], PersonFixture::class);

        $this->assertSame('Alice', $result->name);
        $this->assertSame(30, $result->age);
        $this->assertSame('Al', $result->nickname);
    }

    public function testChainIsInvokedOncePerNestedField(): void
    {
        // The payload contains both a `status` enum and an `address` object,
        // so the chain must be consulted exactly twice — once per
        // non-scalar field.
        $mock = $this->createMock(DenormalizerInterface::class);
        $mock
            ->expects($this->exactly(2))
            ->method('denormalize')
            ->willReturnCallback(static function (mixed $data, string $type): mixed {
                return match ($type) {
                    StatusFixture::class => StatusFixture::from((string) $data),
                    AddressFixture::class => new AddressFixture('s', 'c', 'XX'),
                    default => null,
                };
            });

        $this->denormalizer->setDenormalizer($mock);

        $this->denormalizer->denormalize([
            'name' => 'Alice',
            'status' => 'pending',
            'address' => ['street' => 's', 'city' => 'c', 'country_code' => 'XX'],
        ], PersonFixture::class);
    }

    public function testSetDenormalizerStoresTheChainReference(): void
    {
        $mock = $this->createMock(DenormalizerInterface::class);

        // setDenormalizer() comes from DenormalizerAwareTrait; we just
        // verify the method exists and does not throw when invoked.
        $this->denormalizer->setDenormalizer($mock);

        $this->expectNotToPerformAssertions();
    }

    public function testSetDenormalizerCanBeCalledMultipleTimes(): void
    {
        // Each call replaces the previous reference; the latest one wins.
        $first = $this->createMock(DenormalizerInterface::class);
        $first->expects($this->never())->method('denormalize');

        $second = $this->createMock(DenormalizerInterface::class);
        $second->expects($this->atLeastOnce())->method('denormalize')->willReturn(StatusFixture::ACTIVE);

        $this->denormalizer->setDenormalizer($first);
        $this->denormalizer->setDenormalizer($second);

        /** @var PersonFixture $result */
        $result = $this->denormalizer->denormalize(['name' => 'Alice', 'status' => 'active'], PersonFixture::class);

        $this->assertSame(StatusFixture::ACTIVE, $result->status);
    }

    /**
     * Install a Symfony {@see Serializer} chain on the denormalizer under
     * test, with the generated denormalizer placed first (so Symfony's
     * "highest priority wins" contract is respected for PersonFixture) and
     * the caller-supplied normalizers appended after it.
     *
     * @param list<DenormalizerInterface> $extraNormalizers
     */
    private function wireSerializerChain(array $extraNormalizers): void
    {
        $serializer = new Serializer([$this->denormalizer, ...$extraNormalizers]);
        $this->denormalizer->setDenormalizer($serializer);
    }

    /**
     * Build an {@see ObjectNormalizer} that understands `#[SerializedName]`
     * attributes by wiring in a {@see ClassMetadataFactory} backed by the
     * {@see AttributeLoader}. Without this, the generic ObjectNormalizer
     * would look up constructor parameters by their raw PHP names and fail
     * on fixtures like {@see AddressFixture} that remap `$country` to
     * `country_code` in the serialized payload.
     */
    private function makeAttributeAwareObjectNormalizer(): ObjectNormalizer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);

        return new ObjectNormalizer(classMetadataFactory: $classMetadataFactory, nameConverter: $nameConverter);
    }
}
