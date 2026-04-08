<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Generator;

use BuildableSerializerBundle\Metadata\AccessorType;
use BuildableSerializerBundle\Metadata\ClassMetadata;
use BuildableSerializerBundle\Metadata\MetadataFactoryInterface;
use BuildableSerializerBundle\Metadata\PropertyMetadata;
use PhpParser\BuilderFactory;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
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
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\Instanceof_;
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
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
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

final class NormalizerGenerator implements NormalizerGeneratorInterface
{
    /**
     * Priority assigned to generated normalizers in the serializer chain.
     * A higher value means the normalizer is consulted earlier.
     * Individual generated classes can override this via the NORMALIZER_PRIORITY constant.
     */
    private const DEFAULT_PRIORITY = 200;

    private BuilderFactory $factory;
    private PrettyPrinter $printer;

    /**
     * @param MetadataFactoryInterface $metadataFactory Factory used to obtain ClassMetadata.
     * @param string                   $cacheDir        Absolute path of the generation target directory.
     * @param string                   $generatedNamespace Root PHP namespace for all generated classes.
     * @param array{
     *     groups: bool,
     *     max_depth: bool,
     *     circular_reference: bool,
     *     name_converter: bool,
     *     skip_null_values: bool,
     * } $features Active code-generation feature flags.
     * @param array{
     *     strict_types: bool,
     * } $generation Code style / output options.
     */
    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory,
        private readonly string $cacheDir,
        private readonly string $generatedNamespace,
        private readonly array $features,
        private readonly array $generation,
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
     * {@see \BuildableSerializerBundle\Metadata\ClassMetadata} for a class
     * without having to inject the factory separately.
     */
    public function getMetadataFactory(): MetadataFactoryInterface
    {
        return $this->metadataFactory;
    }

    /** @inheritdoc */
    public function generateAll(iterable $metadataCollection): array
    {
        $paths = [];
        $classmap = [];

        foreach ($metadataCollection as $metadata) {
            $filePath = $this->generateAndWrite($metadata);
            $fqcn = $this->resolveNormalizerFqcn($metadata);
            $paths[] = $filePath;
            $classmap[$fqcn] = $filePath;
        }

        if ($classmap !== []) {
            $this->writeClassmap($classmap);
        }

        return $paths;
    }

    /**
     * Write the autoload classmap file that the compiler pass uses to register
     * generated normalizers as Symfony services.
     *
     * @param iterable<string, string> $classmap FQCN => absolute file path
     */
    private function writeClassmap(iterable $classmap): void
    {
        $items = [];
        foreach ($classmap as $fqcn => $filePath) {
            $items[] = new ArrayItem(new String_($filePath), new String_($fqcn));
        }

        $stmts = [
            new Return_(new Array_($items, ['kind' => Array_::KIND_SHORT])),
        ];

        $content =
            "<?php\n\n// @generated by buildable/serializer-bundle\n\n" . $this->printer->prettyPrint($stmts) . "\n";

        if (is_dir($this->cacheDir) === false) {
            mkdir($this->cacheDir, 0755, true);
        }

        file_put_contents($this->cacheDir . '/autoload.php', $content);
    }

    /** @inheritdoc */
    public function generateAndWrite(ClassMetadata $metadata): string
    {
        $source = $this->generate($metadata);
        $filePath = $this->resolveFilePath($metadata);
        $directory = \dirname($filePath);

        if (is_dir($directory) === false) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filePath, $source);

