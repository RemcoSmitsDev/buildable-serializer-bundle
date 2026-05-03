<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\RuntimeException as SerializerRuntimeException;

/**
 * @covers \Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException
 */
final class MissingConstructorArgumentsExceptionTest extends TestCase
{
    public function testIsSerializerRuntimeException(): void
    {
        $exception = new MissingConstructorArgumentsException('Missing constructor argument.', 0, null, ['name']);

        $this->assertInstanceOf(SerializerRuntimeException::class, $exception);
    }

    public function testGetMissingConstructorArgumentsReturnsPassedArray(): void
    {
        $exception = new MissingConstructorArgumentsException('Missing arguments.', 0, null, ['email', 'name']);

        $this->assertSame(['email', 'name'], $exception->getMissingConstructorArguments());
    }

    public function testGetMissingConstructorArgumentsReturnsSingleElement(): void
    {
        $exception = new MissingConstructorArgumentsException('Missing arguments.', 0, null, ['email']);

        $this->assertSame(['email'], $exception->getMissingConstructorArguments());
        $this->assertSame('email', $exception->getMissingConstructorArguments()[0]);
    }

    public function testGetClassReturnsPassedClassString(): void
    {
        $exception = new MissingConstructorArgumentsException(
            'Missing arguments.',
            0,
            null,
            ['email'],
            'App\\Entity\\User',
        );

        $this->assertSame('App\\Entity\\User', $exception->getClass());
    }

    public function testGetClassReturnsNullWhenNotProvided(): void
    {
        $exception = new MissingConstructorArgumentsException('Missing arguments.', 0, null, ['email']);

        $this->assertNull($exception->getClass());
    }

    public function testMessageIsPreserved(): void
    {
        $message = 'Cannot create an instance of "App\\Entity\\User" from serialized data because its constructor requires the following parameters to be present : "$email".';
        $exception = new MissingConstructorArgumentsException($message, 0, null, ['email'], 'App\\Entity\\User');

        $this->assertSame($message, $exception->getMessage());
    }

    public function testPreviousIsPreserved(): void
    {
        $previous = new \RuntimeException('boom');
        $exception = new MissingConstructorArgumentsException('Missing arguments.', 0, $previous, ['email']);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCodeDefaultsToZero(): void
    {
        $exception = new MissingConstructorArgumentsException('Missing arguments.', 0, null, ['email']);

        $this->assertSame(0, $exception->getCode());
    }

    public function testEmptyMissingArgumentsArray(): void
    {
        $exception = new MissingConstructorArgumentsException('Missing arguments.', 0, null, []);

        $this->assertSame([], $exception->getMissingConstructorArguments());
    }
}
