<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Metadata;

use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Extracts {@see ConstructorParameterMetadata} objects for a class by
 * inspecting its constructor via reflection and, when available, enriching
 * the resulting type information with Symfony PropertyInfo.
 *
 * ### Resolution rules
 *
 *   - A parameter is considered "required" when it has neither a default
 *     value nor a nullable type. Nullable parameters without a default are
 *     still considered required in the sense that the key must be present
 *     in the input data, but `null` is an accepted value.
 *   - Default values are extracted via
 *     {@see \ReflectionParameter::getDefaultValue()} and preserved verbatim
 *     so the denormalizer code generator can emit them as AST nodes.
 *   - Type resolution is delegated to Symfony PropertyInfo first (so that
 *     generics/docblocks are honored) and falls back to the parameter's
 *     raw {@see \ReflectionType} when PropertyInfo yields nothing.
 *   - Serializer attributes ({@see SerializedName}, {@see Groups},
 *     {@see Ignore}, {@see MaxDepth}, {@see Context}) are collected from
 *     BOTH the parameter itself and the same-named class property (when
 *     one exists). This links a plain non-promoted constructor parameter
 *     like `__construct(string $postalCode)` to a separately-declared
 *     `#[SerializedName("postal_code")] public string $postalCode` so the
 *     two stay in sync without forcing callers to duplicate the attribute.
 *     Groups and contexts from the two locations are merged; scalar
 *     attributes (serialized name, max depth) prefer the property when both
 *     sides declare them, because the property is the canonical metadata
 *     location for Symfony Serializer.
 *
 * ### Unsupported default value types
 *
 * The extractor itself does not reject unsupported default value types;
 * this validation is performed later by the code generator when it tries
 * to convert the value into an AST node.
 */
final class ConstructorMetadataExtractor
{
    /**
     * PHP built-in scalar / pseudo-type names that are NOT treated as nested
     * classes. Kept in sync with {@see MetadataFactory::SCALAR_TYPES}.
     *
     * @var array<string, true>
     */
    private const SCALAR_TYPES = [
        'int' => true,
        'integer' => true,
        'float' => true,
        'double' => true,
        'string' => true,
        'bool' => true,
        'boolean' => true,
        'null' => true,
        'array' => true,
        'iterable' => true,
        'callable' => true,
        'resource' => true,
        'mixed' => true,
        'void' => true,
        'never' => true,
        'object' => true,
        'self' => true,
        'static' => true,
        'parent' => true,
    ];

    public function __construct(
        private readonly PropertyInfoExtractorInterface $propertyInfoExtractor,
    ) {}

    /**
     * Extract constructor parameter metadata for the given class.
     *
     * Returns an empty array when the class has no constructor at all.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $reflectionClass
     *
     * @return list<ConstructorParameterMetadata>
     */
    public function extract(\ReflectionClass $reflectionClass): array
    {
        $constructor = $reflectionClass->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $result = [];

        foreach ($constructor->getParameters() as $param) {
            $result[] = $this->extractParameter($reflectionClass, $param);
        }

        return $result;
    }

    /**
     * Return true when the class has a constructor.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $reflectionClass
     */
    public function hasConstructor(\ReflectionClass $reflectionClass): bool
    {
        return $reflectionClass->getConstructor() !== null;
    }

    /**
     * Return true when the class has a constructor with at least one parameter.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $reflectionClass
     */
    public function hasConstructorParameters(\ReflectionClass $reflectionClass): bool
    {
        $constructor = $reflectionClass->getConstructor();

        return $constructor !== null && $constructor->getNumberOfParameters() > 0;
    }

