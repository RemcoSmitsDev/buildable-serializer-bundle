<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator;

use PhpParser\BuilderFactory;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\DeclareItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast\Array_ as CastArray;
use PhpParser\Node\Expr\Cast\Bool_ as CastBool;
use PhpParser\Node\Expr\Cast\Int_ as CastInt;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\Node\UseItem;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\AccessorType;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactoryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\PropertyMetadata;
use RemcoSmitsDev\BuildableSerializerBundle\Normalizer\GeneratedNormalizerInterface;
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use UnexpectedValueException;

final class NormalizerGenerator implements NormalizerGeneratorInterface
{
    private BuilderFactory $factory;
    private PrettyPrinter $printer;

    /**
     * @param MetadataFactoryInterface $metadataFactory Factory used to obtain ClassMetadata.
     * @param string                   $generatedNamespace Root PHP namespace for all generated classes.
     * @param array{
     *     groups: bool,
     *     max_depth: bool,
     *     circular_reference: bool,
     *     skip_null_values: bool,
     *     context: bool,
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
    }

    /**
     * Return the metadata factory used by this generator.
     *
     * Exposed so that consumers (e.g. the console command) can retrieve
     * {@see \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata} for a class
     * without having to inject the factory separately.
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
        $normalizerNs = $this->resolveNormalizerNamespace($metadata);
        $normalizerClass = $this->resolveNormalizerClassName($metadata);
        $targetFqcn = $metadata->getClassName();

        $needsAware = $this->needsNormalizerAware($metadata);
        $needsAbstractNorm = $this->needsAbstractNormalizerConstants($metadata);
        $needsAbstractObj = $this->needsAbstractObjectNormalizerConstants($metadata);
        $needsCircularRef = $this->features['circular_reference'] && $metadata->hasNestedObjects();

        // Top-level statements
        $stmts = [];

        // declare(strict_types=1)
        if ($this->features['strict_types']) {
            $stmts[] = new Declare_([new DeclareItem('strict_types', new Int_(1))]);
        }

        // use statements
        $uses = $this->buildUseStatements(
            $targetFqcn,
            $needsAware,
            $needsAbstractNorm,
            $needsAbstractObj,
            $needsCircularRef,
        );

        $useStmts = [];
        foreach ($uses as $use) {
            $useStmts[] = new Use_([new UseItem(new Name($use))]);
        }

        // implements list
        $implements = [
            new Name('NormalizerInterface'),
            new Name('GeneratedNormalizerInterface'),
        ];

        if ($needsAware) {
            $implements[] = new Name('NormalizerAwareInterface');
        }

        // class body
        $classStmts = [];

        if ($needsAware) {
            $classStmts[] = new TraitUse([new Name('NormalizerAwareTrait')]);
        }

        $classStmts[] = $this->buildNormalizeMethod($metadata, $needsCircularRef);
        $classStmts[] = $this->buildSupportsNormalizationMethod($targetFqcn);
        $classStmts[] = $this->buildGetSupportedTypesMethod($targetFqcn);

        $classNode = new Class_(
            $normalizerClass,
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
                        . " * Normalizer for \\"
                        . $targetFqcn
                        . ".\n"
                        . " *\n"
                        . " * THIS FILE IS AUTO-GENERATED. DO NOT EDIT MANUALLY.\n"
                        . ' */',
                    ),
                ],
            ],
        );

        $namespaceNode = new Namespace_(new Name($normalizerNs), array_merge($useStmts, [$classNode]));
        $stmts[] = $namespaceNode;

        $code = $this->printer->prettyPrint($stmts);

        // PHP-Parser's standard printer emits `declare (strict_types=1)` with a space;
        // normalise it to the more conventional `declare(strict_types=1)`.
        $code = str_replace('declare (strict_types=1)', 'declare(strict_types=1)', $code);

        return "<?php\n\n" . $code . "\n";
    }

    /**
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function resolveNormalizerNamespace(ClassMetadata $metadata): string
    {
        return rtrim($this->generatedNamespace, "\\");
    }

    /**
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function resolveNormalizerClassName(ClassMetadata $metadata): string
    {
        return $this->buildNamespacePrefix($metadata) . $metadata->getShortName() . 'Normalizer';
    }

    /**
     * Build a short hash prefix from the class namespace to avoid collisions
     * when classes with the same short name exist in different namespaces.
     *
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

        // Prefix with 'N' to ensure valid PHP class name (cannot start with a number)
        return 'N' . substr(hash('xxh3', $classNs), 0, 8) . '_';
    }

    /**
     * @return list<string>
     */
    private function buildUseStatements(
        string $targetFqcn,
        bool $needsAware,
        bool $needsAbstractNorm,
        bool $needsAbstractObj,
        bool $needsCircularRef,
    ): array {
        /** @var array<string, true> $set */
        $set = [];

        $set[$targetFqcn] = true;
        $set[GeneratedNormalizerInterface::class] = true;
        $set[NormalizerInterface::class] = true;

        if ($needsAware) {
            $set[NormalizerAwareInterface::class] = true;
            $set[NormalizerAwareTrait::class] = true;
        }

        if ($needsAbstractNorm) {
            $set[AbstractNormalizer::class] = true;
        }

        if ($needsAbstractObj) {
            $set[AbstractObjectNormalizer::class] = true;
        }

        if ($needsCircularRef) {
            $set[CircularReferenceException::class] = true;
        }

        $uses = array_keys($set);
        sort($uses);

        return $uses;
    }

    /**
     * Whether the generated class needs the NormalizerAware interface/trait for
     * recursive delegation (nested objects or typed collections).
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function needsNormalizerAware(ClassMetadata $metadata): bool
    {
        return $metadata->hasNestedObjects() || $metadata->hasCollections();
    }

    /**
     * Whether any AbstractNormalizer constant references are needed in the
     * generated normalize() method body.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function needsAbstractNormalizerConstants(ClassMetadata $metadata): bool
    {
        return (
            $this->features['groups'] && $metadata->hasGroupConstraints()
            || $this->features['circular_reference'] && $metadata->hasNestedObjects()
        );
    }

    /**
     * Whether any AbstractObjectNormalizer constant references are needed.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function needsAbstractObjectNormalizerConstants(ClassMetadata $metadata): bool
    {
        return (
            $this->features['max_depth'] && $metadata->hasMaxDepthConstraints()
            || $this->features['skip_null_values']
        );
    }

    /**
     * Build the normalize() method AST node.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function buildNormalizeMethod(ClassMetadata $metadata, bool $needsCircularRef): Stmt\ClassMethod
    {
        $activeFeatures = $this->resolveActiveFeatures($metadata);

        $hasGroups = $activeFeatures['groups'];
        $hasSkipNull = $activeFeatures['skip_null_values'];
        $hasMaxDepth = $activeFeatures['max_depth'];
        $hasContext = $activeFeatures['context'];

        $stmts = [];

        // Circular-reference guard
        if ($needsCircularRef) {
            $stmts = array_merge($stmts, $this->buildCircularReferenceGuard($metadata->getClassName()));
        }

        // $groups = (array) ($context[AbstractNormalizer::GROUPS] ?? []);
        // $groupsLookup = array_fill_keys($groups, true);
        if ($hasGroups) {
            $stmts[] = new Expression(
                new Assign(
                    new Variable('groups'),
                    new CastArray(
                        new Coalesce(
                            new ArrayDimFetch(
                                new Variable('context'),
                                new ClassConstFetch(new Name('AbstractNormalizer'), 'GROUPS'),
                            ),
                            new Array_([], ['kind' => Array_::KIND_SHORT]),
                        ),
                    ),
                ),
            );

            $stmts[] = new Expression(
                new Assign(new Variable('groupsLookup'), new FuncCall(new Name('array_fill_keys'), [
                    new Arg(new Variable('groups')),
                    new Arg(new ConstFetch(new Name('true'))),
                ])),
            );
        }

        // $skipNullValues = (bool) ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false);
        if ($hasSkipNull) {
            $stmts[] = new Expression(
                new Assign(
                    new Variable('skipNullValues'),
                    new CastBool(
                        new Coalesce(
                            new ArrayDimFetch(
                                new Variable('context'),
                                new ClassConstFetch(new Name('AbstractObjectNormalizer'), 'SKIP_NULL_VALUES'),
                            ),
                            new ConstFetch(new Name('false')),
                        ),
                    ),
                ),
            );
        }

        // $data = [];
        $stmts[] = new Expression(new Assign(new Variable('data'), new Array_([], ['kind' => Array_::KIND_SHORT])));

        $visibleProperties = array_filter(
            $metadata->getProperties(),
            static fn(PropertyMetadata $p): bool => !$p->isIgnored(),
        );

        if ($visibleProperties === []) {
            $stmts[] = new Return_(new Variable('data'));
        } else {
            foreach ($visibleProperties as $property) {
                $stmts = array_merge($stmts, $this->buildPropertyStatements(
                    $property,
                    $metadata->getClassName(),
                    $hasGroups,
                    $hasSkipNull,
                    $hasMaxDepth,
                    $hasContext,
                ));
            }

            $stmts[] = new Return_(new Variable('data'));
        }

        $method = $this->factory
            ->method('normalize')
            ->makePublic()
            ->addParam($this->factory->param('object')->setType('mixed'))
            ->addParam(
                $this->factory
                    ->param('format')
                    ->setType(new NullableType(new Identifier('string')))
                    ->setDefault(null),
            )
            ->addParam($this->factory->param('context')->setType('array')->setDefault([]))
            ->setReturnType(new UnionType([
                new Identifier('array'),
                new Identifier('string'),
                new Identifier('int'),
                new Identifier('float'),
                new Identifier('bool'),
                new FullyQualified('ArrayObject'),
                new Identifier('null'),
            ]))
            ->addStmts($stmts)
            ->setDocComment(new Doc(
                "/**\n"
                . " * @param \\"
                . $metadata->getClassName()
                . " \$object\n"
                . " * @param array<string, mixed>      \$context\n"
                . " *\n"
                . " * @return array<string, mixed>\n"
                . ' */',
            ));

        return $method->getNode();
    }

    /**
     * Build the circular-reference detection guard statements.
     *
     * @return Stmt[]
     */
    private function buildCircularReferenceGuard(string $targetFqcn): array
    {
        $stmts = [];

        // $objectHash = spl_object_hash($object);
        $stmts[] = new Expression(
            new Assign(new Variable('objectHash'), new FuncCall(new Name('spl_object_hash'), [new Arg(
                new Variable('object'),
            )])),
        );

        // $context['circular_reference_limit_counters'] ??= [];
        $stmts[] = new Expression(
            new Expr\AssignOp\Coalesce(
                new ArrayDimFetch(new Variable('context'), new String_('circular_reference_limit_counters')),
                new Array_([], ['kind' => Array_::KIND_SHORT]),
            ),
        );

        // if (isset($context['circular_reference_limit_counters'][$objectHash])) { ... } else { ... }
        $countersKey = new ArrayDimFetch(new Variable('context'), new String_('circular_reference_limit_counters'));

        $counterEntry = new ArrayDimFetch($countersKey, new Variable('objectHash'));

        // inner-if body
        $innerStmts = [];

        // $limit = (int) ($context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT] ?? 1);
        $innerStmts[] = new Expression(
            new Assign(
                new Variable('limit'),
                new CastInt(
                    new Coalesce(
                        new ArrayDimFetch(
                            new Variable('context'),
                            new ClassConstFetch(new Name('AbstractNormalizer'), 'CIRCULAR_REFERENCE_LIMIT'),
                        ),
                        new Int_(1),
                    ),
                ),
            ),
        );

        // if ($counter >= $limit) { handler | throw }
        $handlerKey = new ArrayDimFetch(
            new Variable('context'),
            new ClassConstFetch(new Name('AbstractNormalizer'), 'CIRCULAR_REFERENCE_HANDLER'),
        );

        $innerStmts[] = new If_(new GreaterOrEqual($counterEntry, new Variable('limit')), [
            'stmts' => [
                new If_(
                    new FuncCall(new Name('isset'), [new Arg($handlerKey)]),
                    [
                        'stmts' => [
                            new Return_(new FuncCall($handlerKey, [
                                new Arg(new Variable('object')),
                                new Arg(new Variable('format')),
                                new Arg(new Variable('context')),
                            ])),
                        ],
                    ],
                ),
                new Expression(new Throw_(new New_(new Name('CircularReferenceException'), [
                    new Arg(new FuncCall(new Name('sprintf'), [
                        new Arg(
                            new String_(
                                'A circular reference has been detected when serializing the object of class "%s" (configured limit: %d).',
                            ),
                        ),
                        new Arg(new String_($targetFqcn)),
                        new Arg(new Variable('limit')),
                    ])),
                ]))),
            ],
        ]);

        // ++$context['circular_reference_limit_counters'][$objectHash];
        $innerStmts[] = new Expression(new PreInc($counterEntry));

        // else: $context['circular_reference_limit_counters'][$objectHash] = 1;
        $elseStmts = [
            new Expression(new Assign($counterEntry, new Int_(1))),
        ];

        $stmts[] = new If_(
            new FuncCall(new Name('isset'), [new Arg($counterEntry)]),
            [
                'stmts' => $innerStmts,
                'else' => new Else_($elseStmts),
            ],
        );

        return $stmts;
    }

    /**
     * Build all statements for a single property.
     *
     * @return Stmt[]
     */
    private function buildPropertyStatements(
        PropertyMetadata $property,
        string $ownerClass,
        bool $hasGroups,
        bool $hasSkipNull,
        bool $hasMaxDepth,
        bool $hasContext,
    ): array {
        $needsGroupBlock = $hasGroups && $property->getGroups() !== [];
        $needsMaxDepth =
            $hasMaxDepth && $property->getMaxDepth() !== null && ($property->isNested() || $property->isCollection());

        $rawKey = $property->getSerializedName() ?? $property->getName();

        // --- key expression --------------------------------------------------
        $coreStmts = [];
        $keyExpr = new String_($rawKey);

        // --- accessor expression ---------------------------------------------
        $accessorType = $property->getAccessorType();
        $rawValueExpr = $this->buildAccessorExpr($accessorType, $property->getAccessor());

        // --- value assignment (possibly wrapped in max-depth if) -------------
        $valueStmts = [];

        if ($needsMaxDepth) {
            // $_depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, 'OwnerClass', 'prop');
            $valueStmts[] = new Expression(new Assign(new Variable('_depthKey'), new FuncCall(new Name('sprintf'), [
                new Arg(new ClassConstFetch(new Name('AbstractObjectNormalizer'), 'DEPTH_KEY_PATTERN')),
                new Arg(new String_($ownerClass)),
                new Arg(new String_($property->getName())),
            ])));

            // $_currentDepth = (int) ($context[$_depthKey] ?? 0);
            $valueStmts[] = new Expression(
                new Assign(
                    new Variable('_currentDepth'),
                    new CastInt(
                        new Coalesce(
                            new ArrayDimFetch(new Variable('context'), new Variable('_depthKey')),
                            new Int_(0),
                        ),
                    ),
                ),
            );

            // if ($_currentDepth < LIMIT) { $context[$_depthKey]++; <assign> }
            $maxDepthBody = [];
            $maxDepthBody[] = new Expression(
                new Assign(
                    new ArrayDimFetch(new Variable('context'), new Variable('_depthKey')),
                    new Plus(new Variable('_currentDepth'), new Int_(1)),
                ),
            );
            $maxDepthBody = array_merge($maxDepthBody, $this->buildValueAssignment(
                $property,
                $rawValueExpr,
                $keyExpr,
                $hasSkipNull,
                $hasGroups,
                $hasContext,
            ));

            $maxDepthIf = new If_(
                new Smaller(new Variable('_currentDepth'), new Int_((int) $property->getMaxDepth())),
                ['stmts' => $maxDepthBody],
            );
            $maxDepthIf->setAttribute('comments', [
                new Comment('// max-depth: ' . $property->getName() . ' (limit=' . $property->getMaxDepth() . ')'),
            ]);
            $valueStmts[] = $maxDepthIf;
        } else {
            $valueStmts = array_merge($valueStmts, $this->buildValueAssignment(
                $property,
                $rawValueExpr,
                $keyExpr,
                $hasSkipNull,
                $hasGroups,
                $hasContext,
            ));
        }

        $coreStmts = array_merge($coreStmts, $valueStmts);

        // --- groups wrapper --------------------------------------------------
        if ($needsGroupBlock) {
            // if ($groups === [] || isset($groupsLookup['group1']) || isset($groupsLookup['group2']) || ...) { ... }
            $groupCondition = new Identical(new Variable('groups'), new Array_([], ['kind' => Array_::KIND_SHORT]));

            foreach ($property->getGroups() as $group) {
                $groupCondition = new Expr\BinaryOp\BooleanOr($groupCondition, new Isset_([new ArrayDimFetch(
                    new Variable('groupsLookup'),
                    new String_($group),
                )]));
            }

            return [new If_($groupCondition, ['stmts' => $coreStmts])];
        }

        return $coreStmts;
    }

    /**
     * Dispatch to the correct value-assignment builder based on property type.
     *
     * @return Stmt[]
     */
    private function buildValueAssignment(
        PropertyMetadata $property,
        Expr $rawValueExpr,
        Expr $keyExpr,
        bool $hasSkipNull,
        bool $hasGroups,
        bool $hasContext,
    ): array {
        if ($property->isNested()) {
            return $this->buildNestedValueAssignment(
                $property,
                $rawValueExpr,
                $keyExpr,
                $hasSkipNull,
                $hasGroups,
                $hasContext,
            );
        }

        if ($property->isCollection()) {
            return $this->buildCollectionValueAssignment(
                $property,
                $rawValueExpr,
                $keyExpr,
                $hasSkipNull,
                $hasGroups,
                $hasContext,
            );
        }

        return $this->buildScalarValueAssignment($property, $rawValueExpr, $keyExpr, $hasSkipNull);
    }

    /**
     * Build the value-assignment block for a nested object property.
     *
     * @return Stmt[]
     */
    private function buildNestedValueAssignment(
        PropertyMetadata $property,
        Expr $rawValueExpr,
        Expr $keyExpr,
        bool $hasSkipNull,
        bool $hasGroups,
        bool $hasContext,
    ): array {
        $needsNullCheck = $property->isNullable() || $hasSkipNull;
        $null = new ConstFetch(new Name('null'));

        // Check if we need a separate $_context variable for complex context merging
        $contextResult = $this->buildContextExprWithStatements($property, $hasGroups, $hasContext);
        $contextStmts = $contextResult['statements'];
        $contextExpr = $contextResult['expression'];

        // Helper: $this->normalizer->normalize($_val, $format, $contextExpr)
        $normalizeVar = new MethodCall(new PropertyFetch(new Variable('this'), 'normalizer'), 'normalize', [
            new Arg(new Variable('_val')),
            new Arg(new Variable('format')),
            new Arg($contextExpr),
        ]);

        // Helper: $this->normalizer->normalize(<direct>, $format, $contextExpr)
        $normalizeDirect = new MethodCall(new PropertyFetch(new Variable('this'), 'normalizer'), 'normalize', [
            new Arg($rawValueExpr),
            new Arg(new Variable('format')),
            new Arg($contextExpr),
        ]);

        $dataSet = fn(Expr $val) => new Expression(new Assign(new ArrayDimFetch(new Variable('data'), $keyExpr), $val));

        if (!$needsNullCheck) {
            $stmts = $contextStmts;
            $stmts[] = $dataSet($normalizeDirect);
            return $stmts;
        }

        $stmts = [];
        $stmts[] = new Expression(new Assign(new Variable('_val'), $rawValueExpr));

        $notNull = new NotIdentical(new Variable('_val'), $null);

        // Build inner statements with context assignment
        $innerStmts = $contextStmts;
        $innerStmts[] = $dataSet($normalizeVar);

        if ($property->isNullable() && $hasSkipNull) {
            $stmts[] = new If_($notNull, [
                'stmts' => $innerStmts,
                'elseifs' => [
                    new ElseIf_(new BooleanNot(new Variable('skipNullValues')), [$dataSet($null)]),
                ],
            ]);
        } elseif ($property->isNullable()) {
            $stmts[] = new If_($notNull, [
                'stmts' => $innerStmts,
                'else' => new Else_([$dataSet($null)]),
            ]);
        } else {
            // not nullable, but skip_null_values active
            $combinedStmts = $contextStmts;
            $combinedStmts[] = $dataSet(new Ternary($notNull, $normalizeVar, $null));
            $stmts[] = new If_(new Expr\BinaryOp\BooleanOr($notNull, new BooleanNot(new Variable('skipNullValues'))), [
                'stmts' => $combinedStmts,
            ]);
        }

        return $stmts;
    }

    /**
     * Build the value-assignment block for a collection property.
     *
     * @return Stmt[]
     */
    private function buildCollectionValueAssignment(
        PropertyMetadata $property,
        Expr $rawValueExpr,
        Expr $keyExpr,
        bool $hasSkipNull,
        bool $hasGroups,
        bool $hasContext,
    ): array {
        $null = new ConstFetch(new Name('null'));
        $dataSet = fn(Expr $val) => new Expression(new Assign(new ArrayDimFetch(new Variable('data'), $keyExpr), $val));

        // Check if we need a separate $_context variable for complex context merging
        $contextResult = $this->buildContextExprWithStatements($property, $hasGroups, $hasContext);
        $contextStmts = $contextResult['statements'];
        $contextExpr = $contextResult['expression'];

        $normalizeCollection = fn(Expr $ref) => new MethodCall(
            new PropertyFetch(new Variable('this'), 'normalizer'),
            'normalize',
            [new Arg($ref), new Arg(new Variable('format')), new Arg($contextExpr)],
        );

        $needsNullCheck = $property->isNullable() || $hasSkipNull;

        if (!$needsNullCheck) {
            $stmts = $contextStmts;
            $stmts[] = $dataSet($normalizeCollection($rawValueExpr));
            return $stmts;
        }

        $stmts = [];
        $stmts[] = new Expression(new Assign(new Variable('_collection'), $rawValueExpr));

        $notNull = new NotIdentical(new Variable('_collection'), $null);

        // Build inner statements with context assignment
        $innerStmts = $contextStmts;
        $innerStmts[] = $dataSet($normalizeCollection(new Variable('_collection')));

        if ($hasSkipNull) {
            $stmts[] = new If_($notNull, [
                'stmts' => $innerStmts,
                'elseifs' => [
                    new ElseIf_(new BooleanNot(new Variable('skipNullValues')), [$dataSet($null)]),
                ],
            ]);
        } else {
            $stmts[] = new If_($notNull, [
                'stmts' => $innerStmts,
                'else' => new Else_([$dataSet($null)]),
            ]);
        }

        return $stmts;
    }

    /**
     * Build the value-assignment block for a plain scalar property.
     *
     * @return Stmt[]
     */
    private function buildScalarValueAssignment(
        PropertyMetadata $property,
        Expr $rawValueExpr,
        Expr $keyExpr,
        bool $hasSkipNull,
    ): array {
        $null = new ConstFetch(new Name('null'));
        $dataSet = fn(Expr $val) => new Expression(new Assign(new ArrayDimFetch(new Variable('data'), $keyExpr), $val));

        // When the property cannot be null (not nullable and type is known/not mixed),
        // skip the null-guard entirely and assign the value directly, even when
        // skip_null_values is active. Unknown types (null) and mixed must keep the guard
        // since they can legitimately hold null at runtime.
        if (
            $hasSkipNull === false
            || $property->isNullable() === false && is_string($property->getType()) && $property->getType() !== 'mixed'
        ) {
            return [$dataSet($rawValueExpr)];
        }

        $stmts = [];
        $stmts[] = new Expression(new Assign(new Variable('_val'), $rawValueExpr));

        $stmts[] = new If_(
            new Expr\BinaryOp\BooleanOr(
                new NotIdentical(new Variable('_val'), $null),
                new BooleanNot(new Variable('skipNullValues')),
            ),
            ['stmts' => [$dataSet(new Variable('_val'))]],
        );

        return $stmts;
    }

    /**
     * Build the supportsNormalization() method AST node.
     */
    private function buildSupportsNormalizationMethod(string $targetFqcn): Stmt\ClassMethod
    {
        $shortName = $this->shortName($targetFqcn);

        $method = $this->factory
            ->method('supportsNormalization')
            ->makePublic()
            ->addParam($this->factory->param('data')->setType('mixed'))
            ->addParam(
                $this->factory
                    ->param('format')
                    ->setType(new NullableType(new Identifier('string')))
                    ->setDefault(null),
            )
            ->addParam($this->factory->param('context')->setType('array')->setDefault([]))
            ->setReturnType('bool')
            ->addStmt(new Return_(new Instanceof_(new Variable('data'), new Name($shortName))))
            ->setDocComment(new Doc("/**\n * @param array<string, mixed> \$context\n */"));

        return $method->getNode();
    }

    /**
     * Build the getSupportedTypes() method AST node.
     */
    private function buildGetSupportedTypesMethod(string $targetFqcn): Stmt\ClassMethod
    {
        $shortName = $this->shortName($targetFqcn);

        $method = $this->factory
            ->method('getSupportedTypes')
            ->makePublic()
            ->addParam($this->factory->param('format')->setType(new NullableType(new Identifier('string'))))
            ->setReturnType('array')
            ->addStmt(new Return_(
                new Array_([
                    new ArrayItem(new ConstFetch(new Name('true')), new ClassConstFetch(new Name($shortName), 'class')),
                ], ['kind' => Array_::KIND_SHORT]),
            ))
            ->setDocComment(new Doc("/**\n * @return array<class-string|'*'|'object'|string, bool|null>\n */"));

        return $method->getNode();
    }

    /**
     * Compute which features are actually active for the given class.
     *
     * @return array{groups: bool, max_depth: bool, circular_reference: bool, skip_null_values: bool, context: bool}
     */
    private function resolveActiveFeatures(ClassMetadata $metadata): array
    {
        return [
            'groups' => $this->features['groups'] && $metadata->hasGroupConstraints(),
            'max_depth' => $this->features['max_depth'] && $metadata->hasMaxDepthConstraints(),
            'circular_reference' => $this->features['circular_reference'] && $metadata->hasNestedObjects(),
            'skip_null_values' => $this->features['skip_null_values'],
            'context' => $this->features['context'] ?? true,
        ];
    }

    /**
     * Build an Expr node that reads the property value from $object using the
     * given accessor type and accessor string (property name or method name).
     */
    private function buildAccessorExpr(AccessorType $accessorType, string $accessor): Expr
    {
        return match ($accessorType) {
            AccessorType::PROPERTY => new PropertyFetch(new Variable('object'), $accessor),
            AccessorType::METHOD => new MethodCall(new Variable('object'), $accessor),
        };
    }

    /**
     * Return the short (unqualified) class name from a fully-qualified name.
     */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, "\\");

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }

    /**
     * Build the context expression and any required preceding statements for a normalize call.
     *
     * When the property has a normalization context defined via the #[Context] attribute,
     * this generates code to merge the context. If contexts have group conditions,
     * runtime group checks are generated with a separate $_context variable assignment.
     *
     * @return array{statements: Stmt[], expression: Expr}
     */
    private function buildContextExprWithStatements(
        PropertyMetadata $property,
        bool $hasGroups = false,
        bool $hasContext = true,
    ): array {
        // If context feature is disabled, always return plain $context
        if (!$hasContext) {
            return [
                'statements' => [],
                'expression' => new Variable('context'),
            ];
        }

        $contexts = $property->getContexts();

        if ($contexts === []) {
            return [
                'statements' => [],
                'expression' => new Variable('context'),
            ];
        }

        // Separate unconditional and conditional contexts
        $unconditionalContexts = [];
        $conditionalContexts = [];

        foreach ($contexts as $context) {
            if (!$context->hasNormalizationContext()) {
                continue;
            }

            $contextGroups = $context->getGroups();

            if ($contextGroups === [] || !$hasGroups) {
                $unconditionalContexts[] = $context;
            } else {
                $conditionalContexts[] = $context;
            }
        }

        // If no contexts to merge, return plain $context
        if ($unconditionalContexts === [] && $conditionalContexts === []) {
            return [
                'statements' => [],
                'expression' => new Variable('context'),
            ];
        }

        // If only unconditional contexts, we can inline the array_merge
        if ($conditionalContexts === []) {
            $mergeArgs = [new Arg(new Variable('context'))];
            foreach ($unconditionalContexts as $context) {
                $mergeArgs[] = new Arg($this->buildArrayExpr($context->getNormalizationContext()));
            }

            return [
                'statements' => [],
                'expression' => new FuncCall(new Name('array_merge'), $mergeArgs),
            ];
        }

        // For conditional contexts, we need to build a $_context variable
        // Start with: $_context = array_merge($context, ...unconditional contexts)
        $statements = [];

        $mergeArgs = [new Arg(new Variable('context'))];
        foreach ($unconditionalContexts as $context) {
            $mergeArgs[] = new Arg($this->buildArrayExpr($context->getNormalizationContext()));
        }

        $statements[] = new Expression(
            new Assign(new Variable('_context'), new FuncCall(new Name('array_merge'), $mergeArgs)),
        );

        // Add conditional context merges
        foreach ($conditionalContexts as $context) {
            $contextArray = $this->buildArrayExpr($context->getNormalizationContext());
            $contextGroups = $context->getGroups();

            // Build condition: $groups === [] || isset($groupsLookup['group1']) || ...
            $condition = new Identical(new Variable('groups'), new Array_([], ['kind' => Array_::KIND_SHORT]));

            foreach ($contextGroups as $group) {
                $condition = new Expr\BinaryOp\BooleanOr($condition, new Isset_([new ArrayDimFetch(
                    new Variable('groupsLookup'),
                    new String_($group),
                )]));
            }

            // Generate: if ($groups === [] || isset(...)) { $_context = array_merge($_context, [...]) }
            $statements[] = new If_($condition, [
                'stmts' => [
                    new Expression(new Assign(new Variable('_context'), new FuncCall(new Name('array_merge'), [
                        new Arg(new Variable('_context')),
                        new Arg($contextArray),
                    ]))),
                ],
            ]);
        }

        return [
            'statements' => $statements,
            'expression' => new Variable('_context'),
        ];
    }

    /**
     * Convert a PHP array to an AST Array_ expression.
     *
     * @param array<string|int, mixed> $array
     */
    private function buildArrayExpr(array $array): Array_
    {
        $items = [];

        foreach ($array as $key => $value) {
            $keyExpr = is_int($key) ? new Int_($key) : new String_($key);
            $valueExpr = $this->buildValueExpr($value);
            $items[] = new ArrayItem($valueExpr, $keyExpr);
        }

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * Convert a PHP value to an AST expression.
     */
    private function buildValueExpr(mixed $value): Expr
    {
        if (is_null($value)) {
            return new ConstFetch(new Name('null'));
        }

        if (is_bool($value)) {
            return new ConstFetch(new Name($value ? 'true' : 'false'));
        }

        if (is_int($value)) {
            return new Int_($value);
        }

        if (is_float($value)) {
            return new Float_($value);
        }

        if (is_string($value)) {
            return new String_($value);
        }

        if (is_array($value)) {
            return $this->buildArrayExpr($value);
        }

        throw new UnexpectedValueException();
    }
}
