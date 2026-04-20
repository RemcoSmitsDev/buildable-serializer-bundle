<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Metadata;

/**
 * Classifies the different strategies by which a value can be written back
 * to a property of a class during denormalization.
 *
 * The denormalizer generator uses this enum to decide which PHP expression
 * to emit in the generated `populate()` method for each property.
 *
 * ### Strategies
 *
 *   - {@see self::CONSTRUCTOR}
 *     The property is populated exclusively through the class constructor
 *     (e.g. a promoted, readonly parameter with no public setter/wither).
 *     The generator MUST skip it during the population phase because it has
 *     already been provided when the object was instantiated.
 *
 *   - {@see self::PROPERTY}
 *     The property is a plain public property and can be written to directly:
 *
 *     ```php
 *     $object->name = $value;
 *     ```
 *
 *   - {@see self::SETTER}
 *     A public setter method (returning `void` or the same instance) exists
 *     and should be invoked to mutate the object in place:
 *
 *     ```php
 *     $object->setName($value);
 *     ```
 *
 *   - {@see self::WITHER}
 *     A public wither method (returning `self` / `static` / the class type)
 *     exists. Withers are used by immutable objects and return a NEW instance
 *     with the updated value. The generator must reassign the object reference:
 *
 *     ```php
 *     $object = $object->withName($value);
 *     ```
 *
 *   - {@see self::NONE}
 *     No write strategy is available for this property. The denormalizer
 *     generator should skip the property entirely (after logging or ignoring
 *     it at the factory level).
 */
enum MutatorType: string
{
    case CONSTRUCTOR = 'CONSTRUCTOR';
    case PROPERTY = 'PROPERTY';
    case SETTER = 'SETTER';
    case WITHER = 'WITHER';
    case NONE = 'NONE';

    /**
     * Return true when the mutator reassigns the object reference (wither
     * pattern). Callers use this to decide whether to emit
     * `$object = $object->withX($value)` versus `$object->setX($value)`.
     */
    public function reassignsObject(): bool
    {
        return $this === self::WITHER;
    }

    /**
     * Return true when the mutator is a callable method invocation.
     */
    public function isMethod(): bool
    {
        return $this === self::SETTER || $this === self::WITHER;
    }

    /**
     * Return true when the mutator is a direct property assignment.
     */
    public function isProperty(): bool
    {
        return $this === self::PROPERTY;
    }

    /**
     * Return true when the property is not writable during the population
     * phase (either because it was already provided via the constructor or
     * because no write strategy could be discovered).
     */
    public function isSkippedDuringPopulation(): bool
    {
        return $this === self::CONSTRUCTOR || $this === self::NONE;
    }

    /**
     * Attempt to create a MutatorType from a legacy string constant.
     *
     * Accepts both upper- and mixed-case variants for convenience.
     *
     * @throws \ValueError When the string does not map to a known mutator type.
     */
    public static function fromString(string $value): self
    {
        return self::from(strtoupper($value));
    }
}