    /**
     * Extract metadata for a single constructor parameter.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $reflectionClass
     */
    private function extractParameter(
        \ReflectionClass $reflectionClass,
        \ReflectionParameter $param,
    ): ConstructorParameterMetadata {
        $name = $param->getName();

        // Type information
        $typeData = $this->resolveTypeData($reflectionClass, $param);

        // Default value
        $hasDefault = $param->isDefaultValueAvailable();
        $defaultValue = null;

        if ($hasDefault) {
            $defaultValue = $param->getDefaultValue();
        }

        // Nullable detection: combine reflection and PropertyInfo hints
        $isNullable = $typeData['nullable'] || $param->allowsNull();

        // Required when no default AND not nullable.
        // A nullable parameter without a default is still "required" in that
        // the field must be present in the input data; however, we mark it as
        // required = false here because Symfony's behavior is to accept null
        // for nullable params even when absent, matching Symfony's ObjectNormalizer.
        $isRequired = !$hasDefault && !$isNullable;

        // Collect serializer attributes from both the parameter and the
        // same-named class property (when one exists). This is what links
        // a plain non-promoted constructor parameter to a separately
        // declared class property carrying the serializer attributes.
        $attrData = $this->collectAttributeData($reflectionClass, $param);

        return new ConstructorParameterMetadata(
            name: $name,
            serializedName: $attrData['serializedName'] ?? $name,
            type: $typeData['type'],
            isNested: $typeData['isNested'],
            isCollection: $typeData['isCollection'],
            collectionValueType: $typeData['collectionValueType'],
            isRequired: $isRequired,
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            isNullable: $isNullable,
            isPromoted: $param->isPromoted(),
            isVariadic: $param->isVariadic(),
            groups: $attrData['groups'],
            ignored: $attrData['ignored'],
            maxDepth: $attrData['maxDepth'],
            contexts: $attrData['contexts'],
        );
    }

    /**
     * Collect all serializer attributes that apply to the given constructor
     * parameter, merging data from the parameter itself with data from the
     * same-named class property (when one exists).
     *
     * ### Merge semantics
     *
     *   - `serializedName` / `maxDepth`: the property-level value wins when
     *     both sides declare one, because the property is the canonical
     *     metadata location in Symfony Serializer. When only one side
     *     declares a value, that value is used.
     *   - `groups`: union of both sides (deduplicated, in declaration order —
     *     property first, then parameter).
     *   - `contexts`: concatenated from both sides (property first, then
     *     parameter), preserving every `#[Context]` instance.
     *   - `ignored`: true when `#[Ignore]` appears on EITHER side.
     *
     * Attribute-target errors (e.g. a `#[Groups]` placed on a non-promoted
     * parameter where the attribute is not valid) are silently ignored so
     * the whole extraction does not abort on a misplaced attribute.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $reflectionClass
     *
     * @return array{
     *     serializedName: ?string,
     *     groups: string[],
     *     ignored: bool,
     *     maxDepth: ?int,
     *     contexts: PropertyContext[],
     * }
     */
    private function collectAttributeData(\ReflectionClass $reflectionClass, \ReflectionParameter $param): array
    {
        $data = [
            'serializedName' => null,
            'groups' => [],
            'ignored' => false,
            'maxDepth' => null,
            'contexts' => [],
        ];

        // 1. Read from the linked class property FIRST, so property-level
        //    scalar attributes (serializedName, maxDepth) take precedence
        //    over anything else.
        if ($reflectionClass->hasProperty($param->getName())) {
            $property = $reflectionClass->getProperty($param->getName());
            $this->collectFromAttributes($property->getAttributes(), $data);
        }

        // 2. Overlay parameter-level attributes. For promoted parameters
        //    this is redundant with the property read above (same syntax
        //    position); for non-promoted parameters it gives callers the
        //    option to attach attributes directly to the parameter even
        //    when no matching property exists.
        $this->collectFromAttributes($param->getAttributes(), $data);

        return $data;
    }

    /**
     * Apply a list of {@see \ReflectionAttribute} entries to the running
     * attribute-data array, dispatching each attribute to its handler and
     * silently skipping target-mismatch errors.
     *
     * @param \ReflectionAttribute<object>[] $attributes
     * @param array{
     *     serializedName: ?string,
     *     groups: string[],
     *     ignored: bool,
     *     maxDepth: ?int,
     *     contexts: PropertyContext[],
     * } $data
     */
    private function collectFromAttributes(array $attributes, array &$data): void
    {
        foreach ($attributes as $attr) {
            try {
                $this->collectSingleAttribute($attr, $data);
            } catch (\Error) {
                // PHP raises an Error when an attribute is instantiated for
                // a target it does not allow (e.g. `#[SerializedName]` on a
                // parameter position). Skip silently so the rest of the
                // attribute set can still be collected.
                continue;
            }
        }
    }

