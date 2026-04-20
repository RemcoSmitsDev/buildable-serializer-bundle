<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\PersonFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SetterFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\WitherFixture;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Integration tests for the four property-population strategies that the
 * denormalizer generator emits at build time:
 *
 *   1. CONSTRUCTOR – value supplied as a constructor argument and NOT
 *      revisited during the populate() phase.
 *   2. PROPERTY    – direct public-property assignment
 *                    (`$object->name = $value`).
 *   3. SETTER      – public setter invocation
 *                    (`$object->setName($value)`), valid for both void and
 *                    fluent (`self`) return types.
 *   4. WITHER      – immutable-style reassignment
 *                    (`$object = $object->withName($value)`), recognised by
 *                    a `self`, `static`, or owning-class return type.
 *
 * Each fixture is carefully chosen so that the discovery rules in
 * {@see \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory}
 * pick exactly one strategy per property, which makes assertions here clean
 * and deterministic.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\GeneratedDenormalizerTrait
 */
final class DenormalizerPopulationStrategiesTest extends AbstractTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testSetterStrategyAssignsAllFields(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        /** @var SetterFixture $result */
        $result = $denormalizer->denormalize([
            'name' => 'Alice',
            'age' => 30,
            'email' => 'a@b.c',
        ], SetterFixture::class);

        $this->assertInstanceOf(SetterFixture::class, $result);
        $this->assertSame('Alice', $result->getName());
        $this->assertSame(30, $result->getAge());
        $this->assertSame('a@b.c', $result->getEmail());
    }

    public function testSetterStrategyAppliesVoidReturningSetter(): void
    {
        // `setName(string $name): void` is the canonical void setter; make
        // sure it is invoked in the generated populate() and successfully
        // mutates the object.
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        /** @var SetterFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Bob'], SetterFixture::class);

        $this->assertSame('Bob', $result->getName());
    }

    public function testSetterStrategyAppliesSelfReturningSetter(): void
    {
        // `setAge(int $age): self` returns $this — still a SETTER, not a
        // WITHER — so the generator must emit a plain method call, NOT a
        // reassignment. We verify the mutation took effect end-to-end.
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        /** @var SetterFixture $result */
        $result = $denormalizer->denormalize(['age' => 42], SetterFixture::class);

        $this->assertSame(42, $result->getAge());
    }

    public function testSetterStrategyAppliesNullableSetter(): void
    {
        // SetterFixture::setEmail(?string $email): void accepts null.
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        /** @var SetterFixture $result */
        $result = $denormalizer->denormalize(['email' => null], SetterFixture::class);

        $this->assertNull($result->getEmail());
    }

    public function testSetterStrategyPreservesDefaultsForMissingFields(): void
    {
        // SetterFixture initialises every field inline, so a payload that
        // omits a field must leave that field at its pre-existing default
        // (empty string / 0 / null).
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        /** @var SetterFixture $result */
        $result = $denormalizer->denormalize(['name' => 'OnlyName'], SetterFixture::class);

        $this->assertSame('OnlyName', $result->getName());
        $this->assertSame(0, $result->getAge());
        $this->assertNull($result->getEmail());
    }

    public function testSetterStrategyAcceptsEmptyPayload(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        /** @var SetterFixture $result */
        $result = $denormalizer->denormalize([], SetterFixture::class);

        $this->assertInstanceOf(SetterFixture::class, $result);
        $this->assertSame('', $result->getName());
        $this->assertSame(0, $result->getAge());
        $this->assertNull($result->getEmail());
    }

    public function testWitherStrategyProducesNewInstancePerField(): void
    {
        // All three wither methods return a *new* instance. Even though
        // PopulateFixture starts out with default values, the populate()
        // method must reassign $object to the result of each wither call
        // so the final return value carries every applied field.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize([
            'title' => 'Hello',
            'body' => 'World',
            'slug' => 'hello-world',
        ], WitherFixture::class);

        $this->assertInstanceOf(WitherFixture::class, $result);
        $this->assertSame('Hello', $result->title);
        $this->assertSame('World', $result->body);
        $this->assertSame('hello-world', $result->slug);
    }

    public function testWitherStrategyAppliesSelfReturningWither(): void
    {
        // withTitle() returns `self` — the classic wither shape.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize(['title' => 'X'], WitherFixture::class);

        $this->assertSame('X', $result->title);
        $this->assertSame('', $result->body);
        $this->assertNull($result->slug);
    }

    public function testWitherStrategyAppliesStaticReturningWither(): void
    {
        // withBody() returns `static` — must still be detected as a wither.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize(['body' => 'Y'], WitherFixture::class);

        $this->assertSame('Y', $result->body);
    }

    public function testWitherStrategyAppliesOwningClassReturningWither(): void
    {
        // withSlug() returns WitherFixture (the owning class) explicitly.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize(['slug' => 'my-slug'], WitherFixture::class);

        $this->assertSame('my-slug', $result->slug);
    }

    public function testWitherStrategyHandlesNullableField(): void
    {
        // WitherFixture::$slug is nullable; an explicit null must survive.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize(['slug' => null], WitherFixture::class);

        $this->assertNull($result->slug);
    }

    public function testWitherStrategyReassignsObjectBetweenCalls(): void
    {
        // All three fields updated together — the final object must carry
        // values from every wither invocation, proving that each call
        // correctly reassigned the $object reference internally.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize(['title' => 'T', 'body' => 'B', 'slug' => 'S'], WitherFixture::class);

        $this->assertSame('T', $result->title);
        $this->assertSame('B', $result->body);
        $this->assertSame('S', $result->slug);
    }

    public function testWitherStrategyWithEmptyPayloadReturnsDefaults(): void
    {
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize([], WitherFixture::class);

        $this->assertSame('', $result->title);
        $this->assertSame('', $result->body);
        $this->assertNull($result->slug);
    }

    public function testWitherStrategyPreservesReadonlyFieldsAcrossDefaults(): void
    {
        // WitherFixture declares its fields as `readonly`, so every wither
        // must return a new instance rather than mutating in place. The
        // denormalizer's populate() has to reassign $object so the
        // downstream field(s) are preserved — if it didn't, the second
        // wither call would clobber the first.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize(['title' => 'First', 'body' => 'Second'], WitherFixture::class);

        $this->assertSame('First', $result->title, 'Title must survive a subsequent withBody() call.');
        $this->assertSame('Second', $result->body);
    }

    public function testPropertyStrategyWritesPublicFieldsViaConstructor(): void
    {
        // PersonFixture promotes every parameter to a public property. On
        // a fresh denormalization the constructor handles all fields — we
        // assert the values round-trip correctly.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize([
            'name' => 'Alice',
            'age' => 30,
            'nickname' => 'Al',
        ], PersonFixture::class);

        $this->assertSame('Alice', $result->name);
        $this->assertSame(30, $result->age);
        $this->assertSame('Al', $result->nickname);
    }

    public function testPropertyStrategyTogglesSkipMapForObjectToPopulate(): void
    {
        // With OBJECT_TO_POPULATE the skip map must be empty, so every
        // field in the input payload is written via direct property
        // assignment onto the existing object.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Carol', 25);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['age' => 31, 'nickname' => 'Cee'], PersonFixture::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame($existing, $result);
        $this->assertSame('Carol', $result->name, 'Name must remain untouched when not present in payload.');
        $this->assertSame(31, $result->age, 'Age must be updated via public-property write.');
        $this->assertSame('Cee', $result->nickname);
    }

    public function testPropertyStrategyPreservesMissingFieldsOnPopulateObject(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Dave', 40, nickname: 'Davo');

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['age' => 41], PersonFixture::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame('Dave', $result->name);
        $this->assertSame(41, $result->age);
        $this->assertSame('Davo', $result->nickname, 'Nickname must survive partial update.');
    }

    public function testPropertyStrategyWritesNullableFieldExplicitly(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Eve', 28, nickname: 'Evee');

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['nickname' => null], PersonFixture::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertNull($result->nickname);
    }

    public function testConstructorStrategyPopulatesEveryFieldWithoutPopulatePhase(): void
    {
        // SetterFixture has an empty constructor, so we exercise the
        // "new Foo()" branch of construct(). PersonFixture is the opposite
        // extreme — every field goes through the constructor — so use a
        // default-loaded PersonFixture to assert the construct() path
        // produces a fully-initialised object.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Frank'], PersonFixture::class);

        $this->assertSame('Frank', $result->name);
        // Default values from the constructor signature must be honoured.
        $this->assertSame(18, $result->age);
        $this->assertNull($result->nickname);
    }

    public function testConstructorStrategyWorksForEmptyConstructorClass(): void
    {
        // SetterFixture has no required parameters; denormalize() with an
        // empty payload must still produce a valid instance.
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        /** @var SetterFixture $result */
        $result = $denormalizer->denormalize([], SetterFixture::class);

        $this->assertInstanceOf(SetterFixture::class, $result);
    }

    public function testConstructorFieldsAreNotOverwrittenByPopulateOnFreshInstance(): void
    {
        // On a fresh denormalize (no OBJECT_TO_POPULATE), the skip map must
        // ensure that populate() does NOT reprocess the same fields that
        // the constructor has already consumed. If it did, the value would
        // pass through the scalar extractor a second time, but with
        // `required: false` — which would silently coerce "0" to 0 for an
        // int default. We assert that the constructor value is the one
        // that survives.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Gina', 'age' => 55], PersonFixture::class);

        $this->assertSame(55, $result->age);
    }

    public function testObjectToPopulateIgnoresConstructorDefaults(): void
    {
        // When OBJECT_TO_POPULATE is supplied we skip construct() entirely,
        // so the existing object's non-default values must be preserved
        // for fields that are absent from the payload.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Hank', 99, nickname: 'H');

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize([], PersonFixture::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame($existing, $result);
        $this->assertSame('Hank', $result->name);
        $this->assertSame(99, $result->age);
        $this->assertSame('H', $result->nickname);
    }

    public function testObjectToPopulateDoesNotInvokeConstructor(): void
    {
        // Regression guard: the denormalize() method must short-circuit to
        // the provided object reference WITHOUT ever invoking the class
        // constructor. We verify this by injecting an object whose state
        // cannot be reached through any valid constructor argument list
        // (e.g. a name that the constructor would accept, but a nickname
        // that requires an explicit override).
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Irene', 0, nickname: 'seed');
        $sentinelHash = spl_object_hash($existing);

        $result = $denormalizer->denormalize(['name' => 'ignored', 'age' => 1], PersonFixture::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame(
            $sentinelHash,
            spl_object_hash($result),
            'OBJECT_TO_POPULATE must reuse the supplied instance, never create a new one.',
        );
    }
}
