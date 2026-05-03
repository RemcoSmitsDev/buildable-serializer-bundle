<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

/**
 * @covers \Symfony\Component\Serializer\Exception\NotNormalizableValueException
 */
final class NotNormalizableValueExceptionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a NotNormalizableValueException that models "null was supplied for a
     * non-nullable field" (the scenario previously represented by UnexpectedNullException).
     *
     * @param list<string> $expectedTypes
     */
    private function makeNullException(
        string $fieldName,
        array $expectedTypes = ['string'],
        string $message = 'The value is null.',
        bool $canUseMessageForUser = true,
        ?\Throwable $previous = null,
    ): NotNormalizableValueException {
        return NotNormalizableValueException::createForUnexpectedDataType(
            $message,
            null,
            $expectedTypes,
            $fieldName,
            $canUseMessageForUser,
            0,
            $previous,
        );
    }

    // -------------------------------------------------------------------------
    // Inheritance
    // -------------------------------------------------------------------------

    public function testIsSymfonyUnexpectedValueException(): void
    {
        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Expected int, got string.',
            'foo',
            ['int'],
            'age',
        );

        $this->assertInstanceOf(UnexpectedValueException::class, $exception);
    }

    // -------------------------------------------------------------------------
    // Path / field name
    // -------------------------------------------------------------------------

    public function testGetPathReturnsSuppliedPath(): void
    {
        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Expected int, got string.',
            'foo',
            ['int'],
            'age',
        );

        $this->assertSame('age', $exception->getPath());
    }

    public function testGetPathReturnsFieldNameForNullScenario(): void
    {
        $e = $this->makeNullException('fieldName');

        $this->assertSame('fieldName', $e->getPath());
    }

    public function testGetPathReturnsEmptyStringWhenFieldNameIsEmpty(): void
    {
        $e = $this->makeNullException('');

        $this->assertSame('', $e->getPath());
    }

    // -------------------------------------------------------------------------
    // Expected types
    // -------------------------------------------------------------------------

    public function testGetExpectedTypesReturnsExpectedTypesArray(): void
    {
        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Expected int, got string.',
            'foo',
            ['int'],
            'age',
        );

        $this->assertSame(['int'], $exception->getExpectedTypes());
    }

    public function testGetExpectedTypesFirstElementWithStringType(): void
    {
        $e = $this->makeNullException('name', ['string']);

        $this->assertSame('string', $e->getExpectedTypes()[0]);
    }

    public function testGetExpectedTypesFirstElementWithIntType(): void
    {
        $e = $this->makeNullException('age', ['int']);

        $this->assertSame('int', $e->getExpectedTypes()[0]);
    }

    public function testGetExpectedTypesFirstElementWithBoolType(): void
    {
        $e = $this->makeNullException('active', ['bool']);

        $this->assertSame('bool', $e->getExpectedTypes()[0]);
    }

    public function testGetExpectedTypesFirstElementWithObjectClassName(): void
    {
        $e = $this->makeNullException('address', ['App\\Entity\\Address']);

        $this->assertSame('App\\Entity\\Address', $e->getExpectedTypes()[0]);
    }

    public function testComplexTypeDescriptors(): void
    {
        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Expected array<App\Entity\Tag>, got string.',
            'foo',
            ['array<App\Entity\Tag>'],
            'tags',
        );

        $this->assertSame(['array<App\Entity\Tag>'], $exception->getExpectedTypes());
        $this->assertStringContainsString('array<App\Entity\Tag>', $exception->getExpectedTypes()[0]);
        $this->assertStringContainsString('array<App\Entity\Tag>', $exception->getMessage());
    }

    // -------------------------------------------------------------------------
    // Current type
    // -------------------------------------------------------------------------

    public function testGetCurrentTypeReturnsDebugTypeOfData(): void
    {
        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Expected int, got string.',
            'foo',
            ['int'],
            'age',
        );

        $this->assertSame(get_debug_type('foo'), $exception->getCurrentType());
    }

    public function testGetCurrentTypeIsNullStringForNullValue(): void
    {
        // get_debug_type(null) returns the string 'null'.
        $e = $this->makeNullException('name');

        $this->assertSame('null', $e->getCurrentType());
    }

    // -------------------------------------------------------------------------
    // Message
    // -------------------------------------------------------------------------

    public function testMessageIsPreserved(): void
    {
        $message = 'Expected int, got string for field age.';

        $exception = NotNormalizableValueException::createForUnexpectedDataType($message, 42, ['string'], 'name');

        $this->assertSame($message, $exception->getMessage());
    }

    public function testMessageIsPreservedForNullScenario(): void
    {
        $e = $this->makeNullException('name', ['string'], 'Custom null message.');

        $this->assertStringContainsString('Custom null message.', $e->getMessage());
    }

    // -------------------------------------------------------------------------
    // Previous exception
    // -------------------------------------------------------------------------

    public function testGetPreviousIsNullByDefault(): void
    {
        $e = $this->makeNullException('name');

        $this->assertNull($e->getPrevious());
    }

    public function testGetPreviousIsPreservedWhenPassed(): void
    {
        $previous = new \RuntimeException('boom');

        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Type mismatch.',
            'foo',
            ['int'],
            'age',
            false,
            0,
            $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testGetPreviousIsPreservedForNullScenario(): void
    {
        $previous = new \RuntimeException('boom');
        $e = $this->makeNullException('name', ['string'], 'msg', true, $previous);

        $this->assertSame($previous, $e->getPrevious());
    }

    // -------------------------------------------------------------------------
    // Code
    // -------------------------------------------------------------------------

    public function testGetCodeDefaultsToZero(): void
    {
        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Type mismatch.',
            'foo',
            ['int'],
            'age',
        );

        $this->assertSame(0, $exception->getCode());
    }

    // -------------------------------------------------------------------------
    // canUseMessageForUser
    // -------------------------------------------------------------------------

    public function testCanUseMessageForUserReturnsTrueWhenEnabled(): void
    {
        $exception = NotNormalizableValueException::createForUnexpectedDataType(
            'Type mismatch.',
            'foo',
            ['int'],
            'age',
            true,
        );

        $this->assertTrue($exception->canUseMessageForUser());
    }

    public function testCanUseMessageForUserReturnsFalseWhenPassedFalse(): void
    {
        $e = $this->makeNullException('name', ['string'], 'msg', false);

        $this->assertFalse($e->canUseMessageForUser());
    }
}
