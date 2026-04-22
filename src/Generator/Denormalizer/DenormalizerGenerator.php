<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer;

use PhpParser\BuilderFactory;
use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\GeneratedDenormalizerInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorParameterMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactoryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MutatorType;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Trait\GeneratedDenormalizerTrait;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Generates PHP source code for denormalizer classes that convert arrays
 * back into PHP objects.
 *
 * The generated denormalizers implement {@see DenormalizerInterface} and
 * {@see DenormalizerAwareInterface} and use {@see GeneratedDenormalizerTrait}
 * for runtime value extraction/coercion.
 *
 * @see \RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerGenerator
 *      for the sibling normalizer generator that this class mirrors.
 */
final class DenormalizerGenerator implements DenormalizerGeneratorInterface
{
    private BuilderFactory $factory;
    private PrettyPrinter $printer;
    private DefaultValueBuilder $defaultValueBuilder;

    /**
     * @param MetadataFactoryInterface $metadataFactory    Factory used to obtain ClassMetadata.
     * @param string                   $generatedNamespace Root PHP namespace for all generated classes.
     * @param array{
     *     groups: bool,
     *     strict_types: bool,
     * } $features Active code-generation feature flags.
     */
    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory,
        private readonly string $generatedNamespace,
        private readonly array $features,
    ) {
        $this->factory = new BuilderFactory();
        $this->printer = new PrettyPrinter([
            'shortArraySyntax' => true,
            'phpVersion' => PhpVersion::fromComponents(8, 1),
        ]);
        $this->defaultValueBuilder = new DefaultValueBuilder();
    }

    /**
     * Return the metadata factory used by this generator.
     */
    public function getMetadataFactory(): MetadataFactoryInterface
    {
        return $this->metadataFactory;
    }

    /**
     * @inheritdoc
     */
    public function generate(ClassMetadata $metadata): string
    {
        $denormalizerNs = $this->resolveDenormalizerNamespace();
        $denormalizerClass = $this->resolveDenormalizerClassName($metadata);
        $targetFqcn = $metadata->getClassName();

        // Top-level statements
        $stmts = [];

        if ($this->features['strict_types']) {
            $stmts[] = new Declare_([new DeclareItem('strict_types', new Int_(1))]);
        }

        // Collect use statements
        $uses = $this->buildUseStatements($metadata);

        $useStmts = [];
        foreach ($uses as $use) {
            $useStmts[] = new Use_([new UseItem(new Name($use))]);
        }

        // implements list
        $implements = [
            new Name('DenormalizerInterface'),
            new Name('DenormalizerAwareInterface'),
            new Name('GeneratedDenormalizerInterface'),
        ];

        // class body
        $classStmts = [];

        $classStmts[] = new TraitUse([new Name('GeneratedDenormalizerTrait')]);
        $classStmts[] = new TraitUse([new Name('DenormalizerAwareTrait')]);

        $classStmts[] = $this->buildDenormalizeMethod($metadata);

        if ($metadata->hasConstructor() || $metadata->hasConstructorParameters()) {
            $classStmts[] = $this->buildConstructMethod($metadata);
        } else {
            $classStmts[] = $this->buildConstructMethod($metadata);
        }

        $classStmts[] = $this->buildPopulateMethod($metadata);
        $classStmts[] = $this->buildSupportsDenormalizationMethod($targetFqcn);
        $classStmts[] = $this->buildGetSupportedTypesMethod($targetFqcn);

        $classNode = new Class_(
            $denormalizerClass,
            [
                'flags' => Modifiers::FINAL,
                'implements' => $implements,
                'stmts' => $classStmts,
            ],
            [
                'comments' => [
                    new Doc(
                        "/**\n"
                        . " * @generated\n"
                        . " *\n"
                        . " * Denormalizer for \\"
                        . $targetFqcn
                        . ".\n"
                        . " *\n"
                        . " * THIS FILE IS AUTO-GENERATED. DO NOT EDIT MANUALLY.\n"
                        . ' */',
                    ),
                ],
            ],
        );

        $namespaceNode = new Namespace_(new Name($denormalizerNs), array_merge($useStmts, [$classNode]));
        $stmts[] = $namespaceNode;

        $code = $this->printer->prettyPrint($stmts);

        // Normalise PHP-Parser's "declare (strict_types=1)" quirk.
        $code = str_replace('declare (strict_types=1)', 'declare(strict_types=1)', $code);

        return "<?php\n\n" . $code . "\n";
    }

    /**
     * Resolve the namespace for generated denormalizers.
     */
    private function resolveDenormalizerNamespace(): string
    {
        return rtrim($this->generatedNamespace, '\\');
    }

    /**
     * Resolve the short class name for a generated denormalizer.
     *
     * Uses a hash prefix of the source class's namespace to avoid collisions
     * between classes with the same short name in different namespaces.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function resolveDenormalizerClassName(ClassMetadata $metadata): string
    {
        return $this->buildNamespacePrefix($metadata) . $metadata->getShortName() . 'Denormalizer';
    }

    /**
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function buildNamespacePrefix(ClassMetadata $metadata): string
    {
        $classNs = $metadata->getNamespace();

        if ($classNs === '') {
            return '';
        }

        return 'N' . substr(hash('xxh3', $classNs), 0, 8) . '_';
    }

    /**
     * Build the set of fully-qualified class names that must be imported.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     *
     * @return list<string>
     */
    private function buildUseStatements(ClassMetadata $metadata): array
    {
        /** @var array<string, true> $set */
        $set = [];

        $set[$metadata->getClassName()] = true;
        $set[DenormalizerInterface::class] = true;
        $set[DenormalizerAwareInterface::class] = true;
        $set[DenormalizerAwareTrait::class] = true;
        $set[GeneratedDenormalizerInterface::class] = true;
        $set[GeneratedDenormalizerTrait::class] = true;
        $set[AbstractNormalizer::class] = true;

        // Referenced nested classes (for construct/populate type hints in extractObject calls).
        foreach ($metadata->getConstructorReferencedClasses() as $fqcn) {
            $set[$fqcn] = true;
        }

        foreach ($metadata->getProperties() as $property) {
            if ($property->isNested() && $property->getType() !== null) {
                $set[$property->getType()] = true;
            }

            if ($property->isCollection() && $property->getCollectionValueType() !== null) {
                $set[$property->getCollectionValueType()] = true;
            }
        }

        $uses = array_keys($set);
        sort($uses);

        return $uses;
    }

    /**
     * Build the top-level `denormalize()` method that delegates to
     * `construct()` and `populate()`.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function buildDenormalizeMethod(ClassMetadata $metadata): Stmt\ClassMethod
    {
        $targetFqcn = $metadata->getClassName();
        $shortName = $this->shortName($targetFqcn);

        // $object = $context[AbstractNormalizer::OBJECT_TO_POPULATE] ?? $this->construct($data, $format, $context);
        $objectToPopulate = new Coalesce(
            new ArrayDimFetch(
                new Variable('context'),
                new ClassConstFetch(new Name('AbstractNormalizer'), 'OBJECT_TO_POPULATE'),
            ),
            new MethodCall(new Variable('this'), 'construct', [
                new Arg(new Variable('data')),
                new Arg(new Variable('format')),
                new Arg(new Variable('context')),
            ]),
        );

        $stmts = [];
        $stmts[] = new Expression(new Assign(new Variable('object'), $objectToPopulate));
        $stmts[] = new Return_(new MethodCall(new Variable('this'), 'populate', [
            new Arg(new Variable('object')),
            new Arg(new Variable('data')),
            new Arg(new Variable('format')),
            new Arg(new Variable('context')),
        ]));

        $method = $this->factory
            ->method('denormalize')
            ->makePublic()
            ->addParam($this->factory->param('data')->setType('mixed'))
            ->addParam($this->factory->param('type')->setType('string'))
            ->addParam(
                $this->factory
                    ->param('format')
                    ->setType(new NullableType(new Identifier('string')))
                    ->setDefault(null),
            )
            ->addParam($this->factory->param('context')->setType('array')->setDefault([]))
            ->setReturnType(new Name($shortName))
            ->addStmts($stmts)
            ->setDocComment(new Doc(
                "/**\n"
                . " * @param array<string, mixed>|mixed \$data\n"
                . " * @param array<string, mixed>       \$context\n"
                . " *\n"
                . " * @return \\"
                . $targetFqcn
                . "\n"
                . ' */',
            ));

        return $method->getNode();
    }

    /**
     * Build the `construct()` method that instantiates a new object.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function buildConstructMethod(ClassMetadata $metadata): Stmt\ClassMethod
    {
        $targetFqcn = $metadata->getClassName();
        $shortName = $this->shortName($targetFqcn);

        $stmts = [];

        if (!$metadata->hasConstructor() || !$metadata->hasConstructorParameters()) {
            // No constructor or empty constructor: just `new ClassName()`.
            $stmts[] = new Return_(new New_(new Name($shortName)));
        } else {
            // Build one Arg per constructor parameter, using named args so the
            // generated call is stable against parameter-order changes.
            $args = [];

            foreach ($metadata->getConstructorParameters() as $param) {
                if ($param->isVariadic()) {
                    // Variadic parameters cannot be populated from a flat array
                    // in a straightforward way; skip them.
                    continue;
                }

                if ($param->isIgnored()) {
                    // Ignored parameters must never be read from the input
                    // payload. Pass the constructor default directly so the
                    // class retains its intended internal value regardless of
                    // what the caller supplies in $data.
                    $args[] = new Arg(
                        value: $this->buildDefaultValueExpr($param),
                        name: new Identifier($param->getName()),
                    );
                    continue;
                }

                $args[] = new Arg(
                    value: $this->buildExtractCallForConstructorParam($param),
                    name: new Identifier($param->getName()),
                );
            }

            $stmts[] = new Return_(new New_(new Name($shortName), $args));
        }

        $method = $this->factory
            ->method('construct')
            ->makePrivate()
            ->addParam($this->factory->param('data')->setType('array'))
            ->addParam($this->factory->param('format')->setType(new NullableType(new Identifier('string'))))
            ->addParam($this->factory->param('context')->setType('array'))
            ->setReturnType(new Name($shortName))
            ->addStmts($stmts)
            ->setDocComment(
                new Doc(
                    "/**\n"
                    . " * @param array<string, mixed> \$data\n"
                    . " * @param array<string, mixed> \$context\n"
                    . ' */',
                ),
            );

        return $method->getNode();
    }

    /**
     * Build the `populate()` method that writes remaining properties onto the
     * instantiated object.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function buildPopulateMethod(ClassMetadata $metadata): Stmt\ClassMethod
    {
        $targetFqcn = $metadata->getClassName();
        $shortName = $this->shortName($targetFqcn);

        $stmts = [];

        // Build the skip map for properties already populated via the constructor.
        //
        // The skip map avoids writing a value twice when the same key is both a
        // constructor parameter and a populatable property (e.g. a promoted
        // public parameter). We only apply this optimisation when the object
        // was freshly constructed by this denormalizer — when the caller
        // passed OBJECT_TO_POPULATE we must still honour every field present
        // in $data so that partial updates work as users expect.
        $skipKeys = $this->collectSkipKeys($metadata);
        $hasSkipMap = $skipKeys !== [];

        if ($hasSkipMap) {
            $skipItems = [];
            foreach ($skipKeys as $key) {
                $skipItems[] = new ArrayItem(new String_($key));
            }

            // $skip = isset($context[AbstractNormalizer::OBJECT_TO_POPULATE])
            //     ? []
            //     : array_fill_keys([...], true);
            $skipAssign = new Expr\Ternary(
                new Isset_([
                    new ArrayDimFetch(
                        new Variable('context'),
                        new ClassConstFetch(new Name('AbstractNormalizer'), 'OBJECT_TO_POPULATE'),
                    ),
                ]),
                new Array_([], ['kind' => Array_::KIND_SHORT]),
                new FuncCall(new Name('array_fill_keys'), [
                    new Arg(new Array_($skipItems, ['kind' => Array_::KIND_SHORT])),
                    new Arg(new ConstFetch(new Name('true'))),
                ]),
            );

            $stmts[] = new Expression(new Assign(new Variable('skip'), $skipAssign));
        }

        foreach ($metadata->getProperties() as $property) {
            if ($property->isIgnored()) {
                continue;
            }

            if ($property->getMutatorType()->isSkippedDuringPopulation()) {
                continue;
            }

            $stmts = array_merge($stmts, $this->buildPropertyPopulation($property, $hasSkipMap));
        }

        $stmts[] = new Return_(new Variable('object'));

        $method = $this->factory
            ->method('populate')
            ->makePrivate()
            ->addParam($this->factory->param('object')->setType(new Name($shortName)))
            ->addParam($this->factory->param('data')->setType('array'))
            ->addParam($this->factory->param('format')->setType(new NullableType(new Identifier('string'))))
            ->addParam($this->factory->param('context')->setType('array'))
            ->setReturnType(new Name($shortName))
            ->addStmts($stmts)
            ->setDocComment(
                new Doc(
                    "/**\n"
                    . " * @param array<string, mixed> \$data\n"
                    . " * @param array<string, mixed> \$context\n"
                    . ' */',
                ),
            );

        return $method->getNode();
    }

    /**
     * Collect the data-array keys of properties that are populated through
     * the constructor, so the populate() method can skip them.
     *
     * When a constructor parameter carries a `#[SerializedName]` alias that
     * differs from its PHP name, BOTH keys are added to the skip map — the
     * generated code accepts either alias in the input payload (the chained
     * `extract*` fallback, see {@see constructorParamKeyAliases()}), so
     * both aliases must be skipped during the populate phase to avoid
     * double-writing the same field.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     *
     * @return list<string>
     */
    private function collectSkipKeys(ClassMetadata $metadata): array
    {
        $keys = [];

        foreach ($metadata->getConstructorParameters() as $param) {
            if ($param->isVariadic()) {
                continue;
            }

            if ($param->isIgnored()) {
                // Ignored parameters are never extracted from $data, so their
                // key aliases must not be added to the skip-map. Omitting them
                // allows the populate() phase to write to a same-named
                // non-ignored property if one exists.
                continue;
            }

            foreach ($this->constructorParamKeyAliases($param) as $alias) {
                $keys[] = $alias;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Return the list of input-data keys that may carry the value for the
     * given constructor parameter.
     *
     * The first entry is the canonical (serialized) name — the one quoted
     * in exception messages and used as the primary lookup. When the PHP
     * parameter name differs from the serialized alias (i.e. a
     * `#[SerializedName]` attribute is in play), the PHP name is appended
     * as a fallback so payloads can use either form interchangeably.
     *
     * The generator threads the returned list through the chained `extract*`
     * call pattern: the first element drives the outer call, every
     * subsequent element drives a nested inner call that is used as the
     * outer call's `default:` argument.
     *
     * @return list<string>
     */
    private function constructorParamKeyAliases(ConstructorParameterMetadata $param): array
    {
        $serialized = $param->getSerializedName();
        $phpName = $param->getName();

        return $serialized === $phpName ? [$serialized] : [$serialized, $phpName];
    }

    /**
     * Return the list of input-data keys that may carry the value for the
     * given property during the populate() phase.
     *
     * Mirrors {@see constructorParamKeyAliases()} for properties: when the
     * `#[SerializedName]` alias and the PHP property name differ, both are
     * tried in order so callers can supply either form.
     *
     * @return list<string>
     */
    private function propertyKeyAliases(PropertyMetadata $property): array
    {
        $serialized = $property->getSerializedKey();
        $phpName = $property->getName();

        return $serialized === $phpName ? [$serialized] : [$serialized, $phpName];
    }

    /**
     * Build the statements that populate a single property during the
     * populate() phase.
     *
     * @return Stmt[]
     */
    private function buildPropertyPopulation(PropertyMetadata $property, bool $hasSkipMap): array
    {
        $keyAliases = $this->propertyKeyAliases($property);

        // `array_key_exists('primary', $data) [|| array_key_exists('fallback', $data)]`
        // When the property carries a #[SerializedName] alias that differs
        // from its PHP name, the populate() guard must accept either form
        // so the subsequent chained `extract*` call is reached for both.
        $keyCheck = $this->buildAnyKeyExistsExpr($keyAliases);

        $condition = $keyCheck;

        if ($hasSkipMap) {
            // The skip map stores every alias that is already handled by
            // the constructor phase, so we only need to check the primary
            // (first) alias here — collectSkipKeys() guarantees that every
            // alias of a constructor-handled field lands in the map.
            $notSkipped = new BooleanNot(new Isset_([
                new ArrayDimFetch(new Variable('skip'), new String_($keyAliases[0])),
            ]));

            $condition = new BooleanAnd($keyCheck, $notSkipped);
        }

        $extractExpr = $this->buildExtractCallForProperty($property);

        $mutatorType = $property->getMutatorType();
        $mutator = $property->getMutator();

        if ($mutator === null) {
            return [];
        }

        $assignment = match ($mutatorType) {
            MutatorType::PROPERTY => new Expression(
                new Assign(new PropertyFetch(new Variable('object'), $mutator), $extractExpr),
            ),
            MutatorType::SETTER => new Expression(new MethodCall(new Variable('object'), $mutator, [new Arg(
                $extractExpr,
            )])),
            MutatorType::WITHER => new Expression(
                new Assign(new Variable('object'), new MethodCall(new Variable('object'), $mutator, [new Arg(
                    $extractExpr,
                )])),
            ),
            default => null,
        };

        if ($assignment === null) {
            return [];
        }

        return [new If_($condition, ['stmts' => [$assignment]])];
    }

    /**
     * Build the AST expression that extracts a value for a constructor
     * parameter from the input $data.
     */
    private function buildExtractCallForConstructorParam(ConstructorParameterMetadata $param): Expr
    {
        $keyAliases = $this->constructorParamKeyAliases($param);
        $required = $param->isRequired();
        $default = $this->buildDefaultValueExpr($param);

        // Collection of objects (array<T> / T[]).
        if ($param->isCollection() && $param->getCollectionValueType() !== null) {
            return $this->buildExtractArrayOfObjectsCall(
                $keyAliases,
                $param->getCollectionValueType(),
                $required,
                $param->isNullable(),
            );
        }

        // Object / enum / DateTime.
        if ($param->isNested() && $param->getType() !== null) {
            return $this->buildExtractObjectCall($keyAliases, $param->getType(), $required, $default);
        }

        // Scalar / array / mixed.
        return $this->buildExtractScalarCall($keyAliases, $param->getType(), $param->isNullable(), $required, $default);
    }

    /**
     * Build the AST expression that extracts a value for a property during
     * the populate() phase.
     */
    private function buildExtractCallForProperty(PropertyMetadata $property): Expr
    {
        $keyAliases = $this->propertyKeyAliases($property);
        $null = new ConstFetch(new Name('null'));

        // Collection of objects.
        if ($property->isCollection() && $property->getCollectionValueType() !== null) {
            return $this->buildExtractArrayOfObjectsCall(
                $keyAliases,
                $property->getCollectionValueType(),
                required: false,
                nullable: $property->isNullable(),
            );
        }

        // Object / enum / DateTime.
        if ($property->isNested() && $property->getType() !== null) {
            return $this->buildExtractObjectCall($keyAliases, $property->getType(), required: false, default: $null);
        }

        // Scalar.
        return $this->buildExtractScalarCall(
            $keyAliases,
            $property->getType(),
            $property->isNullable(),
            required: false,
            default: $null,
        );
    }

    /**
     * Build a `$this->extract{Scalar}(...)` call, optionally wrapping it in
     * additional outer calls — one per non-primary alias — to implement the
     * chained-call fallback used by `#[SerializedName]`.
     *
     * The innermost call reads the LAST alias with the caller-supplied
     * `$default`; each outer wrapper reads one earlier alias with the
     * inner call's result plugged in as its own `default:` argument.
     *
     * @param list<string> $keyAliases Candidate data-array keys, primary (outer-most) first.
     */
    private function buildExtractScalarCall(
        array $keyAliases,
        ?string $type,
        bool $nullable,
        bool $required,
        Expr $default,
    ): Expr {
        // The outer (canonical) call uses the caller-requested nullability,
        // but every INNER fallback call must be the Nullable variant so that
        // a missing fallback key can propagate as `null` through the
        // chain. The extractors treat a non-null `$default` as an
        // authoritative fallback and skip the `required` check — so when
        // the innermost call has nothing to return (no key present, no
        // user-supplied default), it yields `null`, which bubbles up until
        // the outer canonical call re-evaluates the `required` flag against
        // its own key name.
        $outerMethod = $this->resolveScalarExtractorMethod($type, $nullable);
        $innerMethod = $this->resolveScalarExtractorMethod($type, nullable: true);

        // Walk the aliases from the innermost (last) to the outermost
        // (first), so the `default:` of each layer carries the result of
        // the next-inner lookup.
        $expr = null;
        for ($i = \count($keyAliases) - 1; $i >= 0; $i--) {
            $isOutermost = $i === 0;
            $method = $isOutermost ? $outerMethod : $innerMethod;

            // Only the outermost call propagates the caller's `required`
            // flag. Every inner call uses `required: false` so that a
            // missing fallback key does NOT throw — it returns `null`,
            // which then drives the outer call's own required / default
            // decision.
            $innerRequired = $isOutermost ? $required : false;

            $currentDefault = $expr ?? $default;

            $expr = new MethodCall(new Variable('this'), $method, [
                new Arg(new Variable('data')),
                new Arg(new String_($keyAliases[$i])),
                new Arg(new ConstFetch(new Name($innerRequired ? 'true' : 'false')), name: new Identifier('required')),
                new Arg($currentDefault, name: new Identifier('default')),
                new Arg(new Variable('context'), name: new Identifier('context')),
            ]);
        }

        /** @var Expr $expr */
        return $expr;
    }

    /**
     * Build a `$this->extractObject(...)` call, chained across every alias
     * in `$keyAliases` so that the generated code falls back from the
     * canonical serialized name to the PHP name (and any further fallbacks).
     *
     * @param list<string> $keyAliases Candidate data-array keys, primary (outer-most) first.
     */
    private function buildExtractObjectCall(array $keyAliases, string $className, bool $required, Expr $default): Expr
    {
        $classConst = new ClassConstFetch(new Name($this->shortName($className)), 'class');

        // `extractObject` already returns `?object`, so the same method is
        // safe to use for both the outer and inner layers. Only the outer
        // (canonical) call propagates the caller's `required` flag — every
        // inner fallback uses `required: false` so its "missing-key"
        // outcome is a plain `null` that the outer call can inspect via
        // its own `$default` argument.
        $expr = null;
        for ($i = \count($keyAliases) - 1; $i >= 0; $i--) {
            $isOutermost = $i === 0;
            $innerRequired = $isOutermost ? $required : false;

            $currentDefault = $expr ?? $default;

            $expr = new MethodCall(new Variable('this'), 'extractObject', [
                new Arg(new Variable('data')),
                new Arg(new String_($keyAliases[$i])),
                new Arg($classConst),
                new Arg(new ConstFetch(new Name($innerRequired ? 'true' : 'false')), name: new Identifier('required')),
                new Arg($currentDefault, name: new Identifier('default')),
                new Arg(new Variable('format'), name: new Identifier('format')),
                new Arg(new Variable('context'), name: new Identifier('context')),
            ]);
        }

        /** @var Expr $expr */
        return $expr;
    }

    /**
     * Build a `$this->extractArrayOfObjects(...)` call (or its nullable
     * counterpart), chained across every alias in `$keyAliases`.
     *
     * The innermost call receives `null` as its `default:`, matching how
     * the collection helpers interpret a null default ("no fallback — use
     * the missing-key sentinel `[]` / `null` defined by the helper").
     * Outer wrappers then plug in the inner call's result as their own
     * `default:`, so a missing canonical alias transparently yields the
     * PHP-name fallback's value.
     *
     * @param list<string> $keyAliases Candidate data-array keys, primary (outer-most) first.
     */
    private function buildExtractArrayOfObjectsCall(
        array $keyAliases,
        string $className,
        bool $required,
        bool $nullable,
    ): Expr {
        // The outer call uses the caller-requested nullability; every INNER
        // fallback uses the Nullable variant so a missing fallback key
        // propagates as `null` rather than the non-null `[]` sentinel that
        // `extractArrayOfObjects` would otherwise emit. A non-null `[]`
        // from an inner call would incorrectly short-circuit the outer's
        // required / default logic (since `$default !== null` is treated
        // as an authoritative fallback).
        $outerMethod = $nullable ? 'extractNullableArrayOfObjects' : 'extractArrayOfObjects';
        $innerMethod = 'extractNullableArrayOfObjects';
        $classConst = new ClassConstFetch(new Name($this->shortName($className)), 'class');

        $expr = null;
        for ($i = \count($keyAliases) - 1; $i >= 0; $i--) {
            $isOutermost = $i === 0;
            $method = $isOutermost ? $outerMethod : $innerMethod;
            $innerRequired = $isOutermost ? $required : false;

            $currentDefault = $expr ?? new ConstFetch(new Name('null'));

            $expr = new MethodCall(new Variable('this'), $method, [
                new Arg(new Variable('data')),
                new Arg(new String_($keyAliases[$i])),
                new Arg($classConst),
                new Arg(new ConstFetch(new Name($innerRequired ? 'true' : 'false')), name: new Identifier('required')),
                new Arg($currentDefault, name: new Identifier('default')),
                new Arg(new Variable('format'), name: new Identifier('format')),
                new Arg(new Variable('context'), name: new Identifier('context')),
            ]);
        }

        /** @var Expr $expr */
        return $expr;
    }

    /**
     * Build an OR-chain of `array_key_exists($alias, $data)` checks — one
     * per candidate key — used by populate() to decide whether ANY alias
     * for a property is present in the input payload.
     *
     * @param list<string> $keyAliases Non-empty list of candidate keys.
     */
    private function buildAnyKeyExistsExpr(array $keyAliases): Expr
    {
        $checks = array_map(static fn(string $alias): Expr => new FuncCall(new Name('array_key_exists'), [
            new Arg(new String_($alias)),
            new Arg(new Variable('data')),
        ]), $keyAliases);

        $expr = $checks[0];
        for ($i = 1, $n = \count($checks); $i < $n; $i++) {
            $expr = new Expr\BinaryOp\BooleanOr($expr, $checks[$i]);
        }

        return $expr;
    }

    /**
     * Resolve the scalar extractor method name based on type and nullability.
     */
    private function resolveScalarExtractorMethod(?string $type, bool $nullable): string
    {
        $normalized = match (strtolower((string) $type)) {
            'int', 'integer' => 'Int',
            'float', 'double' => 'Float',
            'string' => 'String',
            'bool', 'boolean' => 'Bool',
            'array', 'iterable' => 'Array',
            default => 'String', // mixed / unknown → treat as string with lenient coercion
        };

        return $nullable ? 'extractNullable' . $normalized : 'extract' . $normalized;
    }

    /**
     * Build the AST expression representing the default value of a constructor
     * parameter. Falls back to `null` when no default is available.
     */
    private function buildDefaultValueExpr(ConstructorParameterMetadata $param): Expr
    {
        if (!$param->hasDefault()) {
            return new ConstFetch(new Name('null'));
        }

        try {
            return $this->defaultValueBuilder->build($param->getDefaultValue());
        } catch (\LogicException $e) {
            throw new \LogicException(
                sprintf(
                    'Cannot generate denormalizer: unsupported default value for parameter "$%s". %s',
                    $param->getName(),
                    $e->getMessage(),
                ),
                0,
                $e,
            );
        }
    }

    /**
     * Build the supportsDenormalization() method AST node.
     */
    private function buildSupportsDenormalizationMethod(string $targetFqcn): Stmt\ClassMethod
    {
        $method = $this->factory
            ->method('supportsDenormalization')
            ->makePublic()
            ->addParam($this->factory->param('data')->setType('mixed'))
            ->addParam($this->factory->param('type')->setType('string'))
            ->addParam(
                $this->factory
                    ->param('format')
                    ->setType(new NullableType(new Identifier('string')))
                    ->setDefault(null),
            )
            ->addParam($this->factory->param('context')->setType('array')->setDefault([]))
            ->setReturnType('bool')
            ->addStmt(new Return_(new Expr\BinaryOp\Identical(new Variable('type'), new String_($targetFqcn))))
            ->setDocComment(new Doc("/**\n * @param array<string, mixed> \$context\n */"));

        return $method->getNode();
    }

    /**
     * Build the getSupportedTypes() method AST node.
     */
    private function buildGetSupportedTypesMethod(string $targetFqcn): Stmt\ClassMethod
    {
        $method = $this->factory
            ->method('getSupportedTypes')
            ->makePublic()
            ->addParam($this->factory->param('format')->setType(new NullableType(new Identifier('string'))))
            ->setReturnType('array')
            ->addStmt(new Return_(
                new Array_([
                    new ArrayItem(new ConstFetch(new Name('true')), new String_($targetFqcn)),
                ], ['kind' => Array_::KIND_SHORT]),
            ))
            ->setDocComment(new Doc("/**\n * @return array<class-string|'*'|'object'|string, bool|null>\n */"));

        return $method->getNode();
    }

    /**
     * Return the short (unqualified) class name from a fully-qualified name.
     */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
