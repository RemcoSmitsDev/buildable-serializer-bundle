<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Generator;

use Buildable\SerializerBundle\Metadata\AccessorType;
use Buildable\SerializerBundle\Metadata\ClassMetadata;
use Buildable\SerializerBundle\Metadata\MetadataFactoryInterface;
use Buildable\SerializerBundle\Metadata\PropertyMetadata;
use Buildable\SerializerBundle\Generator\CodeBuffer;

/**
 * Generates optimised PHP normalizer source files from {@see ClassMetadata}.
 *
 * The generated normalizers are plain PHP classes that implement
 * {@see \Symfony\Component\Serializer\Normalizer\NormalizerInterface}.
 * They read property values through the accessors discovered at metadata-build
 * time (direct property reads or method calls), completely avoiding runtime
 * reflection and enabling significant throughput improvements over the default
 * {@see \Symfony\Component\Serializer\Normalizer\ObjectNormalizer}.
 *
 * ### Generated class layout
 *
 *   - Namespace: `<generatedNamespace>\<originalClassNamespace>`
 *     (e.g. `BuildableSerializer\Generated\App\Entity`)
 *   - Class name: `<ShortName>Normalizer`
 *     (e.g. `UserNormalizer`)
 *   - Implements: `NormalizerInterface` + optionally `NormalizerAwareInterface`
 *   - Constant: `NORMALIZER_PRIORITY` (used by the compiler pass for ordering)
 *
 * ### Feature flags
 *
 * Each feature can be toggled via the `features` configuration array:
 *
 *   - `groups`             → emit group-filtering logic
 *   - `max_depth`          → emit depth-tracking and capping logic
 *   - `circular_reference` → emit circular-reference detection and handler dispatch
 *   - `name_converter`     → respect a name-converter service from the context
 *   - `skip_null_values`   → omit null properties when context flag is set
 *
 * @see \Buildable\SerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass
 * @see \Buildable\SerializerBundle\CacheWarmer\NormalizerCacheWarmer
 * @see \Buildable\SerializerBundle\Command\GenerateNormalizersCommand
 */
final class NormalizerGenerator implements NormalizerGeneratorInterface
{
    /**
     * Priority assigned to generated normalizers in the serializer chain.
     * A higher value means the normalizer is consulted earlier.
     * Individual generated classes can override this via the NORMALIZER_PRIORITY constant.
     */
    private const DEFAULT_PRIORITY = 200;

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
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the metadata factory used by this generator.
     *
     * Exposed so that consumers (e.g. the console command) can retrieve
     * {@see \Buildable\SerializerBundle\Metadata\ClassMetadata} for a class
     * without having to inject the factory separately.
     */
    public function getMetadataFactory(): MetadataFactoryInterface
    {
        return $this->metadataFactory;
    }

