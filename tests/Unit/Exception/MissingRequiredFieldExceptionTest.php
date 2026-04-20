<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException;
use Symfony\Component\Serializer\Exception\RuntimeException as SerializerRuntimeException;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException
 */
final class MissingRequiredFieldExceptionTest extends TestCase
{
    public function testIsSerializerRuntimeException(): void
    {
        $exception = new MissingRequiredFieldException('name');

        $this->assertInstanceOf(SerializerRuntimeException::class, $exception);
    }

    public function testStoresFieldName(): void
    {
        $exception = new MissingRequiredFieldException('email');

        $this->assertSame('email', $exception->getFieldName());
    }

    public function testMessageIncludesFieldNameWhenClassNameIsNull(): void
    {
        $exception = new MissingRequiredFieldException('email');

        $this->assertStringContainsString('email', $exception->getMessage());
        $this->assertStringContainsString('required', $exception->getMessage());
    }

    public function testMessageIncludesClassNameWhenProvided(): void
    {
        $exception = new MissingRequiredFieldException('email', 'App\\Entity\\User');

        $this->assertStringContainsString('email', $exception->getMessage());
        $this->assertStringContainsString('App\\Entity\\User', $exception->getMessage());
    }

    public function testMessageOmitsClassNameWhenNull(): void
    {
        $exception = new MissingRequiredFieldException('email');

        $this->assertStringNotContainsString('for class', $exception->getMessage());
    }

    public function testPreservesPreviousException(): void
    {
        $previous = new \RuntimeException('boom');
        $exception = new MissingRequiredFieldException('email', null, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testPreservesPreviousExceptionWithClassName(): void
    {
        $previous = new \RuntimeException('boom');
        $exception = new MissingRequiredFieldException('email', 'App\\Entity\\User', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCodeDefaultsToZero(): void
    {
        $exception = new MissingRequiredFieldException('email');

        $this->assertSame(0, $exception->getCode());
    }

    public function testFieldNameWithSpecialCharacters(): void
    {
        $exception = new MissingRequiredFieldException('user.email_address');

        $this->assertSame('user.email_address', $exception->getFieldName());
        $this->assertStringContainsString('user.email_address', $exception->getMessage());
    }

    public function testEmptyFieldName(): void
    {
        $exception = new MissingRequiredFieldException('');

        $this->assertSame('', $exception->getFieldName());
    }
}
