<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Metadata;

/**
 * Holds all serialization-relevant metadata for a single PHP class.
 *
 * Instances of this class are produced by {@see MetadataFactory} through
 * reflection and Symfony PropertyInfo extraction. The code generator consumes
 * them to emit optimised, dedicated normalizer classes at build time.
 *
 * @template-covariant T of object
 */
final class ClassMetadata implements \Stringable
{
    public function __construct(
        /** @var \ReflectionClass<T> */
        public \ReflectionClass $reflectionClass,
        /** @var class-string<T> */
        public string $className = '',
        /** @var PropertyMetadata[] */
        public array $properties = [],
    ) {}

    // -------------------------------------------------------------------------
    // Convenience helpers
    // -------------------------------------------------------------------------

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
     * Returns a property metadata object by its PHP property name, or null
     * when no such property has been registered.
     */
    public function getProperty(string $name): ?PropertyMetadata
    {
        foreach ($this->properties as $property) {
            if ($property->getName() === $name) {
                return $property;
            }
        }

        return null;
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

    /**
     * Returns a human-readable summary of this metadata, useful for debugging
     * and verbose command output.
     */
    public function __toString(): string
    {
        return sprintf('ClassMetadata(%s, %d properties)', $this->className, \count($this->properties));
    }
}
