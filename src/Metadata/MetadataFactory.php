<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Metadata;

use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Builds {@see ClassMetadata} instances by combining PHP Reflection with
 * Symfony PropertyInfo extraction and Symfony Serializer attributes.
 *
 * ### Accessor discovery order
 *
 *   1. Promoted constructor parameter  → PROPERTY accessor
 *   2. Public getter method (get*, is*, has*) → METHOD accessor
 *   3. Regular public property → PROPERTY accessor
 *
 * ### Type resolution order
 *
 *   1. Symfony PropertyInfo extractors (PhpDocExtractor, ReflectionExtractor)
 *      — provides generic/docblock type info (e.g. `array<App\Entity\Tag>`)
 *   2. Raw `\ReflectionType` declarations as fallback
 *
 * ### Recognised Symfony Serializer attributes
 *
 *   - {@see Groups}       → {@see PropertyMetadata::$groups}
 *   - {@see Ignore}       → {@see PropertyMetadata::$ignored} = true (excluded from result)
 *   - {@see SerializedName} → {@see PropertyMetadata::$serializedName}
 *   - {@see MaxDepth}     → {@see PropertyMetadata::$maxDepth}
 */
final class MetadataFactory implements MetadataFactoryInterface
{
    /**
     * PHP built-in scalar / pseudo-types that are NOT treated as nested objects.
     *
     * @var list<string>
     */
    private const SCALAR_TYPES = [
        "int",
        "integer",
        "float",
        "double",
        "string",
        "bool",
        "boolean",
        "null",
        "array",
        "iterable",
        "callable",
        "resource",
        "mixed",
        "void",
        "never",
        "object",
        "self",
        "static",
        "parent",
    ];

    /**
     * In-memory cache of already-built metadata, keyed by FQCN.
     *
     * @var array<class-string, ClassMetadata<object>>
     */
    private array $cache = [];

    public function __construct(
        private readonly PropertyInfoExtractorInterface $propertyInfoExtractor,
    ) {}

    // -------------------------------------------------------------------------
    // MetadataFactoryInterface
    // -------------------------------------------------------------------------