    /**
     * Generate and write normalizer files for every given fully-qualified class
     * name. Returns the absolute paths of all files that were written.
     *
     * @param  string[] $classNames
     * @return string[] Absolute paths of written files, in input order.
     */
    public function generateAll(array $classNames): array
    {
        $paths = [];
        $classmap = [];

        foreach ($classNames as $className) {
            $metadata = $this->metadataFactory->getMetadataFor($className);
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
     * @param array<string, string> $classmap FQCN => absolute file path
     */
    private function writeClassmap(array $classmap): void
    {
        $entries = [];
        foreach ($classmap as $fqcn => $filePath) {
            $entries[] = sprintf(
                "    %s => %s,",
                var_export($fqcn, true),
                var_export($filePath, true),
            );
        }

        $content =
            "<?php\n\n// @generated by buildable/serializer-bundle\n\nreturn [\n" .
            implode("\n", $entries) .
            "\n];\n";

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        file_put_contents($this->cacheDir . "/autoload.php", $content);
    }

    /**
     * Generate the PHP source for a single normalizer class and write it to the
     * resolved file path, creating parent directories as necessary.
     *
     * Returns the absolute path of the written file.
     */
    public function generateAndWrite(ClassMetadata $metadata): string
    {
        $source = $this->generate($metadata);
        $filePath = $this->resolveFilePath($metadata);
        $directory = \dirname($filePath);

        if (!is_dir($directory)) {
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
     */
    public function generate(ClassMetadata $metadata): string
    {
        $buf = new CodeBuffer();

        $normalizerNs = $this->resolveNormalizerNamespace($metadata);
        $normalizerClass = $this->resolveNormalizerClassName($metadata);
        $targetFqcn = $metadata->className;

        $needsAware = $this->needsNormalizerAware($metadata);
        $needsAbstractNorm = $this->needsAbstractNormalizerConstants($metadata);
        $needsAbstractObj = $this->needsAbstractObjectNormalizerConstants(
            $metadata,
        );
        $needsNameConv = $this->features["name_converter"];
        $needsCircularRef =
            $this->features["circular_reference"] &&
            $metadata->hasNestedObjects();

        // ---- File header ----------------------------------------------------
        $buf->line("<?php");
        $buf->blank();

        if ($this->generation["strict_types"]) {
            $buf->line("declare(strict_types=1);");
            $buf->blank();
        }

        // ---- Namespace ------------------------------------------------------
        $buf->line("namespace " . $normalizerNs . ";");
        $buf->blank();

        // ---- Use statements -------------------------------------------------
        $uses = $this->buildUseStatements(
            $targetFqcn,
            $needsAware,
            $needsAbstractNorm,
            $needsAbstractObj,
            $needsNameConv,
            $needsCircularRef,
        );

        foreach ($uses as $use) {
            $buf->line("use " . $use . ";");
        }

        if ($uses !== []) {
            $buf->blank();
        }

        // ---- Class PHPDoc ---------------------------------------------------
        $buf->line("/**");
        $buf->line(" * @generated");
        $buf->line(" *");
        $buf->line(" * Normalizer for \\" . $targetFqcn . ".");
        $buf->line(" *");
        $buf->line(" * THIS FILE IS AUTO-GENERATED. DO NOT EDIT MANUALLY.");
        $buf->line(
            " * Regenerate by running: bin/console buildable:generate-normalizers",
        );
        $buf->line(" */");

        // ---- Class declaration ----------------------------------------------
        $implements = ["NormalizerInterface", "GeneratedNormalizerInterface"];
        $traitsBlock = [];

        if ($needsAware) {
            $implements[] = "NormalizerAwareInterface";
            $traitsBlock[] = "NormalizerAwareTrait";
        }

        $buf->line(
            "final class " .
                $normalizerClass .
                " implements " .
                implode(", ", $implements),
        );
        $buf->line("{");
        $buf->indent();

        foreach ($traitsBlock as $trait) {
            $buf->line("use " . $trait . ";");
        }

        if ($traitsBlock !== []) {
            $buf->blank();
        }

        $buf->line(
            "/** Priority in the Symfony Serializer normalizer chain (higher = earlier). */",
        );
        $buf->line(
            "public const NORMALIZER_PRIORITY = " .
                self::DEFAULT_PRIORITY .
                ";",
        );

        // ---- normalize() ----------------------------------------------------
        $buf->blank();
        $this->writeNormalizeMethod($buf, $metadata, $needsCircularRef);

        // ---- supportsNormalization() ----------------------------------------
        $buf->blank();
        $this->writeSupportsNormalizationMethod($buf, $targetFqcn);

        // ---- getSupportedTypes() --------------------------------------------
        $buf->blank();
        $this->writeGetSupportedTypesMethod($buf, $targetFqcn);

        $buf->outdent();
        $buf->line("}");

        return (string) $buf;
    }

    /**
     * Return the FQCN of the normalizer class that will be generated for the
     * given metadata (namespace + class name, separated by backslash).
     */
    public function resolveNormalizerFqcn(ClassMetadata $metadata): string
    {
        return $this->resolveNormalizerNamespace($metadata) .
            "\\" .
            $this->resolveNormalizerClassName($metadata);
    }

    /**
     * Return the absolute filesystem path where the generated normalizer source
     * file will be written.
     */
    public function resolveFilePath(ClassMetadata $metadata): string
    {
        return rtrim($this->cacheDir, \DIRECTORY_SEPARATOR) .
            \DIRECTORY_SEPARATOR .
            $this->buildPsr4RelativePath($metadata);
    }

    // -------------------------------------------------------------------------
    // Namespace / class-name resolution
    // -------------------------------------------------------------------------

    private function resolveNormalizerNamespace(ClassMetadata $metadata): string
    {
        $classNs = $metadata->getNamespace();
        $base = rtrim($this->generatedNamespace, "\\");

        return $classNs !== "" ? $base . "\\" . $classNs : $base;
    }

    private function resolveNormalizerClassName(ClassMetadata $metadata): string
    {
        return $metadata->getShortName() . "Normalizer";
    }

    /**
     * Build the relative file path under cacheDir following PSR-4 conventions.
     * The class namespace segment is mirrored as a sub-directory structure.
     *
     * Example:
     *   class = App\Entity\User
     *   → App/Entity/UserNormalizer.php
     */
    private function buildPsr4RelativePath(ClassMetadata $metadata): string
    {
        $classNs = $metadata->getNamespace();
        $fileName = $metadata->getShortName() . "Normalizer.php";

        if ($classNs === "") {
            return $fileName;
        }

        return str_replace("\\", \DIRECTORY_SEPARATOR, $classNs) .
            \DIRECTORY_SEPARATOR .
            $fileName;
    }

    // -------------------------------------------------------------------------
    // Use-statement assembly
    // -------------------------------------------------------------------------

    /**
     * Build the sorted list of use-statement FQCNs for the generated file,
     * based on the target class and active features.
     *
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
        $set[
            "Buildable\\SerializerBundle\\Normalizer\\GeneratedNormalizerInterface"
        ] = true;
        $set[
            "Symfony\\Component\\Serializer\\Normalizer\\NormalizerInterface"
        ] = true;

        if ($needsAware) {
            $set[
                "Symfony\\Component\\Serializer\\Normalizer\\NormalizerAwareInterface"
            ] = true;
            $set[
                "Symfony\\Component\\Serializer\\Normalizer\\NormalizerAwareTrait"
            ] = true;
        }

        if ($needsAbstractNorm) {
            $set[
                "Symfony\\Component\\Serializer\\Normalizer\\AbstractNormalizer"
            ] = true;
        }

        if ($needsAbstractObj) {
            $set[
                "Symfony\\Component\\Serializer\\Normalizer\\AbstractObjectNormalizer"
            ] = true;
        }

        if ($needsNameConv) {
            $set[
                "Symfony\\Component\\Serializer\\NameConverter\\NameConverterInterface"
            ] = true;
        }

        if ($needsCircularRef) {
            $set[
                "Symfony\\Component\\Serializer\\Exception\\CircularReferenceException"
            ] = true;
        }

        $uses = array_keys($set);
        sort($uses);

        return $uses;
    }

    // -------------------------------------------------------------------------
    // Feature-dependency queries
    // -------------------------------------------------------------------------

    /**
     * Whether the generated class needs the NormalizerAware interface/trait for
     * recursive delegation (nested objects or typed collections).
     */
    private function needsNormalizerAware(ClassMetadata $metadata): bool
    {
        return $metadata->hasNestedObjects() || $metadata->hasCollections();
    }

    /**
     * Whether any AbstractNormalizer constant references are needed in the
     * generated normalize() method body.
     */
    private function needsAbstractNormalizerConstants(
        ClassMetadata $metadata,
    ): bool {
        return ($this->features["groups"] &&
            $metadata->hasGroupConstraints()) ||
            ($this->features["circular_reference"] &&
                $metadata->hasNestedObjects()) ||
            $this->features["name_converter"];
    }

    /**
     * Whether any AbstractObjectNormalizer constant references are needed.
     */
    private function needsAbstractObjectNormalizerConstants(
        ClassMetadata $metadata,
    ): bool {
        return ($this->features["max_depth"] &&
            $metadata->hasMaxDepthConstraints()) ||
            $this->features["skip_null_values"];
    }

    // -------------------------------------------------------------------------
    // Method writers
    // -------------------------------------------------------------------------

    /**
     * Write the full normalize() method into the buffer.
     */
    private function writeNormalizeMethod(
        CodeBuffer $buf,
        ClassMetadata $metadata,
        bool $needsCircularRef,
    ): void {
        $activeFeatures = $this->resolveActiveFeatures($metadata);

        $hasGroups = $activeFeatures["groups"];
        $hasSkipNull = $activeFeatures["skip_null_values"];
        $hasNameConv = $activeFeatures["name_converter"];
        $hasMaxDepth = $activeFeatures["max_depth"];

        // PHPDoc
        $buf->line("/**");
        $buf->line(" * @param \\" . $metadata->className . ' $object');
        $buf->line(' * @param array<string, mixed>      $context');
        $buf->line(" *");
        $buf->line(" * @return array<string, mixed>");
        $buf->line(" */");

        $buf->line(
            'public function normalize(mixed $object, ?string $format = null, array $context = []): ' .
                "array|string|int|float|bool|\\ArrayObject|null",
        );
        $buf->line("{");
        $buf->indent();

        // -- Circular-reference guard ----------------------------------------
        if ($needsCircularRef) {
            $this->writeCircularReferenceGuard($buf, $metadata->className);
            $buf->blank();
        }

        // -- Context helpers -------------------------------------------------
        if ($hasGroups) {
            $buf->line('/** @var list<string> $groups */');
            $buf->line(
                '$groups = (array) ($context[AbstractNormalizer::GROUPS] ?? []);',
            );
        }

        if ($hasSkipNull) {
            $buf->line(
                '$skipNullValues = (bool) ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false);',
            );
        }

        if ($hasNameConv) {
            $buf->line(
                '$nameConverter = $context[AbstractNormalizer::NAME_CONVERTER] ?? null;',
            );
        }

        if ($hasGroups || $hasSkipNull || $hasNameConv) {
            $buf->blank();
        }

        $buf->line('$data = [];');
        $buf->blank();

        // -- Properties ------------------------------------------------------
        $visibleProperties = array_filter(
            $metadata->properties,
            static fn(PropertyMetadata $p): bool => !$p->ignored,
        );

        if ($visibleProperties === []) {
            $buf->line('return $data;');
            $buf->outdent();
            $buf->line("}");
            return;
        }

        foreach ($visibleProperties as $property) {
            $this->writePropertyBlock(
                $buf,
                $property,
                $metadata->className,
                $hasGroups,
                $hasSkipNull,
                $hasNameConv,
                $hasMaxDepth,
            );
        }

        $buf->line('return $data;');
        $buf->outdent();
        $buf->line("}");
    }

    /**
     * Write the circular-reference detection guard block.
     *
     * The guard:
     *   1. Initialises the counters context key if missing
     *   2. Increments the counter for the current object hash
     *   3. When the counter reaches the configured limit:
     *      a. Invokes the circular_reference_handler if set
     *      b. Otherwise throws {@see CircularReferenceException}
     */
    private function writeCircularReferenceGuard(
        CodeBuffer $buf,
        string $targetFqcn,
    ): void {
        // Note: AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT_COUNTERS is a protected constant
        // ('circular_reference_limit_counters'), so we use the string value directly in generated code.
        $buf->line('$objectHash = spl_object_hash($object);');
        $buf->line('$context[\'circular_reference_limit_counters\'] ??= [];');
        $buf->blank();
        $buf->line(
            'if (isset($context[\'circular_reference_limit_counters\'][$objectHash])) {',
        );
        $buf->indent();
        $buf->line(
            '$limit = (int) ($context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT] ?? 1);',
        );
        $buf->blank();
        $buf->line(
            'if ($context[\'circular_reference_limit_counters\'][$objectHash] >= $limit) {',
        );
        $buf->indent();
        $buf->line(
            'if (isset($context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER])) {',
        );
        $buf->indent();
        $buf->line(
            'return ($context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER])($object, $format, $context);',
        );
        $buf->outdent();
        $buf->line("}");
        $buf->blank();
        $buf->line("throw new CircularReferenceException(sprintf(");
        $buf->indent();
        $buf->line(
            '\'A circular reference has been detected when serializing the object of class "%s" ' .
                '(configured limit: %d).\',',
        );
        $buf->line('\'' . addslashes($targetFqcn) . '\',');
        $buf->line('$limit,');
        $buf->outdent();
        $buf->line("));");
        $buf->outdent();
        $buf->line("}");
        $buf->blank();
        $buf->line(
            '++$context[\'circular_reference_limit_counters\'][$objectHash];',
        );
        $buf->outdent();
        $buf->line("} else {");
        $buf->indent();
        $buf->line(
            '$context[\'circular_reference_limit_counters\'][$objectHash] = 1;',
        );
        $buf->outdent();
        $buf->line("}");
    }

    /**
     * Write all normalization logic for a single property, wrapping it in
     * group-filtering and max-depth blocks as required.
     */
    private function writePropertyBlock(
        CodeBuffer $buf,
        PropertyMetadata $property,
        string $ownerClass,
        bool $hasGroups,
        bool $hasSkipNull,
        bool $hasNameConv,
        bool $hasMaxDepth,
    ): void {
        // -- Groups wrapper --------------------------------------------------
        $needsGroupBlock = $hasGroups && $property->groups !== [];

        if ($needsGroupBlock) {
            $groupsLiteral = $this->buildStringArrayLiteral($property->groups);
            $buf->line(
                'if ($groups === [] || array_intersect(' .
                    $groupsLiteral .
                    ', $groups) !== []) {',
            );
            $buf->indent();
        }

        // -- Key expression --------------------------------------------------
        $rawKey = $property->serializedName ?? $property->name;
        $keyExpr = $hasNameConv
            ? $this->buildNameConverterKeyExpr($rawKey, $ownerClass)
            : $this->q($rawKey);

        if ($hasNameConv) {
            $buf->line('$_key = ' . $keyExpr . ";");
            $keyExpr = '$_key';
        }

        // -- Value expression ------------------------------------------------
        $accessorType = $this->resolveAccessorType($property);
        $rawValueExpr = $accessorType->toExpression($property->accessor);

        // -- Max-depth wrapper -----------------------------------------------
        $needsMaxDepth =
            $hasMaxDepth &&
            $property->maxDepth !== null &&
            ($property->isNested || $property->isCollection);

        if ($needsMaxDepth) {
            $ownerClassLiteral = $this->q($ownerClass);
            $propertyNameLiteral = $this->q($property->name);
            $buf->line(
                '$_depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, ' .
                    $ownerClassLiteral .
                    ", " .
                    $propertyNameLiteral .
                    ");",
            );
            $buf->line('$_currentDepth = (int) ($context[$_depthKey] ?? 0);');
            $buf->blank();
            $buf->line(
                'if ($_currentDepth < ' . (int) $property->maxDepth . ") {",
            );
            $buf->indent();
            $buf->line('$context[$_depthKey] = $_currentDepth + 1;');
            $buf->blank();
        }

        // -- Core value assignment -------------------------------------------
        if ($property->isNested) {
            $this->writeNestedValue(
                $buf,
                $property,
                $rawValueExpr,
                $keyExpr,
                $hasSkipNull,
            );
        } elseif ($property->isCollection) {
            $this->writeCollectionValue(
                $buf,
                $property,
                $rawValueExpr,
                $keyExpr,
                $hasSkipNull,
            );
        } else {
            $this->writeScalarValue(
                $buf,
                $property,
                $rawValueExpr,
                $keyExpr,
                $hasSkipNull,
            );
        }

        // -- Close max-depth block -------------------------------------------
        if ($needsMaxDepth) {
            $buf->outdent();
            $buf->line(
                "} // max-depth: " .
                    $property->name .
                    " (limit=" .
                    $property->maxDepth .
                    ")",
            );
        }

        // -- Close groups block ----------------------------------------------
        if ($needsGroupBlock) {
            $buf->outdent();
            $buf->line("} // groups: " . implode(", ", $property->groups));
        }

        $buf->blank();
    }

    /**
     * Write the value-assignment block for a nested object property.
     *
     * Delegates normalization to $this->normalizer (NormalizerAwareTrait).
     */
    private function writeNestedValue(
        CodeBuffer $buf,
        PropertyMetadata $property,
        string $rawValueExpr,
        string $keyExpr,
        bool $hasSkipNull,
    ): void {
        $needsNullCheck = $property->nullable || $hasSkipNull;

        if (!$needsNullCheck) {
            // Non-nullable, no skip-null logic needed — simplest possible path
            $buf->line(
                '$data[' .
                    $keyExpr .
                    '] = $this->normalizer->normalize(' .
                    $rawValueExpr .
                    ', $format, $context);',
            );
            return;
        }

        $buf->line('$_val = ' . $rawValueExpr . ";");
        $buf->blank();

        if ($property->nullable && $hasSkipNull) {
            $buf->line('if ($_val !== null) {');
            $buf->indent();
            $buf->line(
                '$data[' .
                    $keyExpr .
                    '] = $this->normalizer->normalize($_val, $format, $context);',
            );
            $buf->outdent();
            $buf->line('} elseif (!$skipNullValues) {');
            $buf->indent();
            $buf->line('$data[' . $keyExpr . "] = null;");
            $buf->outdent();
            $buf->line("}");
        } elseif ($property->nullable) {
            $buf->line('if ($_val !== null) {');
            $buf->indent();
            $buf->line(
                '$data[' .
                    $keyExpr .
                    '] = $this->normalizer->normalize($_val, $format, $context);',
            );
            $buf->outdent();
            $buf->line("} else {");
            $buf->indent();
            $buf->line('$data[' . $keyExpr . "] = null;");
            $buf->outdent();
            $buf->line("}");
        } else {
            // Not nullable, but skip_null_values is active
            $buf->line('if ($_val !== null || !$skipNullValues) {');
            $buf->indent();
            $buf->line(
                '$data[' .
                    $keyExpr .
                    '] = $_val !== null' .
                    ' ? $this->normalizer->normalize($_val, $format, $context)' .
                    " : null;",
            );
            $buf->outdent();
            $buf->line("}");
        }
    }

    /**
     * Write the value-assignment block for a collection property.
     *
     * The entire collection is delegated to $this->normalizer->normalize(),
     * which lets Symfony's Serializer handle each element recursively.
     * This works for both typed (array<SomeClass>) and untyped/scalar arrays.
     */
    private function writeCollectionValue(
        CodeBuffer $buf,
        PropertyMetadata $property,
        string $rawValueExpr,
        string $keyExpr,
        bool $hasSkipNull,
    ): void {
        $needsNullCheck = $property->nullable || $hasSkipNull;

        if ($needsNullCheck) {
            $buf->line('$_collection = ' . $rawValueExpr . ";");
            $buf->blank();
            $buf->line('if ($_collection !== null) {');
            $buf->indent();
            $collectionRef = '$_collection';
        } else {
            $collectionRef = $rawValueExpr;
        }

        // Delegate the entire collection to the normalizer.
        // Symfony's Serializer handles arrays by recursively normalizing each element.
        $buf->line(
            '$data[' .
                $keyExpr .
                '] = $this->normalizer->normalize(' .
                $collectionRef .
                ', $format, $context);',
        );

        if ($needsNullCheck) {
            $buf->outdent();

            if ($hasSkipNull) {
                $buf->line('} elseif (!$skipNullValues) {');
                $buf->indent();
                $buf->line('$data[' . $keyExpr . "] = null;");
                $buf->outdent();
            } else {
                // nullable but no skip-null: still write null
                $buf->line("} else {");
                $buf->indent();
                $buf->line('$data[' . $keyExpr . "] = null;");
                $buf->outdent();
            }

            $buf->line("}");
        }
    }

    /**
     * Write the value-assignment block for a plain scalar property.
     */
    private function writeScalarValue(
        CodeBuffer $buf,
        PropertyMetadata $property,
        string $rawValueExpr,
        string $keyExpr,
        bool $hasSkipNull,
    ): void {
        if (!$hasSkipNull) {
            $buf->line('$data[' . $keyExpr . "] = " . $rawValueExpr . ";");
            return;
        }

        $buf->line('$_val = ' . $rawValueExpr . ";");
        $buf->blank();
        $buf->line('if ($_val !== null || !$skipNullValues) {');
        $buf->indent();
        $buf->line('$data[' . $keyExpr . '] = $_val;');
        $buf->outdent();
        $buf->line("}");
    }

    /**
     * Write the supportsNormalization() method.
     */
    private function writeSupportsNormalizationMethod(
        CodeBuffer $buf,
        string $targetFqcn,
    ): void {
        $shortName = $this->shortName($targetFqcn);

        $buf->line("/**");
        $buf->line(' * @param array<string, mixed> $context');
        $buf->line(" */");
        $buf->line(
            'public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool',
        );
        $buf->line("{");
        $buf->indent();
        $buf->line('return $data instanceof ' . $shortName . ";");
        $buf->outdent();
        $buf->line("}");
    }

    /**
     * Write the getSupportedTypes() method (required by Symfony 6.3+ for
     * caching normalizer lookup, replacing the deprecated `hasCacheableSupportsMethod`).
     */
    private function writeGetSupportedTypesMethod(
        CodeBuffer $buf,
        string $targetFqcn,
    ): void {
        $shortName = $this->shortName($targetFqcn);

        $buf->line("/**");
        $buf->line(
            ' * @return array<class-string|\'*\'|\'object\'|string, bool|null>',
        );
        $buf->line(" */");
        $buf->line('public function getSupportedTypes(?string $format): array');
        $buf->line("{");
        $buf->indent();
        $buf->line("return [" . $shortName . "::class => true];");
        $buf->outdent();
        $buf->line("}");
    }

    // -------------------------------------------------------------------------
    // Active feature resolution
    // -------------------------------------------------------------------------

    /**
     * Compute which features are actually active for the given class, taking
     * into account both the global feature flags AND whether the class's
     * metadata actually needs the feature (e.g. groups flag is irrelevant when
     * no property has group constraints).
     *
     * @return array{groups: bool, max_depth: bool, circular_reference: bool, name_converter: bool, skip_null_values: bool}
     */
    private function resolveActiveFeatures(ClassMetadata $metadata): array
    {
        return [
            "groups" =>
                $this->features["groups"] && $metadata->hasGroupConstraints(),
            "max_depth" =>
                $this->features["max_depth"] &&
                $metadata->hasMaxDepthConstraints(),
            "circular_reference" =>
                $this->features["circular_reference"] &&
                $metadata->hasNestedObjects(),
            "name_converter" => $this->features["name_converter"],
            "skip_null_values" => $this->features["skip_null_values"],
        ];
    }

    // -------------------------------------------------------------------------
    // Code-generation helpers
    // -------------------------------------------------------------------------

    /**
     * Build the PHP expression that resolves the serialized key, respecting
     * the context's name converter when one is active.
     *
     * The expression is designed to be used as the right-hand side of a local
     * variable assignment (i.e. `$_key = <expr>;`).
     *
     * Example output:
     *   `$nameConverter instanceof NameConverterInterface
     *       ? $nameConverter->normalize('firstName', 'App\Entity\User', $format, $context)
     *       : 'firstName'`
     */
    private function buildNameConverterKeyExpr(
        string $rawKey,
        string $ownerClass,
    ): string {
        return sprintf(
            '$nameConverter instanceof NameConverterInterface' .
                "\n        ? \$nameConverter->normalize(%s, %s, \$format, \$context)" .
                "\n        : %s",
            $this->q($rawKey),
            $this->q($ownerClass),
            $this->q($rawKey),
        );
    }

    /**
     * Resolve the AccessorType enum instance from a PropertyMetadata, handling
     * both the case where it is already an AccessorType enum and where it is
     * stored as its string backing value.
     */
    private function resolveAccessorType(
        PropertyMetadata $property,
    ): AccessorType {
        $raw = $property->accessorType;

        if ($raw instanceof AccessorType) {
            return $raw;
        }

        return AccessorType::tryFrom((string) $raw) ?? AccessorType::METHOD;
    }

    /**
     * Return the short (unqualified) class name from a fully-qualified name.
     *
     * Safe to use inside generated code because the FQCN will appear in the
     * file's use-statement list.
     *
     * Example: `App\Entity\User` → `User`
     */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, "\\");

        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }

    /**
     * Wrap a string in single quotes, escaping backslashes and single-quotes.
     *
     * For use in generated PHP source, not for runtime use.
     */
    private function q(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    /**
     * Build a PHP short-array literal from a list of string values.
     *
     * Example: `['group1', 'group2']`
     *
     * @param string[] $items
     */
    private function buildStringArrayLiteral(array $items): string
    {
        $quoted = array_map(fn(string $s): string => $this->q($s), $items);

        return "[" . implode(", ", $quoted) . "]";
    }
}
