<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Trait;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException;
use RemcoSmitsDev\BuildableSerializerBundle\Trait\TypeExtractorTrait;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Test host that exposes {@see TypeExtractorTrait} private helpers as public
 * methods so they can be exercised directly from the unit-test layer.
 *
 * Every wrapper forwards verbatim to the underlying trait method, which keeps
 * the test surface identical to the code actually emitted by the denormalizer
 * generator (generated denormalizers call these methods on themselves).
 */
final class TypeExtractorTraitTestHost
{
    use TypeExtractorTrait {
        extractInt as public;
        extractNullableInt as public;
        extractFloat as public;
        extractNullableFloat as public;
        extractString as public;
        extractNullableString as public;
        extractBool as public;
        extractNullableBool as public;
        extractArray as public;
        extractNullableArray as public;
        coerceToInt as public;
        coerceToFloat as public;
        coerceToString as public;
        coerceToBool as public;
    }
}

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Trait\TypeExtractorTrait
 */
final class TypeExtractorTraitTest extends TestCase
{
    private TypeExtractorTraitTestHost $host;

    /**
     * Context flag that disables strict type enforcement and enables
     * lenient coercion, mirroring how Symfony's own serializer consumes
     * this option.
     *
     * @var array<string, mixed>
     */
    private array $lenient;

    protected function setUp(): void
    {
        $this->host = new TypeExtractorTraitTestHost();
        $this->lenient = [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true];
    }

    public function testExtractIntReturnsIntValue(): void
    {
        $this->assertSame(42, $this->host->extractInt(['age' => 42], 'age', true, null, []));
    }

