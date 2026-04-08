<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Metadata;

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
        'int',
        'integer',
        'float',
        'double',
        'string',
        'bool',
        'boolean',
        'null',
        'array',
        'iterable',
        'callable',
        'resource',
        'mixed',
        'void',
        'never',
        'object',
        'self',
        'static',
        'parent',
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

    /**
     * Build and return fully-populated metadata for the given class name.
     *
     * Results are cached in-memory so repeated calls are cheap.
     *
     * @template TValue of object
     *
     * @param class-string<TValue> $className
     *
     * @return ClassMetadata<TValue>
     *
     * @throws \InvalidArgumentException When the class does not exist.
     */
    public function getMetadataFor(string $className): ClassMetadata
    {
        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        if (!class_exists($className)) {
            throw new \InvalidArgumentException(sprintf(
                'Class "%s" does not exist or cannot be autoloaded.',
                $className,
            ));
        }

        /** @var \ReflectionClass<TValue> $reflectionClass */
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

    /**
     * Build a fully-populated ClassMetadata for the given ReflectionClass.
     *
     * @template T of object
     * @param  \ReflectionClass<T> $reflectionClass
     * @return ClassMetadata<T>
     */
    private function buildClassMetadata(\ReflectionClass $reflectionClass): ClassMetadata
    {
        /** @var ClassMetadata<T> $metadata */
        $metadata = new ClassMetadata(reflectionClass: $reflectionClass, className: $reflectionClass->getName());

        /** @var array<string, true> $registered Tracks which property names have already been added */
        $registered = [];

        // ----- 1. Promoted constructor parameters (preserve declaration order) ------
        foreach ($this->collectPromotedParams($reflectionClass) as $param) {
            // Only process promoted parameters that are publicly accessible as properties.
            // Private/protected promoted params cannot be read directly from outside the class;
            // they are discovered via their public getter methods in step 3 below.
            $promotedProperty = $reflectionClass->getProperty($param->getName());
            if (!$promotedProperty->isPublic()) {
                continue;
            }

            $propertyMeta = $this->buildPropertyMetadataFromPromoted($reflectionClass, $param);

            if ($propertyMeta === null || $propertyMeta->isIgnored()) {
                $registered[$param->getName()] = true;
                continue;
            }

            $metadata->addProperty($propertyMeta);
            $registered[$param->getName()] = true;
        }

        // ----- 2. Regular public properties (non-promoted) -------------------------
        foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $reflProperty) {
            $name = $reflProperty->getName();

            // Skip statics and already-registered promoted params
            if ($reflProperty->isStatic() || isset($registered[$name])) {
                continue;
            }

            $propertyMeta = $this->buildPropertyMetadataFromProperty($reflectionClass, $reflProperty);

            if ($propertyMeta === null || $propertyMeta->isIgnored()) {
                $registered[$name] = true;
                continue;
            }

            $metadata->addProperty($propertyMeta);
            $registered[$name] = true;
        }

        // ----- 3. Virtual properties exposed through public getters ----------------
        foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            // Skip methods that require arguments
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $propertyName = $this->extractPropertyNameFromGetter($method->getName());

            if ($propertyName === null || isset($registered[$propertyName])) {
                continue;
            }

            $propertyMeta = $this->buildPropertyMetadataFromGetter($reflectionClass, $method, $propertyName);

            if ($propertyMeta === null || $propertyMeta->isIgnored()) {
                $registered[$propertyName] = true;
                continue;
            }

            $metadata->addProperty($propertyMeta);
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
    private function collectPromotedParams(\ReflectionClass $reflectionClass): array
    {
        $constructor = $reflectionClass->getConstructor();

        if ($constructor === null) {
            return [];
        }

        return array_values(array_filter($constructor->getParameters(), static fn(\ReflectionParameter $p): bool => $p->isPromoted()));
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

        // Collect attribute data
        $attrData = $this->createEmptyAttributeData();
        $this->collectAttributesFromProperty($reflProperty, $attrData);

        // Early return for ignored properties
        if ($attrData['ignored']) {
            return new PropertyMetadata(
                name: $name,
                accessor: $name,
                accessorType: AccessorType::PROPERTY,
                isReadonly: $reflProperty->isReadOnly(),
                ignored: true,
                groups: $attrData['groups'],
                serializedName: $attrData['serializedName'],
                maxDepth: $attrData['maxDepth'],
            );
        }

        // Also check param-level attributes; they may add groups etc.
        $this->collectAttributesFromParameter($param, $attrData);

        // Resolve type information
        $typeData = $this->resolveTypeData($reflectionClass->getName(), $name, $param->getType());

        return new PropertyMetadata(
            name: $name,
            serializedName: $attrData['serializedName'],
            groups: $attrData['groups'],
            ignored: false,
            type: $typeData['type'],
            isNested: $typeData['isNested'],
            isCollection: $typeData['isCollection'],
            collectionValueType: $typeData['collectionValueType'],
            accessor: $name,
            accessorType: AccessorType::PROPERTY,
            maxDepth: $attrData['maxDepth'],
            nullable: $typeData['nullable'],
            isReadonly: $reflProperty->isReadOnly(),
        );
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

        // Collect attribute data
        $attrData = $this->createEmptyAttributeData();
        $this->collectAttributesFromProperty($reflProperty, $attrData);

        // Early return for ignored properties
        if ($attrData['ignored']) {
            return new PropertyMetadata(
                name: $name,
                accessor: $name,
                accessorType: AccessorType::PROPERTY,
                isReadonly: $reflProperty->isReadOnly(),
                ignored: true,
                groups: $attrData['groups'],
                serializedName: $attrData['serializedName'],
                maxDepth: $attrData['maxDepth'],
            );
        }

        // Resolve type information
        $typeData = $this->resolveTypeData($reflectionClass->getName(), $name, $reflProperty->getType());

        return new PropertyMetadata(
            name: $name,
            serializedName: $attrData['serializedName'],
            groups: $attrData['groups'],
            ignored: false,
            type: $typeData['type'],
            isNested: $typeData['isNested'],
            isCollection: $typeData['isCollection'],
            collectionValueType: $typeData['collectionValueType'],
            accessor: $name,
            accessorType: AccessorType::PROPERTY,
            maxDepth: $attrData['maxDepth'],
            nullable: $typeData['nullable'],
            isReadonly: $reflProperty->isReadOnly(),
        );
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
        // Collect attribute data
        $attrData = $this->createEmptyAttributeData();

        // Read attributes from the backing property first (private/protected properties
        // are the canonical location for #[Groups], #[Ignore], #[SerializedName], #[MaxDepth]).
        if ($reflectionClass->hasProperty($propertyName)) {
            $this->collectAttributesFromProperty($reflectionClass->getProperty($propertyName), $attrData);
        }

        // Overlay method-level attributes (getter annotations take precedence).
        $this->collectAttributesFromMethod($method, $attrData);

        // Early return for ignored properties
        if ($attrData['ignored']) {
            return new PropertyMetadata(
                name: $propertyName,
                accessor: $method->getName(),
                accessorType: AccessorType::METHOD,
                isReadonly: false,
                ignored: true,
                groups: $attrData['groups'],
                serializedName: $attrData['serializedName'],
                maxDepth: $attrData['maxDepth'],
            );
        }

        // Resolve type information
        $typeData = $this->resolveTypeData($reflectionClass->getName(), $propertyName, $method->getReturnType());

        return new PropertyMetadata(
            name: $propertyName,
            serializedName: $attrData['serializedName'],
            groups: $attrData['groups'],
            ignored: false,
            type: $typeData['type'],
            isNested: $typeData['isNested'],
            isCollection: $typeData['isCollection'],
            collectionValueType: $typeData['collectionValueType'],
            accessor: $method->getName(),
            accessorType: AccessorType::METHOD,
            maxDepth: $attrData['maxDepth'],
            nullable: $typeData['nullable'],
            isReadonly: false,
        );
    }

    /**
     * Create an empty attribute data array with default values.
     *
     * @return array{groups: string[], ignored: bool, serializedName: ?string, maxDepth: ?int}
     */
    private function createEmptyAttributeData(): array
    {
        return [
            'groups' => [],
            'ignored' => false,
            'serializedName' => null,
            'maxDepth' => null,
        ];
    }

    /**
     * Read Symfony Serializer attributes from a ReflectionProperty and collect
     * the results into the given data array.
     *
     * @param array{groups: string[], ignored: bool, serializedName: ?string, maxDepth: ?int} $data
     */
    private function collectAttributesFromProperty(\ReflectionProperty $reflProperty, array &$data): void
    {
        foreach ($reflProperty->getAttributes() as $attr) {
            $this->collectAttribute($attr, $data);
        }
    }

    /**
     * Read Symfony Serializer attributes from a ReflectionParameter (promoted
     * constructor params) and merge them into the given data array.
     *
     * @param array{groups: string[], ignored: bool, serializedName: ?string, maxDepth: ?int} $data
     */
    private function collectAttributesFromParameter(\ReflectionParameter $param, array &$data): void
    {
        foreach ($param->getAttributes() as $attr) {
            $this->collectAttribute($attr, $data);
        }
    }

    /**
     * Read Symfony Serializer attributes from a ReflectionMethod (getter) and
     * collect the results into the given data array.
     *
     * @param array{groups: string[], ignored: bool, serializedName: ?string, maxDepth: ?int} $data
     */
    private function collectAttributesFromMethod(\ReflectionMethod $method, array &$data): void
    {
        foreach ($method->getAttributes() as $attr) {
            $this->collectAttribute($attr, $data);
        }
    }

    /**
     * Dispatch a single reflected attribute to the appropriate handler.
     *
     * Unknown attributes are silently ignored to remain forward-compatible.
     *
     * @param \ReflectionAttribute<object> $attr
     * @param array{groups: string[], ignored: bool, serializedName: ?string, maxDepth: ?int} $data
     */
    private function collectAttribute(\ReflectionAttribute $attr, array &$data): void
    {
        switch ($attr->getName()) {
            case Groups::class:
                /** @var Groups $instance */
                $instance = $attr->newInstance();
                // Merge rather than overwrite – promoted params can carry the
                // attribute on both the property and the parameter position.
                $data['groups'] = array_values(array_unique([...$data['groups'], ...$instance->getGroups()]));
                break;

            case Ignore::class:
                $data['ignored'] = true;
                break;

            case SerializedName::class:
                /** @var SerializedName $instance */
                $instance = $attr->newInstance();
                $data['serializedName'] = $instance->getSerializedName();
                break;

            case MaxDepth::class:
                /** @var MaxDepth $instance */
                $instance = $attr->newInstance();
                $data['maxDepth'] = $instance->getMaxDepth();
                break;
        }
    }

    /**
     * Populate type-related fields on a PropertyMetadata, preferring the richer
     * information provided by Symfony PropertyInfo (supports generics/docblocks)
     * and falling back to the raw PHP ReflectionType.
     *
     * @param class-string                                                                          $className    Owning class FQCN
     * @param string                                                                                $propertyName PHP property / virtual-property name
     * @param \ReflectionType|\ReflectionNamedType|\ReflectionUnionType|\ReflectionIntersectionType|null $reflType Raw reflection type for fallback
     */
    /**
     * Create an empty type data array with default values.
     *
     * @return array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool}
     */
    private function createEmptyTypeData(): array
    {
        return [
            'type' => null,
            'isNested' => false,
            'isCollection' => false,
            'collectionValueType' => null,
            'nullable' => false,
        ];
    }

    /**
     * Resolve type information for a property.
     *
     * @return array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool}
     */
    private function resolveTypeData(string $className, string $propertyName, ?\ReflectionType $reflType): array
    {
        $data = $this->createEmptyTypeData();

        // --- Preferred path: Symfony PropertyInfo (richer / docblock-aware) ---
        try {
            $infoTypes = $this->propertyInfoExtractor->getTypes($className, $propertyName);
        } catch (\Exception) {
            $infoTypes = null;
        }

        if ($infoTypes !== null && $infoTypes !== []) {
            $this->collectPropertyInfoTypes($infoTypes, $data);
            return $data;
        }

        // --- Fallback: raw PHP ReflectionType ---
        if ($reflType !== null) {
            $this->collectReflectionType($reflType, $data);
        }

        return $data;
    }

    /**
     * Collect type information returned by Symfony PropertyInfo extractors.
     *
     * @param list<Type> $types
     * @param array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool} $data
     */
    private function collectPropertyInfoTypes(array $types, array &$data): void
    {
        // Mark as nullable if any type slot is null
        foreach ($types as $type) {
            if ($type->isNullable() || $type->getBuiltinType() === Type::BUILTIN_TYPE_NULL) {
                $data['nullable'] = true;
            }
        }

        // Collect all non-null type descriptors
        $nonNullTypes = array_values(array_filter(
            $types,
            static fn(Type $t): bool => $t->getBuiltinType() !== Type::BUILTIN_TYPE_NULL,
        ));

        if ($nonNullTypes === []) {
            return;
        }

        // Use the first non-null type as the canonical (primary) type
        $primary = $nonNullTypes[0];
        $builtinType = $primary->getBuiltinType();

        // --- Collection detection (array / iterable / generic collections) ---
        if (
            $primary->isCollection()
            || $builtinType === Type::BUILTIN_TYPE_ARRAY
            || $builtinType === Type::BUILTIN_TYPE_ITERABLE
        ) {
            $data['isCollection'] = true;
            $data['type'] = $builtinType;

            $valueTypes = $primary->getCollectionValueTypes();

            if ($valueTypes !== []) {
                $valueType = $valueTypes[0];

                if ($valueType->getBuiltinType() === Type::BUILTIN_TYPE_OBJECT) {
                    $data['collectionValueType'] = $valueType->getClassName();
                }
            }

            return;
        }

        // --- Object / nested-class detection ---
        if ($builtinType === Type::BUILTIN_TYPE_OBJECT) {
            $fqcn = $primary->getClassName();

            if ($fqcn !== null) {
                $data['type'] = $fqcn;
                $data['isNested'] = true;
            } else {
                $data['type'] = Type::BUILTIN_TYPE_OBJECT;
            }

            return;
        }

        // --- Scalar / built-in type ---
        $data['type'] = $builtinType;
    }

    /**
     * Apply type information obtained directly from PHP Reflection (fallback).
     */
    /**
     * Collect type information from a ReflectionType.
     *
     * @param array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool} $data
     */
    private function collectReflectionType(\ReflectionType $reflType, array &$data): void
    {
        if ($reflType instanceof \ReflectionNamedType) {
            $this->collectNamedReflectionType($reflType, $data);
            return;
        }

        if ($reflType instanceof \ReflectionUnionType) {
            $this->collectUnionReflectionType($reflType, $data);
            return;
        }

        // ReflectionIntersectionType (PHP 8.1+): all parts are class/interface names
        if ($reflType instanceof \ReflectionIntersectionType) {
            $data['isNested'] = true;

            $parts = $reflType->getTypes();

            if ($parts !== []) {
                /** @var \ReflectionNamedType $first */
                $first = $parts[0];
                $data['type'] = $first->getName();
            }
        }
    }

    /**
     * Collect type information from a single {@see \ReflectionNamedType}.
     *
     * @param array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool} $data
     */
    private function collectNamedReflectionType(\ReflectionNamedType $type, array &$data): void
    {
        $data['nullable'] = $data['nullable'] || $type->allowsNull();
        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            if ($typeName === Type::BUILTIN_TYPE_ARRAY || $typeName === Type::BUILTIN_TYPE_ITERABLE) {
                $data['isCollection'] = true;
            }

            $data['type'] = $typeName;

            return;
        }

        // Non-built-in → fully-qualified class / interface / enum name
        $data['type'] = $typeName;
        $data['isNested'] = !$this->isScalarTypeName($typeName);
    }

    /**
     * Collect type information from a {@see \ReflectionUnionType} (e.g. `string|int|null`),
     * using the first non-null type as the representative.
     *
     * @param array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool} $data
     */
    private function collectUnionReflectionType(\ReflectionUnionType $type, array &$data): void
    {
        $data['nullable'] = $data['nullable'] || $type->allowsNull();

        $nonNull = array_values(array_filter(
            $type->getTypes(),
            static fn(\ReflectionType $t): bool => $t instanceof \ReflectionNamedType && $t->getName() !== 'null',
        ));

        if ($nonNull === []) {
            return;
        }

        /** @var \ReflectionNamedType $first */
        $first = $nonNull[0];

        // Collect but restore the nullable flag afterwards since collectNamedReflectionType
        // may reset it based solely on the individual type's allowsNull() value.
        $savedNullable = $data['nullable'];
        $this->collectNamedReflectionType($first, $data);
        $data['nullable'] = $savedNullable || $type->allowsNull();
    }

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
        foreach (['get', 'is', 'has'] as $prefix) {
            $prefixLength = \strlen($prefix);

            if (
                \strlen($methodName) > $prefixLength
                && str_starts_with($methodName, $prefix)
                && ctype_upper($methodName[$prefixLength])
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
