<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException;
use Symfony\Component\Serializer\Exception\RuntimeException as SerializerRuntimeException;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException
 */
final class TypeMismatchExceptionTest extends TestCase
{
    public function testIsSerializerRuntimeException(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string');

        $this->assertInstanceOf(SerializerRuntimeException::class, $exception);
    }

    public function testStoresFieldName(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string');

        $this->assertSame('age', $exception->getFieldName());
    }

    public function testStoresExpectedType(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string');

        $this->assertSame('int', $exception->getExpectedType());
    }

    public function testStoresActualType(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string');

        $this->assertSame('string', $exception->getActualType());
    }

    public function testMessageIncludesAllFieldsWhenClassNameIsNull(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string');

        $this->assertStringContainsString('age', $exception->getMessage());
        $this->assertStringContainsString('int', $exception->getMessage());
        $this->assertStringContainsString('string', $exception->getMessage());
    }

    public function testMessageIncludesClassNameWhenProvided(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string', 'App\\Entity\\User');

        $this->assertStringContainsString('age', $exception->getMessage());
        $this->assertStringContainsString('int', $exception->getMessage());
        $this->assertStringContainsString('string', $exception->getMessage());
        $this->assertStringContainsString('App\\Entity\\User', $exception->getMessage());
    }

    public function testMessageOmitsClassNameWhenNull(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string');

        $this->assertStringNotContainsString('of class', $exception->getMessage());
    }

    public function testPreservesPreviousException(): void
    {
        $previous = new \RuntimeException('boom');
        $exception = new TypeMismatchException('age', 'int', 'string', null, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testPreservesPreviousExceptionWithClassName(): void
    {
        $previous = new \RuntimeException('boom');
        $exception = new TypeMismatchException('age', 'int', 'string', 'App\\Entity\\User', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCodeDefaultsToZero(): void
    {
        $exception = new TypeMismatchException('age', 'int', 'string');

        $this->assertSame(0, $exception->getCode());
    }

    public function testComplexTypeDescriptors(): void
    {
        $exception = new TypeMismatchException('tags', 'array<App\Entity\Tag>', 'string ("foo") with fractional part');

        $this->assertSame('array<App\Entity\Tag>', $exception->getExpectedType());
        $this->assertSame('string ("foo") with fractional part', $exception->getActualType());
        $this->assertStringContainsString('array<App\Entity\Tag>', $exception->getMessage());
        $this->assertStringContainsString('string ("foo") with fractional part', $exception->getMessage());
    }

    public function testEmptyFieldName(): void
    {
        $exception = new TypeMismatchException('', 'int', 'string');

        $this->assertSame('', $exception->getFieldName());
    }
}