        return $filePath;
    }

    /**
     * Generate and return the complete PHP source code string for a normalizer
     * class that handles the class described by the given {@see ClassMetadata}.
     *
     * The returned string is ready to be written verbatim to a `.php` file.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    public function generate(ClassMetadata $metadata): string
    {
        $normalizerNs = $this->resolveNormalizerNamespace($metadata);
        $normalizerClass = $this->resolveNormalizerClassName($metadata);
        $targetFqcn = $metadata->getClassName();

        $needsAware = $this->needsNormalizerAware($metadata);
        $needsAbstractNorm = $this->needsAbstractNormalizerConstants($metadata);
        $needsAbstractObj = $this->needsAbstractObjectNormalizerConstants($metadata);
        $needsNameConv = $this->features['name_converter'];
        $needsCircularRef = $this->features['circular_reference'] && $metadata->hasNestedObjects();

        // Top-level statements
        $stmts = [];

        // declare(strict_types=1)
        if ($this->generation['strict_types']) {
            $stmts[] = new Declare_([new DeclareItem('strict_types', new Int_(1))]);
        }

        // use statements
        $uses = $this->buildUseStatements(
            $targetFqcn,
            $needsAware,
            $needsAbstractNorm,
            $needsAbstractObj,
            $needsNameConv,
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

        $priorityConst = new ClassConst([new Node\Const_(
            'NORMALIZER_PRIORITY',
            new Int_(self::DEFAULT_PRIORITY),
        )], Class_::MODIFIER_PUBLIC);
        $priorityConst->setAttribute('comments', [new Doc(
            '/** Priority in the Symfony Serializer normalizer chain (higher = earlier). */',
        )]);
        $classStmts[] = $priorityConst;

        $classStmts[] = $this->buildNormalizeMethod($metadata, $needsCircularRef);
        $classStmts[] = $this->buildSupportsNormalizationMethod($targetFqcn);
        $classStmts[] = $this->buildGetSupportedTypesMethod($targetFqcn);

        $classNode = new Class_(
            $normalizerClass,
            [
                'flags' => Class_::MODIFIER_FINAL,
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

    /** @inheritdoc */
    public function resolveNormalizerFqcn(ClassMetadata $metadata): string
    {
        return $this->resolveNormalizerNamespace($metadata) . "\\" . $this->resolveNormalizerClassName($metadata);
    }

    /** @inheritdoc */
    public function resolveFilePath(ClassMetadata $metadata): string
    {
        return (
            rtrim($this->cacheDir, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . $this->buildPsr4RelativePath($metadata)
        );
    }

    /**
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function resolveNormalizerNamespace(ClassMetadata $metadata): string
    {
        $classNs = $metadata->getNamespace();
        $base = rtrim($this->generatedNamespace, "\\");

        return $classNs !== '' ? $base . "\\" . $classNs : $base;
    }

    /**
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function resolveNormalizerClassName(ClassMetadata $metadata): string
    {
        return $metadata->getShortName() . 'Normalizer';
    }

    /**
     * Build the relative file path under cacheDir following PSR-4 conventions.
     *
     * Example:
     *   class = App\Entity\User
     *   → App/Entity/UserNormalizer.php
     */
    private function buildPsr4RelativePath(ClassMetadata $metadata): string
    {
        $classNs = $metadata->getNamespace();
        $fileName = $metadata->getShortName() . 'Normalizer.php';

        if ($classNs === '') {
            return $fileName;
        }

        return str_replace("\\", \DIRECTORY_SEPARATOR, $classNs) . \DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * @return list<string>
     */
    private function buildUseStatements(
        string $targetFqcn,
        bool $needsAware,
        bool $needsAbstractNorm,
        bool $needsAbstractObj,
        bool $needsNameConv,
        bool $needsCircularRef,
    ): array {
        /** @var array<string, true> $set */
        $set = [];

        $set[$targetFqcn] = true;
        $set["BuildableSerializerBundle\\Normalizer\\GeneratedNormalizerInterface"] = true;
        $set["Symfony\\Component\\Serializer\\Normalizer\\NormalizerInterface"] = true;

        if ($needsAware) {
            $set["Symfony\\Component\\Serializer\\Normalizer\\NormalizerAwareInterface"] = true;
            $set["Symfony\\Component\\Serializer\\Normalizer\\NormalizerAwareTrait"] = true;
        }

        if ($needsAbstractNorm) {
            $set["Symfony\\Component\\Serializer\\Normalizer\\AbstractNormalizer"] = true;
        }

        if ($needsAbstractObj) {
            $set["Symfony\\Component\\Serializer\\Normalizer\\AbstractObjectNormalizer"] = true;
        }

        if ($needsNameConv) {
            $set["Symfony\\Component\\Serializer\\NameConverter\\NameConverterInterface"] = true;
        }

        if ($needsCircularRef) {
            $set["Symfony\\Component\\Serializer\\Exception\\CircularReferenceException"] = true;
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
            || $this->features['name_converter']
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
        $hasNameConv = $activeFeatures['name_converter'];
        $hasMaxDepth = $activeFeatures['max_depth'];

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
                new Assign(
                    new Variable('groupsLookup'),
                    new FuncCall(new Name('array_fill_keys'), [
                        new Arg(new Variable('groups')),
                        new Arg(new ConstFetch(new Name('true'))),
                    ]),
                ),
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

        // $nameConverter = $context[AbstractNormalizer::NAME_CONVERTER] ?? null;
        if ($hasNameConv) {
            $stmts[] = new Expression(
                new Assign(
                    new Variable('nameConverter'),
                    new Coalesce(
                        new ArrayDimFetch(
                            new Variable('context'),
                            new ClassConstFetch(new Name('AbstractNormalizer'), 'NAME_CONVERTER'),
                        ),
                        new ConstFetch(new Name('null')),
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
                    $hasNameConv,
                    $hasMaxDepth,
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
        bool $hasNameConv,
        bool $hasMaxDepth,
    ): array {
        $needsGroupBlock = $hasGroups && $property->getGroups() !== [];
        $needsMaxDepth =
            $hasMaxDepth && $property->getMaxDepth() !== null && ($property->isNested() || $property->isCollection());

        $rawKey = $property->getSerializedName() ?? $property->getName();

        // --- key expression --------------------------------------------------
        $coreStmts = [];

        if ($hasNameConv) {
            // $_key = $nameConverter instanceof NameConverterInterface
            //     ? $nameConverter->normalize('rawKey', 'OwnerClass', $format, $context)
            //     : 'rawKey';
            $coreStmts[] = new Expression(
                new Assign(
                    new Variable('_key'),
                    new Ternary(
                        new Instanceof_(new Variable('nameConverter'), new Name('NameConverterInterface')),
                        new MethodCall(new Variable('nameConverter'), 'normalize', [
                            new Arg(new String_($rawKey)),
                            new Arg(new String_($ownerClass)),
                            new Arg(new Variable('format')),
                            new Arg(new Variable('context')),
                        ]),
                        new String_($rawKey),
                    ),
                ),
            );
            $keyExpr = new Variable('_key');
        } else {
            $keyExpr = new String_($rawKey);
        }

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
            ));
        }

        $coreStmts = array_merge($coreStmts, $valueStmts);

        // --- groups wrapper --------------------------------------------------
        if ($needsGroupBlock) {
            // if ($groups === [] || isset($groupsLookup['group1']) || isset($groupsLookup['group2']) || ...) { ... }
            $groupCondition = new Identical(
                new Variable('groups'),
                new Array_([], ['kind' => Array_::KIND_SHORT]),
            );

            foreach ($property->getGroups() as $group) {
                $groupCondition = new Expr\BinaryOp\BooleanOr(
                    $groupCondition,
                    new Isset_([new ArrayDimFetch(new Variable('groupsLookup'), new String_($group))]),
                );
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
    ): array {
        if ($property->isNested()) {
            return $this->buildNestedValueAssignment($property, $rawValueExpr, $keyExpr, $hasSkipNull);
        }

        if ($property->isCollection()) {
            return $this->buildCollectionValueAssignment($property, $rawValueExpr, $keyExpr, $hasSkipNull);
        }

        return $this->buildScalarValueAssignment($rawValueExpr, $keyExpr, $hasSkipNull);
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
    ): array {
        $needsNullCheck = $property->isNullable() || $hasSkipNull;
        $null = new ConstFetch(new Name('null'));

        // Helper: $this->normalizer->normalize($_val, $format, $context)
        $normalizeVar = new MethodCall(new PropertyFetch(new Variable('this'), 'normalizer'), 'normalize', [
            new Arg(new Variable('_val')),
            new Arg(new Variable('format')),
            new Arg(new Variable('context')),
        ]);

        // Helper: $this->normalizer->normalize(<direct>, $format, $context)
        $normalizeDirect = new MethodCall(new PropertyFetch(new Variable('this'), 'normalizer'), 'normalize', [
            new Arg($rawValueExpr),
            new Arg(new Variable('format')),
            new Arg(new Variable('context')),
        ]);

        $dataSet = fn(Expr $val) => new Expression(new Assign(new ArrayDimFetch(new Variable('data'), $keyExpr), $val));

        if (!$needsNullCheck) {
            return [$dataSet($normalizeDirect)];
        }

        $stmts = [];
        $stmts[] = new Expression(new Assign(new Variable('_val'), $rawValueExpr));

        $notNull = new NotIdentical(new Variable('_val'), $null);

        if ($property->isNullable() && $hasSkipNull) {
            $stmts[] = new If_($notNull, [
                'stmts' => [$dataSet($normalizeVar)],
                'elseifs' => [
                    new ElseIf_(new BooleanNot(new Variable('skipNullValues')), [$dataSet($null)]),
                ],
            ]);
        } elseif ($property->isNullable()) {
            $stmts[] = new If_($notNull, [
                'stmts' => [$dataSet($normalizeVar)],
                'else' => new Else_([$dataSet($null)]),
            ]);
        } else {
            // not nullable, but skip_null_values active
            $stmts[] = new If_(new Expr\BinaryOp\BooleanOr($notNull, new BooleanNot(new Variable('skipNullValues'))), [
                'stmts' => [
                    $dataSet(new Ternary($notNull, $normalizeVar, $null)),
                ],
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
    ): array {
        $null = new ConstFetch(new Name('null'));
        $dataSet = fn(Expr $val) => new Expression(new Assign(new ArrayDimFetch(new Variable('data'), $keyExpr), $val));

        $normalizeCollection = fn(Expr $ref) => new MethodCall(
            new PropertyFetch(new Variable('this'), 'normalizer'),
            'normalize',
            [new Arg($ref), new Arg(new Variable('format')), new Arg(new Variable('context'))],
        );

        $needsNullCheck = $property->isNullable() || $hasSkipNull;

        if (!$needsNullCheck) {
            return [$dataSet($normalizeCollection($rawValueExpr))];
        }

        $stmts = [];
        $stmts[] = new Expression(new Assign(new Variable('_collection'), $rawValueExpr));

        $notNull = new NotIdentical(new Variable('_collection'), $null);

        if ($hasSkipNull) {
            $stmts[] = new If_($notNull, [
                'stmts' => [$dataSet($normalizeCollection(new Variable('_collection')))],
                'elseifs' => [
                    new ElseIf_(new BooleanNot(new Variable('skipNullValues')), [$dataSet($null)]),
                ],
            ]);
        } else {
            $stmts[] = new If_($notNull, [
                'stmts' => [$dataSet($normalizeCollection(new Variable('_collection')))],
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
    private function buildScalarValueAssignment(Expr $rawValueExpr, Expr $keyExpr, bool $hasSkipNull): array
    {
        $null = new ConstFetch(new Name('null'));
        $dataSet = fn(Expr $val) => new Expression(new Assign(new ArrayDimFetch(new Variable('data'), $keyExpr), $val));

        if (!$hasSkipNull) {
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
     * @return array{groups: bool, max_depth: bool, circular_reference: bool, name_converter: bool, skip_null_values: bool}
     */
    private function resolveActiveFeatures(ClassMetadata $metadata): array
    {
        return [
            'groups' => $this->features['groups'] && $metadata->hasGroupConstraints(),
            'max_depth' => $this->features['max_depth'] && $metadata->hasMaxDepthConstraints(),
            'circular_reference' => $this->features['circular_reference'] && $metadata->hasNestedObjects(),
            'name_converter' => $this->features['name_converter'],
            'skip_null_values' => $this->features['skip_null_values'],
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
}