    /**
     * Build and return fully-populated metadata for the given class name.
     *
     * Results are cached in-memory so repeated calls are cheap.
     *
     * @param class-string $className
     *
     * @throws \InvalidArgumentException When the class does not exist.
     */
    public function getMetadataFor(string $className): ClassMetadata
    {
        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        if (!class_exists($className)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Class "%s" does not exist or cannot be autoloaded.',
                    $className,
                ),
            );
        }

        /** @var \ReflectionClass<object> $reflectionClass */
        $reflectionClass = new \ReflectionClass($className);
        $metadata = $this->buildClassMetadata($reflectionClass);

        return $this->cache[$className] = $metadata;
    }

    /**
     * Return true when the factory can produce metadata for the given class.
     *
     * @param class-string|string $className
     */
    public function hasMetadataFor(string $className): bool
    {
        return isset($this->cache[$className]) || class_exists($className);
    }

    // -------------------------------------------------------------------------
    // Internal builders
    // -------------------------------------------------------------------------

    /**
     * Build a fully-populated ClassMetadata for the given ReflectionClass.
     *
     * @template T of object
     * @param  \ReflectionClass<T> $reflectionClass
     * @return ClassMetadata<T>
     */
    private function buildClassMetadata(
        \ReflectionClass $reflectionClass,
    ): ClassMetadata {
        /** @var ClassMetadata<T> $metadata */
        $metadata = new ClassMetadata(
            reflectionClass: $reflectionClass,
            className: $reflectionClass->getName(),
        );

        /** @var array<string, true> $registered Tracks which property names have already been added */
        $registered = [];

        // ----- 1. Promoted constructor parameters (preserve declaration order) ------
        foreach ($this->collectPromotedParams($reflectionClass) as $param) {
            // Only process promoted parameters that are publicly accessible as properties.
            // Private/protected promoted params cannot be read directly from outside the class;
            // they are discovered via their public getter methods in step 3 below.
            $promotedProperty = $reflectionClass->getProperty(
                $param->getName(),
            );
            if (!$promotedProperty->isPublic()) {
                continue;
            }

            $propertyMeta = $this->buildPropertyMetadataFromPromoted(
                $reflectionClass,
                $param,
            );

            if ($propertyMeta === null || $propertyMeta->ignored) {
                $registered[$param->getName()] = true;
                continue;
            }

            $metadata->properties[] = $propertyMeta;
            $registered[$param->getName()] = true;
        }

        // ----- 2. Regular public properties (non-promoted) -------------------------
        foreach (
            $reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC)
            as $reflProperty
        ) {
            $name = $reflProperty->getName();

            // Skip statics and already-registered promoted params
            if ($reflProperty->isStatic() || isset($registered[$name])) {
                continue;
            }

            $propertyMeta = $this->buildPropertyMetadataFromProperty(
                $reflectionClass,
                $reflProperty,
            );

            if ($propertyMeta === null || $propertyMeta->ignored) {
                $registered[$name] = true;
                continue;
            }

            $metadata->properties[] = $propertyMeta;
            $registered[$name] = true;
        }

        // ----- 3. Virtual properties exposed through public getters ----------------
        foreach (
            $reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC)
            as $method
        ) {
            if (
                $method->isStatic() ||
                $method->isConstructor() ||
                $method->isDestructor()
            ) {
                continue;
            }

            // Skip methods that require arguments
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $propertyName = $this->extractPropertyNameFromGetter(
                $method->getName(),
            );

            if ($propertyName === null || isset($registered[$propertyName])) {
                continue;
            }

            $propertyMeta = $this->buildPropertyMetadataFromGetter(
                $reflectionClass,
                $method,
                $propertyName,
            );

            if ($propertyMeta === null || $propertyMeta->ignored) {
                $registered[$propertyName] = true;
                continue;
            }

            $metadata->properties[] = $propertyMeta;
            $registered[$propertyName] = true;
        }

        return $metadata;
    }

    /**
     * Return all promoted constructor parameters of the given class.
     *
     * @template T of object
     * @param  \ReflectionClass<T> $reflectionClass
     * @return \ReflectionParameter[]
     */
    private function collectPromotedParams(
        \ReflectionClass $reflectionClass,
    ): array {
        $constructor = $reflectionClass->getConstructor();

        if ($constructor === null) {
            return [];
        }

        return array_values(
            array_filter(
                $constructor->getParameters(),
                static fn(\ReflectionParameter $p): bool => $p->isPromoted(),
            ),
        );
    }

    /**
     * Build {@see PropertyMetadata} for a promoted constructor parameter.
     *
     * Attributes are read from both the corresponding ReflectionProperty and
     * the ReflectionParameter, because PHP allows placing the same attribute on
     * either location for a promoted parameter.
     *
     * @template T of object
     * @param  \ReflectionClass<T> $reflectionClass
     */
    private function buildPropertyMetadataFromPromoted(
        \ReflectionClass $reflectionClass,
        \ReflectionParameter $param,
    ): ?PropertyMetadata {
        $name = $param->getName();

        // Promoted params always have a matching ReflectionProperty
        $reflProperty = $reflectionClass->getProperty($name);

        $propertyMeta = new PropertyMetadata();
        $propertyMeta->name = $name;
        $propertyMeta->accessor = $name;
        $propertyMeta->accessorType = AccessorType::PROPERTY;
        $propertyMeta->isReadonly = $reflProperty->isReadOnly();

        // Read attributes from the property declaration (primary location)
        $this->applyAttributesFromProperty($reflProperty, $propertyMeta);

        if ($propertyMeta->ignored) {
            return $propertyMeta;
        }

        // Also check param-level attributes; they may add groups etc.
        $this->applyAttributesFromParameter($param, $propertyMeta);

        $this->resolveType(
            $reflectionClass->getName(),
            $name,
            $param->getType(),
            $propertyMeta,
        );

        return $propertyMeta;
    }

    /**
     * Build {@see PropertyMetadata} for a plain public property.
     *
     * @template T of object
     * @param  \ReflectionClass<T>   $reflectionClass
     */
    private function buildPropertyMetadataFromProperty(
        \ReflectionClass $reflectionClass,
        \ReflectionProperty $reflProperty,
    ): ?PropertyMetadata {
        $name = $reflProperty->getName();

        $propertyMeta = new PropertyMetadata();
        $propertyMeta->name = $name;
        $propertyMeta->accessor = $name;
        $propertyMeta->accessorType = AccessorType::PROPERTY;
        $propertyMeta->isReadonly = $reflProperty->isReadOnly();

        $this->applyAttributesFromProperty($reflProperty, $propertyMeta);

        if ($propertyMeta->ignored) {
            return $propertyMeta;
        }

        $this->resolveType(
            $reflectionClass->getName(),
            $name,
            $reflProperty->getType(),
            $propertyMeta,
        );

        return $propertyMeta;
    }

    /**
     * Build {@see PropertyMetadata} for a virtual property discovered via a getter.
     *
     * @template T of object
     * @param  \ReflectionClass<T>   $reflectionClass
     */
    private function buildPropertyMetadataFromGetter(
        \ReflectionClass $reflectionClass,
        \ReflectionMethod $method,
        string $propertyName,
    ): ?PropertyMetadata {
        $propertyMeta = new PropertyMetadata();
        $propertyMeta->name = $propertyName;
        $propertyMeta->accessor = $method->getName();
        $propertyMeta->accessorType = AccessorType::METHOD;
        $propertyMeta->isReadonly = false;

        // Read attributes from the backing property first (private/protected properties
        // are the canonical location for #[Groups], #[Ignore], #[SerializedName], #[MaxDepth]).
        if ($reflectionClass->hasProperty($propertyName)) {
            $this->applyAttributesFromProperty(
                $reflectionClass->getProperty($propertyName),
                $propertyMeta,
            );
        }

        // Overlay method-level attributes (getter annotations take precedence).
        $this->applyAttributesFromMethod($method, $propertyMeta);

        if ($propertyMeta->ignored) {
            return $propertyMeta;
        }

        $this->resolveType(
            $reflectionClass->getName(),
            $propertyName,
            $method->getReturnType(),
            $propertyMeta,
        );

        return $propertyMeta;
    }

    // -------------------------------------------------------------------------
    // Attribute readers
    // -------------------------------------------------------------------------

    /**
     * Read Symfony Serializer attributes from a ReflectionProperty and apply
     * the results to the given PropertyMetadata.
     */
    private function applyAttributesFromProperty(
        \ReflectionProperty $reflProperty,
        PropertyMetadata $meta,
    ): void {
        foreach ($reflProperty->getAttributes() as $attr) {
            $this->applyAttribute($attr, $meta);
        }
    }

    /**
     * Read Symfony Serializer attributes from a ReflectionParameter (promoted
     * constructor params) and merge them into the given PropertyMetadata.
     */
    private function applyAttributesFromParameter(
        \ReflectionParameter $param,
        PropertyMetadata $meta,
    ): void {
        foreach ($param->getAttributes() as $attr) {
            $this->applyAttribute($attr, $meta);
        }
    }

    /**
     * Read Symfony Serializer attributes from a ReflectionMethod (getter) and
     * apply the results to the given PropertyMetadata.
     */
    private function applyAttributesFromMethod(
        \ReflectionMethod $method,
        PropertyMetadata $meta,
    ): void {
        foreach ($method->getAttributes() as $attr) {
            $this->applyAttribute($attr, $meta);
        }
    }

    /**
     * Dispatch a single reflected attribute to the appropriate handler.
     *
     * Unknown attributes are silently ignored to remain forward-compatible.
     *
     * @param \ReflectionAttribute<object> $attr
     */
    private function applyAttribute(
        \ReflectionAttribute $attr,
        PropertyMetadata $meta,
    ): void {
        switch ($attr->getName()) {
            case Groups::class:
                /** @var Groups $instance */
                $instance = $attr->newInstance();
                // Merge rather than overwrite – promoted params can carry the
                // attribute on both the property and the parameter position.
                $meta->groups = array_values(
                    array_unique([...$meta->groups, ...$instance->getGroups()]),
                );
                break;

            case Ignore::class:
                $meta->ignored = true;
                break;

            case SerializedName::class:
                /** @var SerializedName $instance */
                $instance = $attr->newInstance();
                $meta->serializedName = $instance->getSerializedName();
                break;

            case MaxDepth::class:
                /** @var MaxDepth $instance */
                $instance = $attr->newInstance();
                $meta->maxDepth = $instance->getMaxDepth();
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Type resolution
    // -------------------------------------------------------------------------

    /**
     * Populate type-related fields on a PropertyMetadata, preferring the richer
     * information provided by Symfony PropertyInfo (supports generics/docblocks)
     * and falling back to the raw PHP ReflectionType.
     *
     * @param class-string                                                                          $className    Owning class FQCN
     * @param string                                                                                $propertyName PHP property / virtual-property name
     * @param \ReflectionType|\ReflectionNamedType|\ReflectionUnionType|\ReflectionIntersectionType|null $reflType Raw reflection type for fallback
     */
    private function resolveType(
        string $className,
        string $propertyName,
        ?\ReflectionType $reflType,
        PropertyMetadata $meta,
    ): void {
        // --- Preferred path: Symfony PropertyInfo (richer / docblock-aware) ---
        try {
            $infoTypes = $this->propertyInfoExtractor->getTypes(
                $className,
                $propertyName,
            );
        } catch (\Exception) {
            $infoTypes = null;
        }

        if ($infoTypes !== null && $infoTypes !== []) {
            $this->applyPropertyInfoTypes($infoTypes, $meta);
            return;
        }

        // --- Fallback: raw PHP ReflectionType ---
        if ($reflType !== null) {
            $this->applyReflectionType($reflType, $meta);
        }
    }

    /**
     * Apply type information returned by Symfony PropertyInfo extractors.
     *
     * @param list<Type> $types
     */
    private function applyPropertyInfoTypes(
        array $types,
        PropertyMetadata $meta,
    ): void {
        // Mark as nullable if any type slot is null
        foreach ($types as $type) {
            if (
                $type->isNullable() ||
                $type->getBuiltinType() === Type::BUILTIN_TYPE_NULL
            ) {
                $meta->nullable = true;
            }
        }

        // Collect all non-null type descriptors
        $nonNullTypes = array_values(
            array_filter(
                $types,
                static fn(Type $t): bool => $t->getBuiltinType() !==
                    Type::BUILTIN_TYPE_NULL,
            ),
        );

        if ($nonNullTypes === []) {
            return;
        }

        // Use the first non-null type as the canonical (primary) type
        $primary = $nonNullTypes[0];
        $builtinType = $primary->getBuiltinType();

        // --- Collection detection (array / iterable / generic collections) ---
        if (
            $primary->isCollection() ||
            $builtinType === Type::BUILTIN_TYPE_ARRAY ||
            $builtinType === Type::BUILTIN_TYPE_ITERABLE
        ) {
            $meta->isCollection = true;
            $meta->type = $builtinType;

            $valueTypes = $primary->getCollectionValueTypes();

            if ($valueTypes !== []) {
                $valueType = $valueTypes[0];

                if (
                    $valueType->getBuiltinType() === Type::BUILTIN_TYPE_OBJECT
                ) {
                    $meta->collectionValueType = $valueType->getClassName();
                }
            }

            return;
        }

        // --- Object / nested-class detection ---
        if ($builtinType === Type::BUILTIN_TYPE_OBJECT) {
            $fqcn = $primary->getClassName();

            if ($fqcn !== null) {
                $meta->type = $fqcn;
                $meta->isNested = true;
            } else {
                $meta->type = Type::BUILTIN_TYPE_OBJECT;
            }

            return;
        }

        // --- Scalar / built-in type ---
        $meta->type = $builtinType;
    }

    /**
     * Apply type information obtained directly from PHP Reflection (fallback).
     */
    private function applyReflectionType(
        \ReflectionType $reflType,
        PropertyMetadata $meta,
    ): void {
        if ($reflType instanceof \ReflectionNamedType) {
            $this->applyNamedReflectionType($reflType, $meta);
            return;
        }

        if ($reflType instanceof \ReflectionUnionType) {
            $this->applyUnionReflectionType($reflType, $meta);
            return;
        }

        // ReflectionIntersectionType (PHP 8.1+): all parts are class/interface names
        if ($reflType instanceof \ReflectionIntersectionType) {
            $meta->isNested = true;

            $parts = $reflType->getTypes();

            if ($parts !== []) {
                /** @var \ReflectionNamedType $first */
                $first = $parts[0];
                $meta->type = $first->getName();
            }
        }
    }

    /**
     * Apply a single {@see \ReflectionNamedType} to a PropertyMetadata.
     */
    private function applyNamedReflectionType(
        \ReflectionNamedType $type,
        PropertyMetadata $meta,
    ): void {
        $meta->nullable = $meta->nullable || $type->allowsNull();
        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            if (
                $typeName === Type::BUILTIN_TYPE_ARRAY ||
                $typeName === Type::BUILTIN_TYPE_ITERABLE
            ) {
                $meta->isCollection = true;
            }

            $meta->type = $typeName;

            return;
        }

        // Non-built-in → fully-qualified class / interface / enum name
        $meta->type = $typeName;
        $meta->isNested = !$this->isScalarTypeName($typeName);
    }

    /**
     * Apply a {@see \ReflectionUnionType} (e.g. `string|int|null`) to a
     * PropertyMetadata, using the first non-null type as the representative.
     */
    private function applyUnionReflectionType(
        \ReflectionUnionType $type,
        PropertyMetadata $meta,
    ): void {
        $meta->nullable = $meta->nullable || $type->allowsNull();

        $nonNull = array_values(
            array_filter(
                $type->getTypes(),
                static fn(\ReflectionType $t): bool => $t instanceof
                    \ReflectionNamedType && $t->getName() !== "null",
            ),
        );

        if ($nonNull === []) {
            return;
        }

        /** @var \ReflectionNamedType $first */
        $first = $nonNull[0];

        // Apply but restore the nullable flag afterwards since applyNamedReflectionType
        // may reset it based solely on the individual type's allowsNull() value.
        $savedNullable = $meta->nullable;
        $this->applyNamedReflectionType($first, $meta);
        $meta->nullable = $savedNullable || $type->allowsNull();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract a camelCase property name from a getter method name.
     *
     * Recognises the standard Symfony/PSR getter prefixes: `get`, `is`, `has`.
     * Returns `null` when the method name does not match any recognised pattern.
     *
     * Examples:
     *   - `getName`   → `name`
     *   - `isActive`  → `active`
     *   - `hasParent` → `parent`
     *   - `doSomething` → null
     */
    private function extractPropertyNameFromGetter(string $methodName): ?string
    {
        foreach (["get", "is", "has"] as $prefix) {
            $prefixLength = \strlen($prefix);

            if (
                \strlen($methodName) > $prefixLength &&
                str_starts_with($methodName, $prefix) &&
                ctype_upper($methodName[$prefixLength])
            ) {
                return lcfirst(substr($methodName, $prefixLength));
            }
        }

        return null;
    }

    /**
     * Return true when the given type name is a PHP built-in scalar or
     * pseudo-type that should NOT cause `isNested` to be set to true.
     */
    private function isScalarTypeName(string $typeName): bool
    {
        return \in_array(strtolower($typeName), self::SCALAR_TYPES, true);
    }
}
