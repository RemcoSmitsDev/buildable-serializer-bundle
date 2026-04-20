<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Exception;

use Symfony\Component\Serializer\Exception\RuntimeException as SerializerRuntimeException;

/**
 * Thrown during denormalization (in strict mode) when the value for a given
 * field does not match the expected type and type enforcement is enabled.
 *
 * This exception is only thrown when `AbstractNormalizer::DISABLE_TYPE_ENFORCEMENT`
 * is not set to true in the context. When type enforcement is disabled the
 * denormalizer will attempt to coerce the value to the expected type instead.
 */
final class TypeMismatchException extends SerializerRuntimeException
{
    public function __construct(
        private readonly string $fieldName,
        private readonly string $expectedType,
        private readonly string $actualType,
        ?string $className = null,
        ?\Throwable $previous = null,
    ) {
        $message = $className !== null
            ? sprintf(
                'The field "%s" of class "%s" expects a value of type "%s", but "%s" was given.',
                $fieldName,
                $className,
                $expectedType,
                $actualType,
            )
            : sprintf(
                'The field "%s" expects a value of type "%s", but "%s" was given.',
                $fieldName,
                $expectedType,
                $actualType,
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

    public function getActualType(): string
    {
        return $this->actualType;
    }
}
