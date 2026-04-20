<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\PersonFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\StatusFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\WitherFixture;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Integration tests covering the constructor-default-value behaviour of the
 * generated denormalizer.
 *
 * These tests focus specifically on the "optional parameter with a default
 * value" case, which the generator handles by emitting the actual default
 * (extracted via reflection and serialised through
 * {@see \RemcoSmitsDev\BuildableSerializerBundle\Generator\DefaultValueBuilder})
 * as the `default:` argument of the relevant `extract*` helper.
 *
 * The constructor-default behaviour is deliberately orthogonal to the
 * population-phase strategies exercised by
 * {@see DenormalizerPopulationStrategiesTest}, so these assertions target
 * the `construct()` method of the generated class rather than `populate()`.
 *
 * ### Covered scenarios
 *
 *   - Scalar defaults (int, string, null) survive round-trip through the
 *     generated code.
 *   - `UnitEnum` defaults are emitted as fully-qualified class constants
 *     and produce the original enum instance at runtime.
 *   - Required parameters (no default, not nullable) throw
 *     {@see MissingRequiredFieldException} when absent from the payload.
 *   - Nullable parameters without a default are still considered optional
 *     (Symfony ObjectNormalizer semantics): they accept either a null value
 *     or an entirely missing key.
 *   - Explicit values provided in the payload override the declared
 *     default.
 *   - OBJECT_TO_POPULATE bypasses the constructor entirely, so defaults
 *     do NOT leak onto a pre-existing object.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DefaultValueBuilder
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorMetadataExtractor
 */
