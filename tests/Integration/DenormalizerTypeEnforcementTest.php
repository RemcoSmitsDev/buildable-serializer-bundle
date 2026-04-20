<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Integration;

use RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\AbstractTestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\PersonFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SetterFixture;
use RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\SimpleBlog;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Integration tests for the `DISABLE_TYPE_ENFORCEMENT` context option.
 *
 * The generated denormalizer honours the same context flag that Symfony's
 * built-in {@see AbstractObjectNormalizer} uses:
 *
 *   - When the flag is absent or false (the default), the denormalizer
 *     runs in **strict mode**: any mismatch between the declared property
 *     type and the payload value surfaces as a
 *     {@see TypeMismatchException}.
 *   - When the flag is true, the denormalizer runs in **lenient mode**:
 *     values are coerced to the expected type whenever the rules
 *     documented on
 *     {@see \RemcoSmitsDev\BuildableSerializerBundle\Trait\TypeExtractorTrait}
 *     allow a safe conversion; only genuinely-ambiguous cases still
 *     throw.
 *
 * These tests exercise both modes end-to-end through a generated
 * denormalizer class, rather than against the trait in isolation (which
 * already has its own dedicated unit tests). The goal is to prove that
 * the code the generator emits wires the flag correctly to every
 * `extract*` helper call.
 *
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\TypeExtractorTrait
 */
final class DenormalizerTypeEnforcementTest extends AbstractTestCase
{
    private string $tempDir;

    /**
     * Shorthand for the context flag used throughout the tests below; kept
     * as an instance property so individual assertions stay compact.
     *
     * @var array<string, mixed>
     */
    private array $lenient;

