<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Exception;

use Symfony\Component\Serializer\Exception\RuntimeException as SerializerRuntimeException;

/**
 * Thrown during denormalization when a required field is missing from the
 * input data and no default value is available.
 */
final class MissingRequiredFieldException extends SerializerRuntimeException
{
    public function __construct(
        private readonly string $fieldName,
        ?string $className = null,
        ?\Throwable $previous = null,
    ) {
        $message = $className !== null
            ? sprintf('The required field "%s" for class "%s" is missing from the input data.', $fieldName, $className)
            : sprintf('The required field "%s" is missing from the input data.', $fieldName);

        parent::__construct($message, 0, $previous);
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }
}
