<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MutatorType;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MutatorType
 */
final class MutatorTypeTest extends TestCase
{
    public function testAllCasesAreDefined(): void
    {
        $values = array_map(static fn(MutatorType $t): string => $t->value, MutatorType::cases());

        $this->assertContains('CONSTRUCTOR', $values);
        $this->assertContains('PROPERTY', $values);
        $this->assertContains('SETTER', $values);
        $this->assertContains('WITHER', $values);
        $this->assertContains('NONE', $values);
    }

    public function testExactlyFiveCasesExist(): void
    {
        $this->assertCount(5, MutatorType::cases());
    }

    public function testValuesMatchNames(): void
    {
        foreach (MutatorType::cases() as $case) {
            $this->assertSame($case->name, $case->value);
        }
    }

    public function testReassignsObjectIsTrueOnlyForWither(): void
    {
        $this->assertTrue(MutatorType::WITHER->reassignsObject());

        $this->assertFalse(MutatorType::CONSTRUCTOR->reassignsObject());
        $this->assertFalse(MutatorType::PROPERTY->reassignsObject());
        $this->assertFalse(MutatorType::SETTER->reassignsObject());
        $this->assertFalse(MutatorType::NONE->reassignsObject());
    }

    public function testIsMethodIsTrueForSetterAndWither(): void
    {
        $this->assertTrue(MutatorType::SETTER->isMethod());
        $this->assertTrue(MutatorType::WITHER->isMethod());

        $this->assertFalse(MutatorType::CONSTRUCTOR->isMethod());
        $this->assertFalse(MutatorType::PROPERTY->isMethod());
        $this->assertFalse(MutatorType::NONE->isMethod());
    }

    public function testIsPropertyIsTrueOnlyForProperty(): void
    {
        $this->assertTrue(MutatorType::PROPERTY->isProperty());

        $this->assertFalse(MutatorType::CONSTRUCTOR->isProperty());
        $this->assertFalse(MutatorType::SETTER->isProperty());
        $this->assertFalse(MutatorType::WITHER->isProperty());
        $this->assertFalse(MutatorType::NONE->isProperty());
    }

    public function testIsSkippedDuringPopulationIsTrueForConstructorAndNone(): void
    {
        $this->assertTrue(MutatorType::CONSTRUCTOR->isSkippedDuringPopulation());
        $this->assertTrue(MutatorType::NONE->isSkippedDuringPopulation());

        $this->assertFalse(MutatorType::PROPERTY->isSkippedDuringPopulation());
        $this->assertFalse(MutatorType::SETTER->isSkippedDuringPopulation());
        $this->assertFalse(MutatorType::WITHER->isSkippedDuringPopulation());
    }

    public function testMethodAndPropertyAreMutuallyExclusive(): void
    {
        foreach (MutatorType::cases() as $case) {
            $this->assertFalse($case->isMethod() && $case->isProperty(), sprintf(
                'Case %s is both method and property.',
                $case->name,
            ));
        }
    }

    public function testSkippedCasesAreNeitherMethodNorProperty(): void
    {
        foreach (MutatorType::cases() as $case) {
            if (!$case->isSkippedDuringPopulation()) {
                continue;
            }

            $this->assertFalse($case->isMethod(), sprintf('Skipped case %s should not be a method.', $case->name));
            $this->assertFalse($case->isProperty(), sprintf('Skipped case %s should not be a property.', $case->name));
        }
    }

    public function testFromStringAcceptsUpperCase(): void
    {
        $this->assertSame(MutatorType::SETTER, MutatorType::fromString('SETTER'));
        $this->assertSame(MutatorType::WITHER, MutatorType::fromString('WITHER'));
        $this->assertSame(MutatorType::PROPERTY, MutatorType::fromString('PROPERTY'));
        $this->assertSame(MutatorType::CONSTRUCTOR, MutatorType::fromString('CONSTRUCTOR'));
        $this->assertSame(MutatorType::NONE, MutatorType::fromString('NONE'));
    }

    public function testFromStringAcceptsLowerCase(): void
    {
        $this->assertSame(MutatorType::SETTER, MutatorType::fromString('setter'));
        $this->assertSame(MutatorType::WITHER, MutatorType::fromString('wither'));
    }

    public function testFromStringAcceptsMixedCase(): void
    {
        $this->assertSame(MutatorType::SETTER, MutatorType::fromString('Setter'));
        $this->assertSame(MutatorType::PROPERTY, MutatorType::fromString('Property'));
        $this->assertSame(MutatorType::NONE, MutatorType::fromString('None'));
    }

    public function testFromStringThrowsValueErrorForUnknownValue(): void
    {
        $this->expectException(\ValueError::class);

        MutatorType::fromString('UNKNOWN_MUTATOR');
    }

    public function testFromStringThrowsValueErrorForEmptyString(): void
    {
        $this->expectException(\ValueError::class);

        MutatorType::fromString('');
    }

    public function testFromValueRoundTrip(): void
    {
        foreach (MutatorType::cases() as $case) {
            $this->assertSame($case, MutatorType::from($case->value));
        }
    }
}