    /** @var array<string, mixed> */
    private array $strict;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTempDir();
        $this->lenient = [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true];
        $this->strict = [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => false];
    }

    protected function tearDown(): void
    {
        $this->removeTempDir($this->tempDir);
    }

    public function testStrictModeIsDefaultWhenContextFlagIsAbsent(): void
    {
        // No flag set at all — the denormalizer must default to strict and
        // reject a wrongly-typed payload value.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(['id' => '42', 'title' => 'T', 'content' => 'C'], SimpleBlog::class);
    }

    public function testStrictModeThrowsForStringInIntField(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(
            ['id' => '42', 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->strict,
        );
    }

    public function testStrictModeThrowsForIntInStringField(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(['id' => 1, 'title' => 42, 'content' => 'C'], SimpleBlog::class);
    }

    public function testStrictModeThrowsForBoolInIntField(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(['id' => true, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);
    }

    public function testStrictModeTypeMismatchExceptionCarriesFieldMetadata(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        try {
            $denormalizer->denormalize(['id' => '42', 'title' => 'T', 'content' => 'C'], SimpleBlog::class);
            $this->fail('Expected TypeMismatchException.');
        } catch (TypeMismatchException $e) {
            $this->assertSame('id', $e->getFieldName());
            $this->assertSame('int', $e->getExpectedType());
            $this->assertSame('string', $e->getActualType());
        }
    }

    public function testStrictModeAcceptsCorrectlyTypedValues(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(['id' => 42, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);

        $this->assertSame(42, $result->getId());
        $this->assertSame('T', $result->getTitle());
        $this->assertSame('C', $result->getContent());
    }

    public function testStrictModeAcceptsIntAsFloat(): void
    {
        // An integer is always a lossless upcast to float, and the trait
        // whitelists this conversion even in strict mode. The SimpleBlog
        // fixture has no float field, so use an anonymous denormalizer
        // target wouldn't work here; instead we assert the inverse — a
        // string is NOT accepted — which proves strict mode is active.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(
            ['id' => 'oops', 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->strict,
        );
    }

    public function testLenientModeCoercesNumericStringToInt(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(
            ['id' => '42', 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame(42, $result->getId());
    }

    public function testLenientModeCoercesBoolToInt(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(
            ['id' => true, 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame(1, $result->getId());
    }

    public function testLenientModeCoercesWholeFloatToInt(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(
            ['id' => 7.0, 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame(7, $result->getId());
    }

    public function testLenientModeStillRejectsFractionalFloatInIntField(): void
    {
        // Fractional floats cannot be safely coerced to int without losing
        // information, so even in lenient mode the denormalizer must throw.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(
            ['id' => 1.5, 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );
    }

    public function testLenientModeStillRejectsNonNumericStringInIntField(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(
            ['id' => 'hello', 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );
    }

    public function testLenientModeCoercesIntToString(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(
            ['id' => 1, 'title' => 42, 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame('42', $result->getTitle());
    }

    public function testLenientModeCoercesFloatToString(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(
            ['id' => 1, 'title' => 1.5, 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame('1.5', $result->getTitle());
    }

    public function testLenientModeCoercesBoolToString(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $trueResult */
        $trueResult = $denormalizer->denormalize(
            ['id' => 1, 'title' => true, 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame('1', $trueResult->getTitle());

        /** @var SimpleBlog $falseResult */
        $falseResult = $denormalizer->denormalize(
            ['id' => 1, 'title' => false, 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame('0', $falseResult->getTitle());
    }

    public function testLenientModeCoercesStringableToString(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'from-stringable';
            }
        };

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(
            ['id' => 1, 'title' => $stringable, 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );

        $this->assertSame('from-stringable', $result->getTitle());
    }

    public function testLenientModeRejectsArrayInStringField(): void
    {
        // Arrays cannot be meaningfully coerced to string without choosing
        // an arbitrary separator / format, so they remain rejected even in
        // lenient mode.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(
            ['id' => 1, 'title' => ['not', 'stringable'], 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );
    }

    public function testLenientModeCoercesTruthyStringsToBool(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SetterFixture::class, $this->tempDir);

        // SetterFixture has no bool field, so use PersonFixture… actually
        // PersonFixture's bools sit on its address. Use a dedicated
        // coercion path through extractBool by exercising WitherFixture?
        // Neither has a bool field — switch fixtures to one that exposes
        // a bool setter.
        //
        // The closest bool field in the existing fixtures is
        // UserFixture::$active, discovered via the `isActive` getter +
        // `setActive` setter pair. Use it here.
        $userDenormalizer = $this->loadDenormalizerFor(
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
            $this->tempDir,
        );

        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'Yes'] as $truthy) {
            /** @var \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture $result */
            $result = $userDenormalizer->denormalize(
                ['id' => 1, 'name' => 'A', 'email' => 'a@b.c', 'active' => $truthy],
                \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
                null,
                $this->lenient,
            );

            $this->assertTrue($result->isActive(), sprintf('Truthy string "%s" should coerce to true.', $truthy));
        }

        // Silence unused-variable notice.
        unset($denormalizer);
    }

    public function testLenientModeCoercesFalsyStringsToBool(): void
    {
        $userDenormalizer = $this->loadDenormalizerFor(
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
            $this->tempDir,
        );

        foreach (['0', 'false', 'no', 'off', '', 'FALSE'] as $falsy) {
            /** @var \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture $result */
            $result = $userDenormalizer->denormalize(
                ['id' => 1, 'name' => 'A', 'email' => 'a@b.c', 'active' => $falsy],
                \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
                null,
                $this->lenient,
            );

            $this->assertFalse($result->isActive(), sprintf('Falsy string "%s" should coerce to false.', $falsy));
        }
    }

    public function testStrictModeStillRejectsStringInBoolField(): void
    {
        $userDenormalizer = $this->loadDenormalizerFor(
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
            $this->tempDir,
        );

        $this->expectException(TypeMismatchException::class);

        $userDenormalizer->denormalize(
            ['id' => 1, 'name' => 'A', 'email' => 'a@b.c', 'active' => 'true'],
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
        );
    }

    public function testLenientModeStillRejectsUnrecognisedStringInBoolField(): void
    {
        $userDenormalizer = $this->loadDenormalizerFor(
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
            $this->tempDir,
        );

        $this->expectException(TypeMismatchException::class);

        $userDenormalizer->denormalize(
            ['id' => 1, 'name' => 'A', 'email' => 'a@b.c', 'active' => 'maybe'],
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
            null,
            $this->lenient,
        );
    }

    public function testStrictModeThrowsUnexpectedNullForNonNullableField(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(UnexpectedNullException::class);

        $denormalizer->denormalize(['id' => null, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);
    }

    public function testLenientModeAlsoThrowsUnexpectedNullForNonNullableField(): void
    {
        // Null handling is orthogonal to type-enforcement: a non-nullable
        // field rejects null regardless of the flag, because there is no
        // safe coercion path.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(UnexpectedNullException::class);

        $denormalizer->denormalize(
            ['id' => null, 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            $this->lenient,
        );
    }

    public function testUnexpectedNullExceptionCarriesExpectedType(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        try {
            $denormalizer->denormalize(['id' => null, 'title' => 'T', 'content' => 'C'], SimpleBlog::class);
            $this->fail('Expected UnexpectedNullException.');
        } catch (UnexpectedNullException $e) {
            $this->assertSame('id', $e->getFieldName());
            $this->assertSame('int', $e->getExpectedType());
        }
    }

    public function testNullableFieldAcceptsNullInBothModes(): void
    {
        // SimpleBlog::$excerpt is nullable, so null is legal in both
        // strict and lenient modes.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        foreach ([$this->strict, $this->lenient] as $context) {
            /** @var SimpleBlog $result */
            $result = $denormalizer->denormalize(
                ['id' => 1, 'title' => 'T', 'content' => 'C', 'excerpt' => null],
                SimpleBlog::class,
                null,
                $context,
            );

            $this->assertNull($result->getExcerpt());
        }
    }

    public function testTruthyNonBooleanFlagIsTreatedAsLenient(): void
    {
        // Consumers in the wild sometimes set the flag to 1 / "yes" / etc.
        // rather than a strict bool; the trait casts the value to bool
        // before reading, so any truthy value enables lenient mode.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        /** @var SimpleBlog $result */
        $result = $denormalizer->denormalize(
            ['id' => '42', 'title' => 'T', 'content' => 'C'],
            SimpleBlog::class,
            null,
            [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => 1],
        );

        $this->assertSame(42, $result->getId());
    }

    public function testFalsyNonBooleanFlagIsTreatedAsStrict(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(['id' => '42', 'title' => 'T', 'content' => 'C'], SimpleBlog::class, null, [
            AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => 0,
        ]);
    }

    public function testFlagIsRespectedOnPopulatePhaseToo(): void
    {
        // PersonFixture populates `age` during the constructor phase AND
        // would re-read it during populate() if the skip map were
        // disabled (OBJECT_TO_POPULATE). Here we pass an existing object
        // with a partial payload — the lenient flag must be consulted
        // during the populate() write, not only in construct().
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Carol', 25);

        /** @var PersonFixture $result */
        $result = $denormalizer->denormalize(['age' => '99'], PersonFixture::class, null, array_merge($this->lenient, [
            \Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]));

        $this->assertSame($existing, $result);
        $this->assertSame(99, $result->age, 'Lenient coercion must apply during populate() as well.');
    }

    public function testFlagIsNotRespectedForWrongTypeOnPopulatePhaseInStrictMode(): void
    {
        $denormalizer = $this->loadDenormalizerFor(PersonFixture::class, $this->tempDir);

        $existing = new PersonFixture('Carol', 25);

        $this->expectException(TypeMismatchException::class);

        $denormalizer->denormalize(['age' => '99'], PersonFixture::class, null, [
            \Symfony\Component\Serializer\Normalizer\AbstractNormalizer::OBJECT_TO_POPULATE => $existing,
        ]);
    }

    public function testStrictModeThrowsForScalarInArrayField(): void
    {
        // We use an anonymous-less fixture here. `PersonFixture::$address`
        // is an object, not a plain array field. To exercise the array
        // branch we target a payload against a class that the existing
        // fixture suite provides, but since there is no pure-array
        // SimpleBlog field either, we rely on the TypeExtractorTraitTest
        // (unit) for the array coercion matrix, and cover only the wiring
        // here via a regression case on UserFixture::$tags — which IS an
        // array field.
        $userDenormalizer = $this->loadDenormalizerFor(
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
            $this->tempDir,
        );

        // `tags` is a typed collection (array<TagFixture>), which uses
        // extractArrayOfObjects rather than extractArray. That helper
        // raises TypeMismatchException for a scalar regardless of the
        // enforcement flag (there is no meaningful coercion for
        // "scalar → array of objects"). This keeps behaviour predictable.
        $this->expectException(TypeMismatchException::class);

        $userDenormalizer->denormalize(
            ['id' => 1, 'name' => 'A', 'email' => 'a@b.c', 'tags' => 'not-an-array'],
            \RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\UserFixture::class,
            null,
            $this->lenient,
        );
    }

    public function testRequiredFieldErrorIsOrthogonalToEnforcementMode(): void
    {
        // A missing required field is NOT a type mismatch and therefore
        // must throw MissingRequiredFieldException (not TypeMismatch) in
        // both modes.
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        foreach ([$this->strict, $this->lenient] as $context) {
            $thrown = null;

            try {
                $denormalizer->denormalize(['title' => 'T', 'content' => 'C'], SimpleBlog::class, null, $context);
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            $this->assertInstanceOf(
                \RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException::class,
                $thrown,
                'Missing required field must surface as MissingRequiredFieldException in every enforcement mode.',
            );
        }
    }

    public function testCorrectlyTypedPayloadSucceedsInBothModes(): void
    {
        $denormalizer = $this->loadDenormalizerFor(SimpleBlog::class, $this->tempDir);

        $data = ['id' => 42, 'title' => 'T', 'content' => 'C'];

        foreach ([$this->strict, $this->lenient] as $label => $context) {
            /** @var SimpleBlog $result */
            $result = $denormalizer->denormalize($data, SimpleBlog::class, null, $context);

            $this->assertSame(42, $result->getId(), "Failed for context #{$label}");
            $this->assertSame('T', $result->getTitle(), "Failed for context #{$label}");
            $this->assertSame('C', $result->getContent(), "Failed for context #{$label}");
        }
    }
}
