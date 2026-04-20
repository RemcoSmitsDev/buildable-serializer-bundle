<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Exception;

use Symfony\Component\Serializer\Exception\RuntimeException as SerializerRuntimeException;

/**
 * Thrown during denormalization when a null value is provided for a field
 * whose declared type is not nullable.
 *
 * This differs from {@see TypeMismatchException} in that it specifically
 * signals an unexpected null, which callers may wish to handle differently
 * (e.g. by falling back to a default value).
 */
final class UnexpectedNullException extends SerializerRuntimeException
{
    public function __construct(
        private readonly string $fieldName,
        private readonly string $expectedType,
        ?string $className = null,
        ?\Throwable $previous = null,
    ) {
        $message = $className !== null
            ? sprintf(
                'The field "%s" of class "%s" expects a non-null value of type "%s", but null was given.',
                $fieldName,
                $className,
                $expectedType,
            )
            : sprintf(
                'The field "%s" expects a non-null value of type "%s", but null was given.',
                $fieldName,
                $expectedType,
            );

        parent::__construct($message, 0, $previous);
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getExpectedType(): string
    {
        return $this->expectedType;
    }
}
