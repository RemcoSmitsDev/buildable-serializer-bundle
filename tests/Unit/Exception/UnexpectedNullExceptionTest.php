<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException;
use Symfony\Component\Serializer\Exception\RuntimeException as SerializerRuntimeException;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException
 */
final class UnexpectedNullExceptionTest extends TestCase
{
    public function testIsSerializerRuntimeException(): void
    {
        $exception = new UnexpectedNullException('name', 'string');

        $this->assertInstanceOf(SerializerRuntimeException::class, $exception);
    }

    public function testStoresFieldName(): void
    {
        $exception = new UnexpectedNullException('name', 'string');

        $this->assertSame('name', $exception->getFieldName());
    }

    public function testStoresExpectedType(): void
    {
        $exception = new UnexpectedNullException('name', 'string');

        $this->assertSame('string', $exception->getExpectedType());
    }

    public function testMessageIncludesFieldAndTypeWhenClassNameIsNull(): void
    {
        $exception = new UnexpectedNullException('name', 'string');

        $this->assertStringContainsString('name', $exception->getMessage());
        $this->assertStringContainsString('string', $exception->getMessage());
        $this->assertStringContainsString('null', $exception->getMessage());
    }

    public function testMessageIncludesClassNameWhenProvided(): void
    {
        $exception = new UnexpectedNullException('name', 'string', 'App\\Entity\\User');

        $this->assertStringContainsString('name', $exception->getMessage());
        $this->assertStringContainsString('string', $exception->getMessage());
        $this->assertStringContainsString('App\\Entity\\User', $exception->getMessage());
    }

    public function testMessageOmitsClassNameWhenNull(): void
    {
        $exception = new UnexpectedNullException('name', 'string');

        $this->assertStringNotContainsString('of class', $exception->getMessage());
    }

    public function testPreservesPreviousException(): void
    {
        $previous = new \RuntimeException('boom');
        $exception = new UnexpectedNullException('name', 'string', null, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testPreservesPreviousExceptionWithClassName(): void
    {
        $previous = new \RuntimeException('boom');
        $exception = new UnexpectedNullException('name', 'string', 'App\\Entity\\User', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCodeDefaultsToZero(): void
    {
        $exception = new UnexpectedNullException('name', 'string');

        $this->assertSame(0, $exception->getCode());
    }

    public function testObjectExpectedType(): void
    {
        $exception = new UnexpectedNullException('address', 'App\\Entity\\Address');

        $this->assertSame('App\\Entity\\Address', $exception->getExpectedType());
        $this->assertStringContainsString('App\\Entity\\Address', $exception->getMessage());
    }

    public function testEmptyFieldName(): void
    {
        $exception = new UnexpectedNullException('', 'string');

        $this->assertSame('', $exception->getFieldName());
    }

    public function testMessageMentionsNonNullExpectation(): void
    {
        $exception = new UnexpectedNullException('name', 'string');

        // The whole point of this exception is to signal that null was
        // provided when a non-null value was expected, so the message
        // must make that expectation clear.
        $this->assertStringContainsString('non-null', $exception->getMessage());
    }
}