    /**
     * Dispatch a single reflected attribute to the appropriate field in
     * `$data`. Unknown attributes are ignored to stay forward-compatible.
     *
     * @param \ReflectionAttribute<object> $attr
     * @param array{
     *     serializedName: ?string,
     *     groups: string[],
     *     ignored: bool,
     *     maxDepth: ?int,
     *     contexts: PropertyContext[],
     * } $data
     */
    private function collectSingleAttribute(\ReflectionAttribute $attr, array &$data): void
    {
        switch ($attr->getName()) {
            case Groups::class:
                /** @var Groups $instance */
                $instance = $attr->newInstance();
                // Union with existing groups, preserving first-seen order.
                $data['groups'] = array_values(array_unique([
                    ...$data['groups'],
                    ...$instance->getGroups(),
                ]));
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

            case Context::class:
                /** @var Context $instance */
                $instance = $attr->newInstance();
                $data['contexts'][] = new PropertyContext(
                    context: $instance->getContext(),
                    normalizationContext: $instance->getNormalizationContext(),
                    denormalizationContext: $instance->getDenormalizationContext(),
                    groups: $instance->getGroups(),
                );
                break;
        }
    }

    /**
     * Resolve the type information for a constructor parameter.
     *
     * Uses Symfony PropertyInfo for promoted parameters (so that docblock
     * types on the backing property are honored) and falls back to raw
     * reflection otherwise.
     *
     * @template T of object
     *
     * @param \ReflectionClass<T> $reflectionClass
     *
     * @return array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool}
     */
    private function resolveTypeData(\ReflectionClass $reflectionClass, \ReflectionParameter $param): array
    {
        $data = [
            'type' => null,
            'isNested' => false,
            'isCollection' => false,
            'collectionValueType' => null,
            'nullable' => false,
        ];

        // Prefer PropertyInfo for promoted params (docblock-aware).
        if ($param->isPromoted() && $reflectionClass->hasProperty($param->getName())) {
            try {
                $infoTypes = $this->propertyInfoExtractor->getTypes($reflectionClass->getName(), $param->getName());
            } catch (\Exception) {
                $infoTypes = null;
            }

            if ($infoTypes !== null && $infoTypes !== []) {
                $this->collectPropertyInfoTypes($infoTypes, $data);

                return $data;
            }
        }

        // Fallback: use the raw reflection type.
        $reflType = $param->getType();

        if ($reflType !== null) {
            $this->collectReflectionType($reflType, $data);
        }

        return $data;
    }

    /**
     * Populate $data from Symfony PropertyInfo types.
     *
     * @param list<Type> $types
     * @param array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool} $data
     */
    private function collectPropertyInfoTypes(array $types, array &$data): void
    {
        foreach ($types as $type) {
            if ($type->isNullable() || $type->getBuiltinType() === Type::BUILTIN_TYPE_NULL) {
                $data['nullable'] = true;
            }
        }

        $nonNullTypes = array_values(array_filter(
            $types,
            static fn(Type $t): bool => $t->getBuiltinType() !== Type::BUILTIN_TYPE_NULL,
        ));

        if ($nonNullTypes === []) {
            return;
        }

        $primary = $nonNullTypes[0];
        $builtinType = $primary->getBuiltinType();

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

        $data['type'] = $builtinType;
    }

    /**
     * Populate $data from a raw \ReflectionType.
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

        if ($reflType instanceof \ReflectionIntersectionType) {
            $data['isNested'] = true;

            $parts = $reflType->getTypes();

            if ($parts !== []) {
                $first = $parts[0];

                if ($first instanceof \ReflectionNamedType) {
                    $data['type'] = $first->getName();
                }
            }
        }
    }

    /**
     * @param array{type: ?string, isNested: bool, isCollection: bool, collectionValueType: ?string, nullable: bool} $data
     */
    private function collectNamedReflectionType(\ReflectionNamedType $type, array &$data): void
    {
        $data['nullable'] = $data['nullable'] || $type->allowsNull();
        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            if ($typeName === 'array' || $typeName === 'iterable') {
                $data['isCollection'] = true;
            }

            $data['type'] = $typeName;

            return;
        }

        $data['type'] = $typeName;
        $data['isNested'] = !$this->isScalarTypeName($typeName);
    }

    /**
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

        $savedNullable = $data['nullable'];
        $this->collectNamedReflectionType($first, $data);
        $data['nullable'] = $savedNullable || $type->allowsNull();
    }

    /**
     * Return true when the given type name is a PHP built-in scalar/pseudo-type.
     */
    private function isScalarTypeName(string $typeName): bool
    {
        return isset(self::SCALAR_TYPES[strtolower($typeName)]);
    }
}