    public function testExtractIntThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractInt([], 'age', true, null, []);
    }

    public function testExtractIntReturnsDefaultWhenMissingAndNotRequired(): void
    {
        $this->assertSame(18, $this->host->extractInt([], 'age', false, 18, []));
    }

    public function testExtractIntFallsBackToZeroWhenDefaultIsNullAndMissing(): void
    {
        $this->assertSame(0, $this->host->extractInt([], 'age', false, null, []));
    }

    public function testExtractIntThrowsUnexpectedNullOnNullValue(): void
    {
        $this->expectException(UnexpectedNullException::class);

        $this->host->extractInt(['age' => null], 'age', false, 18, []);
    }

    public function testExtractIntThrowsTypeMismatchInStrictModeForString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractInt(['age' => '42'], 'age', false, null, []);
    }

    public function testExtractIntCoercesStringInLenientMode(): void
    {
        $this->assertSame(42, $this->host->extractInt(['age' => '42'], 'age', false, null, $this->lenient));
    }

    public function testExtractIntCoercesBoolInLenientMode(): void
    {
        $this->assertSame(1, $this->host->extractInt(['age' => true], 'age', false, null, $this->lenient));
        $this->assertSame(0, $this->host->extractInt(['age' => false], 'age', false, null, $this->lenient));
    }

    public function testExtractIntCoercesWholeFloatInLenientMode(): void
    {
        $this->assertSame(7, $this->host->extractInt(['age' => 7.0], 'age', false, null, $this->lenient));
    }

    public function testExtractIntThrowsOnFractionalFloatEvenInLenientMode(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractInt(['age' => 1.5], 'age', false, null, $this->lenient);
    }

    public function testExtractIntThrowsInLenientModeForNonNumericString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractInt(['age' => 'hello'], 'age', false, null, $this->lenient);
    }

    public function testExtractNullableIntReturnsIntValue(): void
    {
        $this->assertSame(42, $this->host->extractNullableInt(['age' => 42], 'age', true, null, []));
    }

    public function testExtractNullableIntReturnsNullForNullValue(): void
    {
        $this->assertNull($this->host->extractNullableInt(['age' => null], 'age', false, 18, []));
    }

    public function testExtractNullableIntReturnsDefaultWhenMissing(): void
    {
        $this->assertSame(18, $this->host->extractNullableInt([], 'age', false, 18, []));
    }

    public function testExtractNullableIntReturnsNullWhenMissingAndDefaultNull(): void
    {
        $this->assertNull($this->host->extractNullableInt([], 'age', false, null, []));
    }

    public function testExtractNullableIntThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractNullableInt([], 'age', true, null, []);
    }

    public function testExtractNullableIntCoercesInLenientMode(): void
    {
        $this->assertSame(42, $this->host->extractNullableInt(['age' => '42'], 'age', false, null, $this->lenient));
    }

    public function testExtractFloatReturnsFloatValue(): void
    {
        $this->assertSame(1.5, $this->host->extractFloat(['ratio' => 1.5], 'ratio', true, null, []));
    }

    public function testExtractFloatAcceptsIntAsLosslessUpcast(): void
    {
        // An integer is always safely representable as a float — accept it
        // even in strict mode (Symfony's ObjectNormalizer does the same).
        $result = $this->host->extractFloat(['ratio' => 7], 'ratio', true, null, []);

        $this->assertSame(7.0, $result);
    }

    public function testExtractFloatThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractFloat([], 'ratio', true, null, []);
    }

    public function testExtractFloatReturnsDefault(): void
    {
        $this->assertSame(1.5, $this->host->extractFloat([], 'ratio', false, 1.5, []));
    }

    public function testExtractFloatFallsBackToZeroWhenDefaultIsNullAndMissing(): void
    {
        $this->assertSame(0.0, $this->host->extractFloat([], 'ratio', false, null, []));
    }

    public function testExtractFloatThrowsUnexpectedNullOnNullValue(): void
    {
        $this->expectException(UnexpectedNullException::class);

        $this->host->extractFloat(['ratio' => null], 'ratio', false, 1.5, []);
    }

    public function testExtractFloatThrowsTypeMismatchInStrictModeForString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractFloat(['ratio' => '1.5'], 'ratio', false, null, []);
    }

    public function testExtractFloatCoercesStringInLenientMode(): void
    {
        $this->assertSame(1.5, $this->host->extractFloat(['ratio' => '1.5'], 'ratio', false, null, $this->lenient));
    }

    public function testExtractFloatCoercesBoolInLenientMode(): void
    {
        $this->assertSame(1.0, $this->host->extractFloat(['ratio' => true], 'ratio', false, null, $this->lenient));
        $this->assertSame(0.0, $this->host->extractFloat(['ratio' => false], 'ratio', false, null, $this->lenient));
    }

    public function testExtractFloatThrowsInLenientModeForNonNumericString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractFloat(['ratio' => 'hello'], 'ratio', false, null, $this->lenient);
    }

    public function testExtractNullableFloatReturnsFloatValue(): void
    {
        $this->assertSame(1.5, $this->host->extractNullableFloat(['ratio' => 1.5], 'ratio', true, null, []));
    }

    public function testExtractNullableFloatReturnsNullForNullValue(): void
    {
        $this->assertNull($this->host->extractNullableFloat(['ratio' => null], 'ratio', false, 1.5, []));
    }

    public function testExtractNullableFloatReturnsDefaultWhenMissing(): void
    {
        $this->assertSame(1.5, $this->host->extractNullableFloat([], 'ratio', false, 1.5, []));
    }

    public function testExtractNullableFloatAcceptsIntAsUpcast(): void
    {
        $this->assertSame(7.0, $this->host->extractNullableFloat(['ratio' => 7], 'ratio', false, null, []));
    }

    public function testExtractNullableFloatCoercesInLenientMode(): void
    {
        $this->assertSame(1.5, $this->host->extractNullableFloat(
            ['ratio' => '1.5'],
            'ratio',
            false,
            null,
            $this->lenient,
        ));
    }

    public function testExtractStringReturnsStringValue(): void
    {
        $this->assertSame('Alice', $this->host->extractString(['name' => 'Alice'], 'name', true, null, []));
    }

    public function testExtractStringThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractString([], 'name', true, null, []);
    }

    public function testExtractStringReturnsDefault(): void
    {
        $this->assertSame('user', $this->host->extractString([], 'role', false, 'user', []));
    }

    public function testExtractStringFallsBackToEmptyStringWhenDefaultIsNullAndMissing(): void
    {
        $this->assertSame('', $this->host->extractString([], 'name', false, null, []));
    }

    public function testExtractStringThrowsUnexpectedNullOnNullValue(): void
    {
        $this->expectException(UnexpectedNullException::class);

        $this->host->extractString(['name' => null], 'name', false, 'user', []);
    }

    public function testExtractStringThrowsTypeMismatchInStrictModeForInt(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractString(['name' => 42], 'name', false, null, []);
    }

    public function testExtractStringCoercesIntInLenientMode(): void
    {
        $this->assertSame('42', $this->host->extractString(['name' => 42], 'name', false, null, $this->lenient));
    }

    public function testExtractStringCoercesFloatInLenientMode(): void
    {
        $this->assertSame('1.5', $this->host->extractString(['name' => 1.5], 'name', false, null, $this->lenient));
    }

    public function testExtractStringCoercesBoolInLenientMode(): void
    {
        $this->assertSame('1', $this->host->extractString(['name' => true], 'name', false, null, $this->lenient));
        $this->assertSame('0', $this->host->extractString(['name' => false], 'name', false, null, $this->lenient));
    }

    public function testExtractStringCoercesStringableInLenientMode(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'hello';
            }
        };

        $this->assertSame('hello', $this->host->extractString(
            ['name' => $stringable],
            'name',
            false,
            null,
            $this->lenient,
        ));
    }

    public function testExtractStringThrowsInLenientModeForUnsupportedValue(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractString(['name' => ['not', 'stringable']], 'name', false, null, $this->lenient);
    }

    public function testExtractNullableStringReturnsStringValue(): void
    {
        $this->assertSame('Alice', $this->host->extractNullableString(['name' => 'Alice'], 'name', true, null, []));
    }

    public function testExtractNullableStringReturnsNullForNullValue(): void
    {
        $this->assertNull($this->host->extractNullableString(['name' => null], 'name', false, 'x', []));
    }

    public function testExtractNullableStringReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('x', $this->host->extractNullableString([], 'name', false, 'x', []));
    }

    public function testExtractNullableStringCoercesInLenientMode(): void
    {
        $this->assertSame('42', $this->host->extractNullableString(
            ['name' => 42],
            'name',
            false,
            null,
            $this->lenient,
        ));
    }

    public function testExtractBoolReturnsTrue(): void
    {
        $this->assertTrue($this->host->extractBool(['active' => true], 'active', true, null, []));
    }

    public function testExtractBoolReturnsFalse(): void
    {
        $this->assertFalse($this->host->extractBool(['active' => false], 'active', true, null, []));
    }

    public function testExtractBoolThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractBool([], 'active', true, null, []);
    }

    public function testExtractBoolReturnsDefault(): void
    {
        $this->assertTrue($this->host->extractBool([], 'active', false, true, []));
        $this->assertFalse($this->host->extractBool([], 'active', false, false, []));
    }

    public function testExtractBoolFallsBackToFalseWhenDefaultIsNullAndMissing(): void
    {
        $this->assertFalse($this->host->extractBool([], 'active', false, null, []));
    }

    public function testExtractBoolThrowsUnexpectedNullOnNullValue(): void
    {
        $this->expectException(UnexpectedNullException::class);

        $this->host->extractBool(['active' => null], 'active', false, false, []);
    }

    public function testExtractBoolThrowsTypeMismatchInStrictModeForInt(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractBool(['active' => 1], 'active', false, null, []);
    }

    public function testExtractBoolCoercesTruthyStringsInLenientMode(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'Yes', 'On'] as $truthy) {
            $this->assertTrue(
                $this->host->extractBool(['active' => $truthy], 'active', false, null, $this->lenient),
                sprintf('Expected "%s" to coerce to true.', $truthy),
            );
        }
    }

    public function testExtractBoolCoercesFalsyStringsInLenientMode(): void
    {
        foreach (['0', 'false', 'no', 'off', '', 'FALSE', 'No', 'Off'] as $falsy) {
            $this->assertFalse(
                $this->host->extractBool(['active' => $falsy], 'active', false, null, $this->lenient),
                sprintf('Expected "%s" to coerce to false.', $falsy),
            );
        }
    }

    public function testExtractBoolCoercesIntInLenientMode(): void
    {
        $this->assertFalse($this->host->extractBool(['active' => 0], 'active', false, null, $this->lenient));
        $this->assertTrue($this->host->extractBool(['active' => 1], 'active', false, null, $this->lenient));
        $this->assertTrue($this->host->extractBool(['active' => 42], 'active', false, null, $this->lenient));
        $this->assertTrue($this->host->extractBool(['active' => -1], 'active', false, null, $this->lenient));
    }

    public function testExtractBoolCoercesFloatInLenientMode(): void
    {
        $this->assertFalse($this->host->extractBool(['active' => 0.0], 'active', false, null, $this->lenient));
        $this->assertTrue($this->host->extractBool(['active' => 0.1], 'active', false, null, $this->lenient));
    }

    public function testExtractBoolThrowsInLenientModeForUnrecognisedString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractBool(['active' => 'maybe'], 'active', false, null, $this->lenient);
    }

    public function testExtractNullableBoolReturnsBoolValue(): void
    {
        $this->assertTrue($this->host->extractNullableBool(['active' => true], 'active', true, null, []));
    }

    public function testExtractNullableBoolReturnsNullForNullValue(): void
    {
        $this->assertNull($this->host->extractNullableBool(['active' => null], 'active', false, true, []));
    }

    public function testExtractNullableBoolReturnsDefaultWhenMissing(): void
    {
        $this->assertTrue($this->host->extractNullableBool([], 'active', false, true, []));
    }

    public function testExtractNullableBoolReturnsNullWhenMissingAndDefaultNull(): void
    {
        $this->assertNull($this->host->extractNullableBool([], 'active', false, null, []));
    }

    public function testExtractArrayReturnsArrayValue(): void
    {
        $this->assertSame([1, 2, 3], $this->host->extractArray(['tags' => [1, 2, 3]], 'tags', true, null, []));
    }

    public function testExtractArrayThrowsOnMissingRequiredField(): void
    {
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractArray([], 'tags', true, null, []);
    }

    public function testExtractArrayReturnsDefault(): void
    {
        $this->assertSame(['a'], $this->host->extractArray([], 'tags', false, ['a'], []));
    }

    public function testExtractArrayFallsBackToEmptyArrayWhenDefaultIsNullAndMissing(): void
    {
        $this->assertSame([], $this->host->extractArray([], 'tags', false, null, []));
    }

    public function testExtractArrayThrowsUnexpectedNullOnNullValue(): void
    {
        $this->expectException(UnexpectedNullException::class);

        $this->host->extractArray(['tags' => null], 'tags', false, [], []);
    }

    public function testExtractArrayThrowsTypeMismatchInStrictModeForScalar(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractArray(['tags' => 'foo'], 'tags', false, null, []);
    }

    public function testExtractArrayWrapsScalarInLenientMode(): void
    {
        $this->assertSame(['foo'], $this->host->extractArray(['tags' => 'foo'], 'tags', false, null, $this->lenient));
    }

    public function testExtractNullableArrayReturnsArrayValue(): void
    {
        $this->assertSame([1, 2, 3], $this->host->extractNullableArray(['tags' => [1, 2, 3]], 'tags', true, null, []));
    }

    public function testExtractNullableArrayReturnsNullForNullValue(): void
    {
        $this->assertNull($this->host->extractNullableArray(['tags' => null], 'tags', false, [], []));
    }

    public function testExtractNullableArrayReturnsDefaultWhenMissing(): void
    {
        $this->assertSame(['a'], $this->host->extractNullableArray([], 'tags', false, ['a'], []));
    }

    public function testExtractNullableArrayThrowsTypeMismatchInStrictModeForScalar(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractNullableArray(['tags' => 'foo'], 'tags', false, null, []);
    }

    public function testExtractNullableArrayWrapsScalarInLenientMode(): void
    {
        $this->assertSame(
            ['foo'],
            $this->host->extractNullableArray(['tags' => 'foo'], 'tags', false, null, $this->lenient),
        );
    }

    public function testCoerceToIntFromBool(): void
    {
        $this->assertSame(1, $this->host->coerceToInt(true, 'k'));
        $this->assertSame(0, $this->host->coerceToInt(false, 'k'));
    }

    public function testCoerceToIntFromWholeFloat(): void
    {
        $this->assertSame(7, $this->host->coerceToInt(7.0, 'k'));
    }

    public function testCoerceToIntThrowsOnFractionalFloat(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToInt(1.5, 'k');
    }

    public function testCoerceToIntFromNumericString(): void
    {
        $this->assertSame(42, $this->host->coerceToInt('42', 'k'));
    }

    public function testCoerceToIntThrowsOnFractionalNumericString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToInt('1.5', 'k');
    }

    public function testCoerceToIntThrowsOnNonNumericString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToInt('hello', 'k');
    }

    public function testCoerceToIntThrowsOnArray(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToInt([], 'k');
    }

    public function testCoerceToFloatFromBool(): void
    {
        $this->assertSame(1.0, $this->host->coerceToFloat(true, 'k'));
        $this->assertSame(0.0, $this->host->coerceToFloat(false, 'k'));
    }

    public function testCoerceToFloatFromNumericString(): void
    {
        $this->assertSame(1.5, $this->host->coerceToFloat('1.5', 'k'));
    }

    public function testCoerceToFloatThrowsOnNonNumericString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToFloat('hello', 'k');
    }

    public function testCoerceToStringFromInt(): void
    {
        $this->assertSame('42', $this->host->coerceToString(42, 'k'));
    }

    public function testCoerceToStringFromFloat(): void
    {
        $this->assertSame('1.5', $this->host->coerceToString(1.5, 'k'));
    }

    public function testCoerceToStringFromBool(): void
    {
        $this->assertSame('1', $this->host->coerceToString(true, 'k'));
        $this->assertSame('0', $this->host->coerceToString(false, 'k'));
    }

    public function testCoerceToStringFromStringable(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'hi';
            }
        };

        $this->assertSame('hi', $this->host->coerceToString($stringable, 'k'));
    }

    public function testCoerceToStringThrowsOnArray(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToString(['a'], 'k');
    }

    public function testCoerceToBoolFromIntZero(): void
    {
        $this->assertFalse($this->host->coerceToBool(0, 'k'));
    }

    public function testCoerceToBoolFromIntNonZero(): void
    {
        $this->assertTrue($this->host->coerceToBool(1, 'k'));
        $this->assertTrue($this->host->coerceToBool(-5, 'k'));
    }

    public function testCoerceToBoolFromStringWithWhitespace(): void
    {
        $this->assertTrue($this->host->coerceToBool('  true  ', 'k'));
        $this->assertFalse($this->host->coerceToBool('  false  ', 'k'));
    }

    public function testCoerceToBoolThrowsOnUnrecognisedString(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToBool('maybe', 'k');
    }

    public function testCoerceToBoolThrowsOnArray(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->coerceToBool([], 'k');
    }

    public function testExplicitFalseFlagIsStrict(): void
    {
        $this->expectException(TypeMismatchException::class);

        $this->host->extractInt(['age' => '42'], 'age', false, null, [
            AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => false,
        ]);
    }

    public function testTruthyNonBooleanFlagIsTreatedAsLenient(): void
    {
        // Symfony stringly-typed options are commonly set to 1/true/"yes" — the
        // flag is cast to bool before it's inspected, so any truthy value
        // switches coercion on.
        $this->assertSame(42, $this->host->extractInt(['age' => '42'], 'age', false, null, [
            AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => 1,
        ]));
    }

    public function testExceptionFieldNameMatchesKey(): void
    {
        try {
            $this->host->extractInt(['age' => 'nope'], 'age', false, null, []);
            $this->fail('Expected TypeMismatchException.');
        } catch (TypeMismatchException $e) {
            $this->assertSame('age', $e->getFieldName());
            $this->assertSame('int', $e->getExpectedType());
        }
    }

    public function testMissingRequiredFieldExceptionCarriesFieldName(): void
    {
        try {
            $this->host->extractString([], 'email', true, null, []);
            $this->fail('Expected MissingRequiredFieldException.');
        } catch (MissingRequiredFieldException $e) {
            $this->assertSame('email', $e->getFieldName());
        }
    }

    public function testUnexpectedNullExceptionCarriesExpectedType(): void
    {
        try {
            $this->host->extractBool(['active' => null], 'active', false, false, []);
            $this->fail('Expected UnexpectedNullException.');
        } catch (UnexpectedNullException $e) {
            $this->assertSame('active', $e->getFieldName());
            $this->assertSame('bool', $e->getExpectedType());
        }
    }

    //
    // A non-null `$default` is treated as an authoritative fallback: if the
    // key is missing from `$data`, the helper returns the default WITHOUT
    // consulting `$required`. This is what makes the chained `extract*`
    // fallback pattern used for `#[SerializedName]` aliases work: the
    // generator can set `required: true` on the outer call and still plug
    // the inner fallback call's result into `default:` — if the inner
    // call resolves a value, the outer call uses it; only when the inner
    // call also yields `null` does the outer `required` check fire.

    public function testExtractIntNonNullDefaultShortCircuitsRequiredCheck(): void
    {
        // Even with `required: true`, a non-null default must suppress the
        // MissingRequiredFieldException that would otherwise be thrown for
        // a missing key.
        $this->assertSame(18, $this->host->extractInt([], 'age', required: true, default: 18, context: []));
    }

    public function testExtractIntNullDefaultStillFiresRequiredCheck(): void
    {
        // With a null default we must fall through to the original
        // behaviour and throw when the key is missing and required=true.
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractInt([], 'age', required: true, default: null, context: []);
    }

    public function testExtractFloatNonNullDefaultShortCircuitsRequiredCheck(): void
    {
        $this->assertSame(2.5, $this->host->extractFloat([], 'ratio', required: true, default: 2.5, context: []));
    }

    public function testExtractStringNonNullDefaultShortCircuitsRequiredCheck(): void
    {
        $this->assertSame('fallback', $this->host->extractString(
            [],
            'name',
            required: true,
            default: 'fallback',
            context: [],
        ));
    }

    public function testExtractStringEmptyStringDefaultShortCircuitsRequiredCheck(): void
    {
        // Regression guard: an empty string is a non-null default and must
        // therefore short-circuit the required check. A prior implementation
        // coalesced `$default ?? ''` unconditionally, which hid this case.
        $this->assertSame('', $this->host->extractString([], 'name', required: true, default: '', context: []));
    }

    public function testExtractBoolFalseDefaultShortCircuitsRequiredCheck(): void
    {
        // `false` is non-null, so it must be treated as an authoritative
        // fallback that overrides the required check.
        $this->assertFalse($this->host->extractBool([], 'active', required: true, default: false, context: []));
    }

    public function testExtractBoolTrueDefaultShortCircuitsRequiredCheck(): void
    {
        $this->assertTrue($this->host->extractBool([], 'active', required: true, default: true, context: []));
    }

    public function testExtractArrayEmptyArrayDefaultShortCircuitsRequiredCheck(): void
    {
        // Empty array is non-null — it counts as an authoritative default.
        $this->assertSame([], $this->host->extractArray([], 'tags', required: true, default: [], context: []));
    }

    public function testExtractArrayNonEmptyDefaultShortCircuitsRequiredCheck(): void
    {
        $this->assertSame(
            ['a', 'b'],
            $this->host->extractArray([], 'tags', required: true, default: ['a', 'b'], context: []),
        );
    }

    public function testExtractNullableStringNonNullDefaultShortCircuitsRequiredCheck(): void
    {
        // Also verify the short-circuit on nullable variants: a non-null
        // default must win over required=true, even though the method's
        // return type allows null.
        $this->assertSame('fallback', $this->host->extractNullableString(
            [],
            'name',
            required: true,
            default: 'fallback',
            context: [],
        ));
    }

    public function testExtractNullableIntNullDefaultStillFiresRequiredCheck(): void
    {
        // On the nullable variant, a null default is still "no default at
        // all" — so the required check must still fire when the key is
        // missing. This is the signalling mechanism the chained-call
        // pattern relies on: the inner nullable call returns `null` when
        // its fallback key is missing, and that `null` drives the outer
        // call's required / default decision.
        $this->expectException(MissingRequiredFieldException::class);

        $this->host->extractNullableInt([], 'age', required: true, default: null, context: []);
    }

    public function testNonNullDefaultDoesNotSuppressTypeMismatchWhenKeyIsPresent(): void
    {
        // The short-circuit only applies when the key is ABSENT. When the
        // key is present with a wrong-typed value, the default is ignored
        // and the usual strict-mode TypeMismatchException is thrown.
        $this->expectException(TypeMismatchException::class);

        $this->host->extractInt(['age' => 'oops'], 'age', required: true, default: 42, context: []);
    }

    public function testNonNullDefaultDoesNotSuppressUnexpectedNullWhenKeyIsPresent(): void
    {
        // Likewise, an explicit null in a non-nullable field still throws
        // UnexpectedNullException — the default is only used when the key
        // is entirely missing.
        $this->expectException(UnexpectedNullException::class);

        $this->host->extractInt(['age' => null], 'age', required: true, default: 42, context: []);
    }

    public function testNonNullDefaultIsIgnoredWhenKeyProvidesAValidValue(): void
    {
        // Sanity check: the declared value always wins over the default
        // when the key is present with a valid value.
        $this->assertSame(7, $this->host->extractInt(['age' => 7], 'age', required: true, default: 99, context: []));
    }

    public function testChainedDefaultPatternResolvesViaInnerWhenOuterKeyMissing(): void
    {
        // Simulate the chained-call pattern the generator emits for a
        // `#[SerializedName]` field: the caller passes `required: true` on
        // the outer call, with the inner nullable call's result as
        // `$default`. When the inner resolves a value, the outer must use
        // it regardless of the outer key being absent.
        $inner = $this->host->extractNullableString(
            ['emailAddress' => 'inner@example.com'],
            'emailAddress',
            required: false,
            default: null,
            context: [],
        );

        $this->assertSame('inner@example.com', $this->host->extractString(
            ['emailAddress' => 'inner@example.com'],
            'email_address',
            required: true,
            default: $inner,
            context: [],
        ));
    }

    public function testChainedDefaultPatternThrowsUnderCanonicalKeyWhenBothKeysMissing(): void
    {
        // With both keys missing, the inner nullable call resolves to
        // null; that null drives the outer call's required check, which
        // must throw under the OUTER (canonical) key name.
        $inner = $this->host->extractNullableString([], 'emailAddress', required: false, default: null, context: []);

        try {
            $this->host->extractString([], 'email_address', required: true, default: $inner, context: []);
            $this->fail('Expected MissingRequiredFieldException.');
        } catch (MissingRequiredFieldException $e) {
            $this->assertSame('email_address', $e->getFieldName());
        }
    }
}