final class DenormalizerConstructorDefaultsTest extends AbstractTestCase
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

    public function testIntDefaultIsAppliedWhenFieldIsAbsent(): void
    {
        // PersonFixture::$age has default 18.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice'], PersonFixture::class);

        $this->assertSame(18, $result->age);
    }

    public function testIntDefaultIsOverriddenByExplicitValue(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice', 'age' => 99], PersonFixture::class);

        $this->assertSame(99, $result->age);
    }

    public function testNullDefaultIsAppliedForNullableFieldWhenAbsent(): void
    {
        // PersonFixture::$nickname is `?string $nickname = null`.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice'], PersonFixture::class);

        $this->assertNull($result->nickname);
    }

    public function testNullDefaultIsOverriddenByExplicitString(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice', 'nickname' => 'Al'], PersonFixture::class);

        $this->assertSame('Al', $result->nickname);
    }

    public function testNullDefaultAcceptsExplicitNullValue(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice', 'nickname' => null], PersonFixture::class);

        $this->assertNull($result->nickname);
    }

    public function testStringDefaultIsAppliedForSimpleBlogNullableExcerpt(): void
    {
        // SimpleBlog::$excerpt has default null and is nullable.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(['id' => 1, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);

        $this->assertNull($result->getExcerpt());
    }

    public function testEmptyStringDefaultIsPreserved(): void
    {
        // WitherFixture::$title has default ''. The generator must emit
        // the empty string as a literal `''` default — not as null — so
        // that a fresh WitherFixture has the documented empty-string title.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize([], WitherFixture::class);

        $this->assertSame('', $result->title);
        $this->assertSame('', $result->body);
    }

    public function testEmptyStringDefaultIsOverriddenByExplicitValue(): void
    {
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize(['title' => 'Hello'], WitherFixture::class);

        $this->assertSame('Hello', $result->title);
    }

    public function testBackedEnumDefaultIsAppliedWhenFieldIsAbsent(): void
    {
        // PersonFixture::$status has default StatusFixture::PENDING.
        // The generator must emit `\...\StatusFixture::PENDING` as the
        // `default:` argument so the runtime receives the exact enum case.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice'], PersonFixture::class);

        $this->assertSame(StatusFixture::PENDING, $result->status);
    }

    public function testBackedEnumDefaultIsPreservedAsIdentityNotValue(): void
    {
        // Enums are singletons in PHP; the returned status must be the
        // exact same instance as the declared default, not merely an enum
        // case with the same underlying value.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice'], PersonFixture::class);

        $this->assertTrue(
            $result->status === StatusFixture::PENDING,
            'Enum default must be preserved via identity (===), not merely value equality.',
        );
    }

    public function testMissingRequiredFieldThrowsForPersonName(): void
    {
        // PersonFixture::$name has no default and is not nullable.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $this->expectException(MissingRequiredFieldException::class);

        $denormalizer->denormalize([], PersonFixture::class);
    }

    public function testMissingRequiredFieldExceptionCarriesFieldName(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        try {
            $denormalizer->denormalize([], PersonFixture::class);
            $this->fail('Expected MissingRequiredFieldException.');
        } catch (MissingRequiredFieldException $e) {
            $this->assertSame('name', $e->getFieldName());
        }
    }

    public function testMissingRequiredFieldThrowsEvenWhenOtherFieldsArePresent(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $this->expectException(MissingRequiredFieldException::class);

        $denormalizer->denormalize(['age' => 30, 'nickname' => 'Al'], PersonFixture::class);
    }

    public function testAllRequiredFieldsMustBePresentForSimpleBlog(): void
    {
        // SimpleBlog requires id, title, content. Missing any of them
        // triggers the exception.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $payloads = [
            'missing-id' => ['title' => 'T', 'content' => 'C'],
            'missing-title' => ['id' => 1, 'content' => 'C'],
            'missing-content' => ['id' => 1, 'title' => 'T'],
        ];

        foreach ($payloads as $name => $payload) {
            $thrown = false;

            try {
                $denormalizer->denormalize($payload, SimpleBlog::class);
            } catch (MissingRequiredFieldException) {
                $thrown = true;
            }

            $this->assertTrue($thrown, sprintf('Expected MissingRequiredFieldException for payload "%s".', $name));
        }
    }

    public function testRequiredFieldCanBeSatisfiedByExplicitValue(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Bob'], PersonFixture::class);

        $this->assertSame('Bob', $result->name);
    }

    public function testAllOptionalFieldsCanBeOmitted(): void
    {
        // WitherFixture has only optional parameters (all carry defaults).
        // Denormalizing an empty payload must therefore succeed without any
        // MissingRequiredFieldException and produce a fully-initialised
        // instance.
        $denormalizer = $this->loadDenormalizerFor(WitherFixture::class, $this->tempDir);

        /** @var WitherFixture $result */
        $result = $denormalizer->denormalize([], WitherFixture::class);

        $this->assertInstanceOf(WitherFixture::class, $result);
        $this->assertSame('', $result->title);
        $this->assertSame('', $result->body);
        $this->assertNull($result->slug);
    }

    public function testAllDefaultsApplyWhenOnlyRequiredFieldProvided(): void
    {
        // Providing only the single required field on PersonFixture should
        // trigger every declared default: age=18, status=PENDING,
        // address=null, nickname=null.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['name' => 'Alice'], PersonFixture::class);

        $this->assertSame('Alice', $result->name);
        $this->assertSame(18, $result->age);
        $this->assertSame(StatusFixture::PENDING, $result->status);
        $this->assertNull($result->address);
        $this->assertNull($result->nickname);
    }

    public function testObjectToPopulateSkipsConstructorDefaults(): void
    {
        // When OBJECT_TO_POPULATE is supplied, construct() is bypassed
        // entirely, so the existing object's values must be preserved —
        // the declared defaults must NOT overwrite them.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Carol', 99, StatusFixture::ARCHIVED);
        $this->assertNotSame(
            StatusFixture::PENDING,
            $existing->status,
            'Fixture precondition: the existing object must not already match the default.',
        );

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize([], PersonFixture::class, null, [
            \Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame($existing, $result);
        $this->assertSame('Carol', $result->name);
        $this->assertSame(99, $result->age);
        $this->assertSame(StatusFixture::ARCHIVED, $result->status);
    }

    public function testObjectToPopulateAllowsOverridingFieldsButDefaultsDoNotLeak(): void
    {
        // With OBJECT_TO_POPULATE AND some payload fields, only the
        // payload fields should be written; every other field retains
        // the pre-existing value on the populate target.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Dave', 40, StatusFixture::ACTIVE, nickname: 'Davo');

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['age' => 41], PersonFixture::class, null, [
            \Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);

        $this->assertSame($existing, $result);
        $this->assertSame('Dave', $result->name);
        $this->assertSame(41, $result->age);
        $this->assertSame(StatusFixture::ACTIVE, $result->status, 'Status default must NOT leak into populate target.');
        $this->assertSame('Davo', $result->nickname);
    }

    public function testExplicitValueEqualToDefaultRoundTrips(): void
    {
        // Passing the default value explicitly must produce the same
        // result as omitting the field — the generated code must not
        // care whether it's consuming a declared default or a
        // payload-supplied one.
        //
        // The explicit `'status' => 'pending'` branch delegates back to the
        // serializer chain via `$this->denormalizer`, so we wire up a
        // minimal chain containing only the BackedEnumNormalizer (enough
        // for StatusFixture) before invoking the generated denormalizer.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $serializer = new Serializer([$denormalizer, new BackedEnumNormalizer()]);
        $denormalizer->setDenormalizer($serializer);

        /** @var PersonFixture $explicit */
        $explicit = $denormalizer->denormalize([
            'name' => 'Eve',
            'age' => 18,
            'status' => 'pending',
        ], PersonFixture::class);

        /** @var PersonFixture $implicit */
        $implicit = $denormalizer->denormalize(['name' => 'Eve'], PersonFixture::class);

        $this->assertSame($explicit->name, $implicit->name);
        $this->assertSame($explicit->age, $implicit->age);
        $this->assertSame($explicit->status, $implicit->status);
    }

    public function testOmittingEveryOptionalFieldSucceedsMultipleTimes(): void
    {
        // Regression guard: generated denormalizers are often cached after
        // the first invocation. Multiple successive `denormalize()` calls
        // with only the required field must consistently apply every
        // declared default.
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        for ($i = 0; $i < 3; $i++) {
            /** @var PersonFixture $result */
            $result = $denormalizer->denormalize(['name' => 'iteration-' . $i], PersonFixture::class);

            $this->assertSame('iteration-' . $i, $result->name);
            $this->assertSame(18, $result->age);
            $this->assertSame(StatusFixture::PENDING, $result->status);
            $this->assertNull($result->nickname);
            $this->assertNull($result->address);
        }
    }
}
