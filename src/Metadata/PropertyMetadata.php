<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Metadata;

/**
 * Holds all serialization-relevant metadata for a single property of a class.
 *
 * Instances of this class are built by {@see MetadataFactory} through reflection
 * and Symfony PropertyInfo extraction. They are consumed by the code generator
 * to produce optimised normalizer classes at build time.
 */
final class PropertyMetadata implements \Stringable
{
    /**
     * The property's original name as declared in the PHP class.
     */
    public string $name = '';

    /**
     * The name to use in the serialized output.
     * When null the property's original name (possibly transformed by a name
     * converter) is used.
     *
     * Populated from the {@see \Symfony\Component\Serializer\Attribute\SerializedName} attribute.
     */
    public ?string $serializedName = null;

    /**
     * The serialization groups this property belongs to.
     * An empty array means the property is included in every group (no filtering).
     *
     * Populated from the {@see \Symfony\Component\Serializer\Attribute\Groups} attribute.
     *
     * @var string[]
     */
    public array $groups = [];

    /**
     * When true this property must be skipped entirely during normalization /
     * denormalization.
     *
     * Populated from the {@see \Symfony\Component\Serializer\Attribute\Ignore} attribute.
     */
    public bool $ignored = false;

    /**
     * The resolved PHP type string for this property (e.g. "string", "int",
     * "float", "bool", "array", "DateTimeImmutable", "App\Entity\User").
     * Null when the type cannot be determined.
     */
    public ?string $type = null;

    /**
     * Whether the property's type is a non-scalar, non-collection object that
     * requires its own normalizer to be invoked recursively.
     *
     * A property is considered nested when its resolved type is a class name
     * that is not one of the built-in PHP scalar or pseudo-types.
     */
    public bool $isNested = false;

    /**
     * Whether the property holds a collection of values (typed as "array" or
     * "iterable", or detected via docblock as Type[]).
     */
    public bool $isCollection = false;

    /**
     * The fully-qualified class name of the collection's value type.
     * Only populated when {@see $isCollection} is true and the element type
     * could be resolved (e.g. from "@var array<App\Entity\Tag>" or "Tag[]").
     */
    public ?string $collectionValueType = null;

    /**
     * The name of the method or property used to read the value during
     * normalization (e.g. "getName", "isActive", "publicField").
     */
    public string $accessor = '';

    /**
     * How the accessor is resolved at runtime.
     *
     * - "METHOD"   – call $object->{accessor}()
     * - "PROPERTY" – read $object->{accessor} directly
     */
    public AccessorType $accessorType = AccessorType::METHOD;

    /**
     * The maximum serialization depth for this property.
     * Null means no depth limit is enforced.
     *
     * Populated from the {@see \Symfony\Component\Serializer\Attribute\MaxDepth} attribute.
     */
    public ?int $maxDepth = null;

    /**
     * Whether the property's type is nullable (declared as "?Type" or
     * "Type|null").
     */
    public bool $nullable = false;

    /**
     * Whether the underlying class property was declared with the "readonly"
     * modifier (PHP 8.1+).
     */
    public bool $isReadonly = false;

    // -------------------------------------------------------------------------
    // Convenience helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when this property participates in the given serialization
     * group, or when no groups have been configured at all (open membership).
     */
    public function isInGroup(string $group): bool
    {
        if ($this->groups === []) {
            return true;
        }

        return \in_array($group, $this->groups, true);
    }

    /**
     * Returns true when this property should be included in the output given
     * the active groups list. If no active groups are provided the property is
     * always included (unless it is ignored).
     *
     * @param string[] $activeGroups
     */
    public function isEligibleForGroups(array $activeGroups): bool
    {
        if ($this->ignored) {
            return false;
        }

        if ($activeGroups === [] || $this->groups === []) {
            return true;
        }

        foreach ($activeGroups as $group) {
            if (\in_array($group, $this->groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the serialized key for this property: the explicit serialized
     * name when set, otherwise the plain property name.
     *
     * Note: name-converter transformations are applied at a later stage in the
     * generator, not here.
     */
    public function getSerializedKey(): string
    {
        return $this->serializedName ?? $this->name;
    }

    /**
     * Returns a human-readable representation useful for debugging.
     */
    public function __toString(): string
    {
        return sprintf(
            'PropertyMetadata(%s %s%s, accessor=%s::%s)',
            $this->type ?? 'mixed',
            $this->nullable ? '?' : '',
            $this->name,
            $this->accessorType->value,
            $this->accessor,
        );
    }
}
