<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Metadata;

/**
 * Holds all serialization-relevant metadata for a single property of a class.
 *
 * Instances of this class are built by {@see MetadataFactory} through reflection
 * and Symfony PropertyInfo extraction. They are consumed by the code generator
 * to produce optimised normalizer classes at build time.
 */
final class PropertyMetadata
{
    /**
     * @param string $name The property's original name as declared in the PHP class.
     * @param string|null $serializedName The name to use in the serialized output.
     *                                    When null the property's original name (possibly transformed by a name
     *                                    converter) is used.
     *                                    Populated from the {@see \Symfony\Component\Serializer\Attribute\SerializedName} attribute.
     * @param string[] $groups The serialization groups this property belongs to.
     *                         An empty array means the property is included in every group (no filtering).
     *                         Populated from the {@see \Symfony\Component\Serializer\Attribute\Groups} attribute.
     * @param bool $ignored When true this property must be skipped entirely during normalization /
     *                      denormalization.
     *                      Populated from the {@see \Symfony\Component\Serializer\Attribute\Ignore} attribute.
     * @param string|null $type The resolved PHP type string for this property (e.g. "string", "int",
     *                          "float", "bool", "array", "DateTimeImmutable", "App\Entity\User").
     *                          Null when the type cannot be determined.
     * @param bool $isNested Whether the property's type is a non-scalar, non-collection object that
     *                       requires its own normalizer to be invoked recursively.
     *                       A property is considered nested when its resolved type is a class name
     *                       that is not one of the built-in PHP scalar or pseudo-types.
     * @param bool $isCollection Whether the property holds a collection of values (typed as "array" or
     *                           "iterable", or detected via docblock as Type[]).
     * @param string|null $collectionValueType The fully-qualified class name of the collection's value type.
     *                                         Only populated when {@see $isCollection} is true and the element type
     *                                         could be resolved (e.g. from "@var array<App\Entity\Tag>" or "Tag[]").
     * @param string $accessor The name of the method or property used to read the value during
     *                         normalization (e.g. "getName", "isActive", "publicField").
     * @param AccessorType $accessorType How the accessor is resolved at runtime.
     *                                   - "METHOD"   – call $object->{accessor}()
     *                                   - "PROPERTY" – read $object->{accessor} directly
     * @param int|null $maxDepth The maximum serialization depth for this property.
     *                           Null means no depth limit is enforced.
     *                           Populated from the {@see \Symfony\Component\Serializer\Attribute\MaxDepth} attribute.
     * @param bool $nullable Whether the property's type is nullable (declared as "?Type" or
     *                       "Type|null").
     * @param bool $isReadonly Whether the underlying class property was declared with the "readonly"
     *                         modifier (PHP 8.1+).
     * @param PropertyContext[] $contexts Context configurations for this property.
     *                                    Populated from the {@see \Symfony\Component\Serializer\Attribute\Context} attribute.
     *                                    Multiple contexts can be present when the attribute is repeated with different groups.
     */
    public function __construct(
        private string $name = '',
        private ?string $serializedName = null,
        private array $groups = [],
        private bool $ignored = false,
        private ?string $type = null,
        private bool $isNested = false,
        private bool $isCollection = false,
        private ?string $collectionValueType = null,
        private string $accessor = '',
        private AccessorType $accessorType = AccessorType::METHOD,
        private ?int $maxDepth = null,
        private bool $nullable = false,
        private bool $isReadonly = false,
        private array $contexts = [],
        private MutatorType $mutatorType = MutatorType::NONE,
        private ?string $mutator = null,
    ) {}

    /**
     * Return the strategy by which this property can be written to during
     * denormalization. See {@see MutatorType} for the possible values.
     */
    public function getMutatorType(): MutatorType
    {
        return $this->mutatorType;
    }

    /**
     * Set the mutator strategy. Intended to be called by {@see MetadataFactory}
     * after initial construction, once setters/withers have been discovered.
     */
    public function setMutatorType(MutatorType $mutatorType): void
    {
        $this->mutatorType = $mutatorType;
    }

    /**
     * Return the method or property name used to write this property's value,
     * or null when the mutator strategy is {@see MutatorType::CONSTRUCTOR}
     * or {@see MutatorType::NONE}.
     *
     * Examples:
     *   - PROPERTY → `"name"` (i.e. `$object->name = $value`)
     *   - SETTER   → `"setName"` (i.e. `$object->setName($value)`)
     *   - WITHER   → `"withName"` (i.e. `$object = $object->withName($value)`)
     */
    public function getMutator(): ?string
    {
        return $this->mutator;
    }

    /**
     * Set the mutator method/property name. Intended to be called by
     * {@see MetadataFactory} after initial construction.
     */
    public function setMutator(?string $mutator): void
    {
        $this->mutator = $mutator;
    }

    /**
     * Return true when this property can be written to in some way during
     * the denormalization population phase.
     */
    public function hasMutator(): bool
    {
        return $this->mutatorType !== MutatorType::NONE && $this->mutatorType !== MutatorType::CONSTRUCTOR;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSerializedName(): ?string
    {
        return $this->serializedName;
    }

    /**
     * @return string[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    public function isIgnored(): bool
    {
        return $this->ignored;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function isNested(): bool
    {
        return $this->isNested;
    }

    public function isCollection(): bool
    {
        return $this->isCollection;
    }

    public function getCollectionValueType(): ?string
    {
        return $this->collectionValueType;
    }

    public function getAccessor(): string
    {
        return $this->accessor;
    }

    public function getAccessorType(): AccessorType
    {
        return $this->accessorType;
    }

    public function getMaxDepth(): ?int
    {
        return $this->maxDepth;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isReadonly(): bool
    {
        return $this->isReadonly;
    }

    /**
     * Returns all context configurations for this property.
     *
     * @return PropertyContext[]
     */
    public function getContexts(): array
    {
        return $this->contexts;
    }

    /**
     * Returns true if this property has any context configurations.
     */
    public function hasContexts(): bool
    {
        return $this->contexts !== [];
    }

    /**
     * Returns the merged normalization context for this property, considering active groups.
     * Later contexts override earlier ones when keys conflict.
     *
     * @param string[] $activeGroups The currently active serialization groups.
     * @return array<string, mixed>
     */
    public function getNormalizationContext(array $activeGroups = []): array
    {
        $mergedContext = [];

        foreach ($this->contexts as $context) {
            if ($context->isApplicableForGroups($activeGroups) && $context->hasNormalizationContext()) {
                $mergedContext = array_merge($mergedContext, $context->getNormalizationContext());
            }
        }

        return $mergedContext;
    }

    /**
     * Returns the merged denormalization context for this property, considering active groups.
     * Later contexts override earlier ones when keys conflict.
     *
     * @param string[] $activeGroups The currently active serialization groups.
     * @return array<string, mixed>
     */
    public function getDenormalizationContext(array $activeGroups = []): array
    {
        $mergedContext = [];

        foreach ($this->contexts as $context) {
            if ($context->isApplicableForGroups($activeGroups) && $context->hasDenormalizationContext()) {
                $mergedContext = array_merge($mergedContext, $context->getDenormalizationContext());
            }
        }

        return $mergedContext;
    }

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
}
