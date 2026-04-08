<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Metadata;

enum AccessorType: string
{
    case METHOD = 'METHOD';
    case PROPERTY = 'PROPERTY';

    /**
     * Return the PHP expression string used to read the value from $object.
     *
     * @param string $accessor The method or property name.
     * @param string $variable The variable name representing the object (without $).
     */
    public function toExpression(string $accessor, string $variable = 'object'): string
    {
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
