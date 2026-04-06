<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Metadata;

/**
 * Describes how a property value is read from an object during normalization.
 *
 * - {@see self::METHOD}   – the value is retrieved by calling a public method
 *                           (a getter, isser, or hasser) on the object.
 * - {@see self::PROPERTY} – the value is read directly from a public property
 *                           (including promoted constructor parameters).
 */
enum AccessorType: string
{
    /**
     * Access via a public method call: $object->getName(), $object->isActive(), etc.
     */
    case METHOD = "METHOD";

    /**
     * Access via a public property read: $object->name, $object->active, etc.
     */
    case PROPERTY = "PROPERTY";

    /**
     * Return the PHP expression string used to read the value from $object.
     *
     * @param string $accessor The method or property name.
     * @param string $variable The variable name representing the object (without $).
     */
    public function toExpression(
        string $accessor,
        string $variable = "object",
    ): string {
        return match ($this) {
            self::METHOD => sprintf('$%s->%s()', $variable, $accessor),
            self::PROPERTY => sprintf('$%s->%s', $variable, $accessor),
        };
    }

    /**
     * Return whether this accessor type requires a callable invocation.
     */
    public function isMethod(): bool
    {
        return $this === self::METHOD;
    }

    /**
     * Return whether this accessor type is a direct property read.
     */
    public function isProperty(): bool
    {
        return $this === self::PROPERTY;
    }

    /**
     * Attempt to create an AccessorType from a legacy string constant.
     * Accepts both upper-case and mixed-case variants for convenience.
     *
     * @throws \ValueError When the string does not map to a known accessor type.
     */
    public static function fromString(string $value): self
    {
        return self::from(strtoupper($value));
    }
}
