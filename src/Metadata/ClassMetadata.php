<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Metadata;

/**
 * Holds all serialization-relevant metadata for a single PHP class.
 *
 * Instances of this class are produced by {@see MetadataFactory} through
 * reflection and Symfony PropertyInfo extraction. The code generator consumes
 * them to emit optimised, dedicated normalizer classes at build time.
 *
 * @template-covariant T of object
 */
final class ClassMetadata
{
    /**
     * @param \ReflectionClass<T> $reflectionClass
     * @param class-string<T> $className
     * @param PropertyMetadata[] $properties
     * @param list<ConstructorParameterMetadata> $constructorParameters
     */
    public function __construct(
        private \ReflectionClass $reflectionClass,
        private string $className,
        private array $properties = [],
        private array $constructorParameters = [],
        private bool $hasConstructor = false,
    ) {}

    /**
     * Returns all constructor parameter metadata, in declaration order.
     *
     * An empty array may mean either that the class has no constructor at all
     * (see {@see hasConstructor()}) or that it has a constructor with zero
     * parameters (e.g. `public function __construct() {}`).
     *
     * @return list<ConstructorParameterMetadata>
     */
    public function getConstructorParameters(): array
    {
        return $this->constructorParameters;
    }

    /**
     * Set the constructor parameter metadata. Intended to be called by
     * {@see MetadataFactory} once the extractor has finished inspecting the
     * class constructor.
     *
     * @param list<ConstructorParameterMetadata> $parameters
     */
    public function setConstructorParameters(array $parameters): void
    {
        $this->constructorParameters = $parameters;
    }

    /**
     * Returns true when the class declares (or inherits) a constructor.
     *
     * Use this in combination with {@see getConstructorParameters()} to
     * distinguish between "no constructor at all" (can instantiate via
     * `new ClassName()`) and "constructor with zero parameters".
     */
    public function hasConstructor(): bool
    {
        return $this->hasConstructor;
    }

    /**
     * Mark whether this class has a constructor. Intended to be called by
     * {@see MetadataFactory} once the extractor has finished inspecting the
     * class constructor.
     */
    public function setHasConstructor(bool $hasConstructor): void
    {
        $this->hasConstructor = $hasConstructor;
    }

    /**
     * Returns true when the class has a constructor with at least one parameter.
     */
    public function hasConstructorParameters(): bool
    {
        return $this->constructorParameters !== [];
    }

    /**
     * Returns true when the class has at least one required constructor
     * parameter (no default value and not nullable).
     */
    public function hasRequiredConstructorParameters(): bool
    {
        foreach ($this->constructorParameters as $param) {
            if ($param->isRequired()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the FQCNs referenced by constructor parameters that are nested
     * objects or typed collections. Useful for "use" statement generation in
     * the denormalizer generator.
     *
     * @return list<string>
     */
    public function getConstructorReferencedClasses(): array
    {
        $types = [];

        foreach ($this->constructorParameters as $param) {
            if ($param->isNested() && $param->getType() !== null) {
                $types[$param->getType()] = $param->getType();
            }

            if ($param->isCollection() && $param->getCollectionValueType() !== null) {
                $types[$param->getCollectionValueType()] = $param->getCollectionValueType();
            }
        }

        return array_values($types);
    }

    /**
     * Returns the fully qualified class name (including namespace).
     *
     * Example: for "App\Entity\User" this returns "App\Entity\User".
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * Returns the unqualified (short) class name.
     *
     * Example: for "App\Entity\User" this returns "User".
     */
    public function getShortName(): string
    {
        return $this->reflectionClass->getShortName();
    }

    /**
     * Returns the namespace of the class, without a trailing backslash.
     *
     * Example: for "App\Entity\User" this returns "App\Entity".
     */
    public function getNamespace(): string
    {
        return $this->reflectionClass->getNamespaceName();
    }

    /**
     * Adds a property metadata object to the class metadata.
     */
    public function addProperty(PropertyMetadata $property): void
    {
        $this->properties[$property->getName()] = $property;
    }

    /**
     * Returns a property metadata object by its PHP property name, or null
     * when no such property has been registered.
     */
    public function getProperty(string $name): ?PropertyMetadata
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Returns an array of all property metadata objects.
     *
     * @return PropertyMetadata[]
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Returns true when at least one property carries group constraints.
     * Useful for the generator to decide whether to emit group-filtering code.
     */
    public function hasGroupConstraints(): bool
    {
        foreach ($this->properties as $property) {
            if ($property->getGroups() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when at least one property defines a max-depth constraint.
     */
    public function hasMaxDepthConstraints(): bool
    {
        foreach ($this->properties as $property) {
            if ($property->getMaxDepth() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when at least one property is a nested object (i.e. its
     * type requires recursive normalizer delegation).
     */
    public function hasNestedObjects(): bool
    {
        foreach ($this->properties as $property) {
            if ($property->isNested()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when at least one property is a collection.
     */
    public function hasCollections(): bool
    {
        foreach ($this->properties as $property) {
            if ($property->isCollection()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns all distinct FQCN types referenced by nested-object properties.
     * The generator uses this list to determine which other normalizers must
     * be injected as dependencies.
     *
     * @return string[]
     */
    public function getNestedClassTypes(): array
    {
        $types = [];

        foreach ($this->properties as $property) {
            if ($property->isNested() && $property->getType() !== null) {
                $types[$property->getType()] = $property->getType();
            }

            if ($property->isCollection() && $property->getCollectionValueType() !== null) {
                $types[$property->getCollectionValueType()] = $property->getCollectionValueType();
            }
        }

        return array_values($types);
    }
}
