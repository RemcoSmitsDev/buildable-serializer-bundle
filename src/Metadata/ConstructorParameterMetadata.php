<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Metadata;

/**
 * Holds denormalization-relevant metadata for a single constructor parameter
 * of a class.
 *
 * Instances of this class are produced by {@see ConstructorMetadataExtractor}
 * through reflection and Symfony PropertyInfo extraction. The denormalizer
 * code generator consumes them to emit explicit constructor-invocation code
 * that passes values extracted from the input data (or falls back to defaults)
 * to `new ClassName(...)`.
 *
 * ### Field semantics
 *
 *   - `$name` is the PHP parameter name as declared in the constructor signature.
 *   - `$serializedName` is the key under which the value is expected in the
 *     input data. It defaults to `$name` but can be overridden via the
 *     {@see \Symfony\Component\Serializer\Attribute\SerializedName} attribute
 *     placed either on the parameter itself (for promoted parameters) or on
 *     the same-named class property (for both promoted and non-promoted
 *     parameters).
 *   - `$type` is the resolved PHP type string (e.g. "string", "int",
 *     "App\Entity\Address"). Null when the type cannot be determined.
 *   - `$isRequired` is true when the parameter has no default value AND is not
 *     nullable. A nullable-without-default parameter is still "required" in the
 *     sense that the field must be present in the input data, but null is an
 *     acceptable value.
 *   - `$hasDefault` mirrors `\ReflectionParameter::isDefaultValueAvailable()`.
 *     When true, `$defaultValue` carries the actual default obtained from
 *     reflection and must be preserved in the generated code.
 *   - `$isPromoted` is true for constructor-promoted parameters (PHP 8.0+).
 *     These can double as property definitions and may carry property-level
 *     attributes such as #[Groups] and #[Ignore].
 *
 * ### Serializer-attribute fields
 *
 * The following fields mirror {@see PropertyMetadata}'s attribute fields but
 * apply to a constructor parameter. The extractor populates them by merging
 * attributes found on the parameter itself with those found on the class
 * property that shares the parameter's name, so that serializer attributes
 * placed on either location are honored identically.
 *
 *   - `$groups` — serialization groups declared via {@see \Symfony\Component\Serializer\Attribute\Groups}.
 *   - `$ignored` — whether {@see \Symfony\Component\Serializer\Attribute\Ignore} has been applied.
 *   - `$maxDepth` — max-depth limit declared via {@see \Symfony\Component\Serializer\Attribute\MaxDepth}.
 *   - `$contexts` — per-property context entries declared via {@see \Symfony\Component\Serializer\Attribute\Context}.
 */
final class ConstructorParameterMetadata
{
    /**
     * @param string              $name                The parameter name as declared in the constructor signature.
     * @param string              $serializedName      The key to look up in the input data (defaults to $name).
     * @param string|null         $type                Resolved PHP type string, or null when unknown.
     * @param bool                $isNested            Whether the type is a non-scalar class that must be delegated.
     * @param bool                $isCollection        Whether the parameter holds a collection (array/iterable).
     * @param string|null         $collectionValueType FQCN of the collection's value type, when known.
     * @param bool                $isRequired          True when the field must be present in data (no default, not nullable).
     * @param bool                $hasDefault          True when the parameter has a default value available via reflection.
     * @param mixed               $defaultValue        The default value extracted from reflection (only meaningful when $hasDefault is true).
     * @param bool                $isNullable          True when the declared type allows null (`?T` or `T|null`).
     * @param bool                $isPromoted          True for constructor-promoted parameters (PHP 8.0+).
     * @param bool                $isVariadic          True when the parameter is declared with the `...` variadic operator.
     * @param string[]            $groups              Serialization groups collected from the parameter and its linked property.
     * @param bool                $ignored             Whether the parameter has been marked as ignored via #[Ignore].
     * @param int|null            $maxDepth            Max-depth limit from #[MaxDepth], or null when none.
     * @param PropertyContext[]   $contexts            Context entries from #[Context], repeatable.
     */
    public function __construct(
        private readonly string $name,
        private readonly string $serializedName,
        private readonly ?string $type = null,
        private readonly bool $isNested = false,
        private readonly bool $isCollection = false,
        private readonly ?string $collectionValueType = null,
        private readonly bool $isRequired = true,
        private readonly bool $hasDefault = false,
        private readonly mixed $defaultValue = null,
        private readonly bool $isNullable = false,
        private readonly bool $isPromoted = false,
        private readonly bool $isVariadic = false,
        private readonly array $groups = [],
        private readonly bool $ignored = false,
        private readonly ?int $maxDepth = null,
        private readonly array $contexts = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSerializedName(): string
    {
        return $this->serializedName;
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

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function isNullable(): bool
    {
        return $this->isNullable;
    }

    public function isPromoted(): bool
    {
        return $this->isPromoted;
    }

    public function isVariadic(): bool
    {
        return $this->isVariadic;
    }

    /**
     * Return the serialization groups collected from this parameter and its
     * linked class property.
     *
     * @return string[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Return true when the parameter (or its linked property) is annotated
     * with `#[Ignore]`.
     *
     * Ignored constructor parameters are still required for instantiation —
     * the denormalizer generator must pass a value for them — but callers
     * may choose to skip them during population of a pre-existing object.
     */
    public function isIgnored(): bool
    {
        return $this->ignored;
    }

    /**
     * Return the max-depth limit collected from `#[MaxDepth]`, or null when
     * no limit has been declared.
     */
    public function getMaxDepth(): ?int
    {
        return $this->maxDepth;
    }

    /**
     * Return all context entries collected from repeated `#[Context]`
     * attributes on the parameter or its linked property.
     *
     * @return PropertyContext[]
     */
    public function getContexts(): array
    {
        return $this->contexts;
    }

    /**
     * Return true when at least one `#[Context]` attribute has been
     * declared for this parameter.
     */
    public function hasContexts(): bool
    {
        return $this->contexts !== [];
    }
}
