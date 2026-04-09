<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Compiler;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator;
use RemcoSmitsDev\BuildableSerializerBundle\Normalizer\GeneratedNormalizerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Generates optimised normalizer classes at container compile time and wires
 * them directly into the Symfony Serializer service.
 *
 * ### Why generate during compilation?
 *
 * The previous approach relied on pre-existing generated files in `cache_dir`.
 * This created a chicken-and-egg problem in production: the files only existed
 * after a separate `buildable:generate-normalizers` command, yet the container
 * needed them to exist *before* it was compiled by `cache:clear`.
 *
 * Generating during the compiler pass eliminates that dependency entirely:
 *
 *   - **Production**: a single `cache:clear` (or `cache:warmup`) generates the
 *     normalizer source files, registers them as services, and compiles the
 *     container — all in one step.
 *   - **Development**: the container is rebuilt whenever tracked resources
 *     change; the pass re-generates the normalizers at that point.
 *   - **No external step required**: `buildable:generate-normalizers` and the
 *     `NormalizerCacheWarmer` remain available as convenience tools but are no
 *     longer prerequisites for a working application.
 *
 * ### How it works
 *
 *  1. Reads `buildable_serializer.paths`, `cache_dir`, `generated_namespace`,
 *     `features`, and `generation` from the container parameters.
 *  2. Instantiates `FinderClassDiscovery`, `MetadataFactory`, and
 *     `NormalizerGenerator` directly (without the DI container — all these
 *     objects are pure PHP with no circular dependencies).
 *  3. Discovers every concrete class under the configured PSR-4 paths (see
 *     {@see FinderClassDiscovery}).
 *  4. Generates one PHP source file per class into `cache_dir` and creates a
 *     classmap `autoload.php` for runtime bootstrapping.
 *  5. Registers each generated normalizer as a private DI service tagged with
 *     `serializer.normalizer`.
 *  6. Prepends explicit `Reference` objects for the generated normalizers into
 *     the Symfony Serializer's first constructor argument so that
 *     `RemoveUnusedDefinitionsPass` cannot prune them (the `tagged_iterator`
 *     used by the serializer creates only lazy closures, not hard references).
 *
 * ### Priority
 *
 * The pass is registered at `TYPE_BEFORE_OPTIMIZATION` with priority **-1000**,
 * meaning it runs last in that phase. All bundle extensions and higher-priority
 * passes have already registered their services by then, so the call to
 * `findTaggedServiceIds('serializer.normalizer')` captures a complete,
 * race-condition-free snapshot of the normalizer chain.
 *
 * Generated normalizers receive priority **200** by default (well above
 * `ObjectNormalizer` at -1000). A generated class may override this via a
 * public `NORMALIZER_PRIORITY` integer constant.
 */
final class RegisterGeneratedNormalizersPass implements CompilerPassInterface
{
    private const CACHE_DIR_PARAM = 'buildable_serializer.cache_dir';
    private const NAMESPACE_PARAM = 'buildable_serializer.generated_namespace';
    private const PATHS_PARAM = 'buildable_serializer.paths';
    private const FEATURES_PARAM = 'buildable_serializer.features';
    private const GENERATION_PARAM = 'buildable_serializer.generation';
    private const NORMALIZER_TAG = 'serializer.normalizer';
    private const SERIALIZER_SERVICE = 'serializer';

    private const DEFAULT_FEATURES = [
        'groups' => true,
        'max_depth' => true,
        'circular_reference' => true,
        'name_converter' => false,
        'skip_null_values' => true,
    ];

    private const DEFAULT_PRIORITY = 200;

    public function process(ContainerBuilder $container): void
    {
        foreach ([self::CACHE_DIR_PARAM, self::NAMESPACE_PARAM, self::PATHS_PARAM] as $param) {
            if ($container->hasParameter($param) === false) {
                return;
            }
        }

        /** @var NormalizerGenerator $generator */
        $generator = $container->get(NormalizerGenerator::class);

        /** @var ClassDiscoveryInterface $discovery */
        $discovery = $container->get(ClassDiscoveryInterface::class);

        $metadataCollection = $discovery->discoverClasses();

        if ($metadataCollection === []) {
            return;
        }

        /** @var array<string, int> $registered  fqcn => priority */
        $registered = [];

        /** @var array<string, string> $classmap  fqcn => absolute file path */
        $classmap = [];

        foreach ($metadataCollection as $classMetadata) {
            $fqcn = $generator->resolveNormalizerFqcn($classMetadata);
            $filePath = $generator->generateAndWrite($classMetadata);

            if ($this->loadClass($fqcn, $filePath) === false) {
                continue;
            }

            if (is_a($fqcn, GeneratedNormalizerInterface::class, true) === false) {
                continue;
            }

            $priority = $this->resolvePriority($fqcn);

            if (!$container->hasDefinition($fqcn) && !$container->hasAlias($fqcn)) {
                $definition = new Definition($fqcn);
                $definition->setPublic(false);
                $definition->setAutowired(false);
                $definition->setAutoconfigured(false);
                $definition->addTag(self::NORMALIZER_TAG, [
                    'priority' => $priority,
                ]);
                // Tell PhpDumper to emit `include_once $filePath` before
                // instantiating this service in the compiled container.
                // This is required in production mode where the service is
                // inlined directly into the Serializer constructor call and
                // the class file would otherwise never be loaded (it lives
                // outside Composer's autoload paths in var/buildable_serializer/).
                $definition->setFile($filePath);

                $container->setDefinition($fqcn, $definition);
            }

            $registered[$fqcn] = $priority;
            $classmap[$fqcn] = $filePath;
        }

        if ($registered === []) {
            return;
        }

        /** @var string $cacheDir */
        $cacheDir = (string) $container->getParameterBag()->resolveValue(
            $container->getParameter(self::CACHE_DIR_PARAM)
        );

        $this->writeClassmap($cacheDir, $classmap);

        arsort($registered);
        $this->injectIntoSerializer($container, array_keys($registered));
    }

    /**
     * Write a PHP classmap file to `{cacheDir}/autoload.php`.
     *
     * The classmap is consumed by `RegisterGeneratedNormalizersPass` on the
     * next boot (before service instantiation) and by the console command when
     * it needs to report which files were generated.
     *
     * @param array<string, string> $classmap FQCN => absolute file path
     */
    private function writeClassmap(string $cacheDir, array $classmap): void
    {
        $lines = [];
        foreach ($classmap as $fqcn => $filePath) {
            $lines[] = '    ' . var_export($fqcn, true) . ' => ' . var_export($filePath, true) . ',';
        }

        $content =
            "<?php\n\n// @generated by RemcoSmitsDev/buildable-serializer-bundle\n\nreturn [\n" . implode("\n", $lines) . "\n];\n";

        file_put_contents($cacheDir . '/autoload.php', $content);
    }

    /**
     * Prepend a `Reference` for each generated normalizer into the Symfony
     * Serializer service's first constructor argument (the normalizers list).
     *
     * ### Why direct injection instead of relying on the tag alone?
     *
     * Symfony 6.4 resolves the `serializer.normalizer` tagged_iterator lazily
     * via a `RewindableGenerator` closure. Because no hard `Reference` objects
     * are created at compile time, `RemoveUnusedDefinitionsPass` (TYPE_REMOVE)
     * cannot see that our services are needed and prunes them as "unused".
     *
     * Prepending explicit `Reference` objects into the Serializer's first
     * constructor argument creates the hard references the pruning pass follows,
     * while the `serializer.normalizer` tag is retained for
     * `debug:container --tag=serializer.normalizer` visibility.
     *
     * ### Complete-snapshot guarantee
     *
     * This method is called from `process()` which is registered at
     * `TYPE_BEFORE_OPTIMIZATION` priority `-1000` — the very last pass in that
     * phase. Every bundle extension (which all run before any compiler pass)
     * and every higher-priority TYPE_BEFORE_OPTIMIZATION pass has already
     * registered its services by then. `findTaggedServiceIds()` therefore
     * returns the complete, final set of normalizers.
     *
     * @param string[] $fqcns Generated normalizer FQCNs, highest priority first.
     */
    private function injectIntoSerializer(ContainerBuilder $container, array $fqcns): void
    {
        if (!$container->hasDefinition(self::SERIALIZER_SERVICE)) {
            return;
        }

        $serializerDef = $container->getDefinition(self::SERIALIZER_SERVICE);

        try {
            $existing = $serializerDef->getArgument(0);
        } catch (\OutOfBoundsException) {
            return;
        }

        // Build References for our generated normalizers (highest priority first).
        /** @var list<Reference> $ourRefs */
        $ourRefs = [];
        foreach ($fqcns as $fqcn) {
            $ourRefs[] = new Reference($fqcn);
        }

        // O(1) set for deduplication when iterating tagged services below.
        $ownFqcns = array_flip($fqcns);

        if (is_array($existing)) {
            // Argument is already a plain array of References (resolved by a
            // prior pass) — simply prepend ours.
            $serializerDef->replaceArgument(0, array_merge($ourRefs, $existing));

            return;
        }

        // Argument is a TaggedIteratorArgument or similar dynamic type.
        // Replace it with a fully-resolved flat array:
        //   [generated normalizers (priority desc)] + [all other tagged normalizers (priority desc)]
        $taggedIds = $container->findTaggedServiceIds(self::NORMALIZER_TAG);

        $otherWithPriority = [];
        foreach ($taggedIds as $id => $tags) {
            if (isset($ownFqcns[$id])) {
                // Already in $ourRefs — skip to avoid duplication.
                continue;
            }
            $otherWithPriority[$id] = (int) ($tags[0]['priority'] ?? 0);
        }
        arsort($otherWithPriority);

        $allRefs = $ourRefs;
        foreach (array_keys($otherWithPriority) as $id) {
            $allRefs[] = new Reference($id);
        }

        $serializerDef->replaceArgument(0, $allRefs);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Require a generated PHP file and verify the expected class is available.
     */
    private function loadClass(string $fqcn, string $filePath): bool
    {
        if (class_exists($fqcn, false)) {
            return true;
        }

        try {
            /** @psalm-suppress UnresolvableInclude */
            require_once $filePath;
        } catch (\Throwable) {
            return false;
        }

        return class_exists($fqcn, false);
    }

    /**
     * Return the `NORMALIZER_PRIORITY` constant from a generated class, or the
     * default priority when the constant is absent.
     */
    private function resolvePriority(string $fqcn): int
    {
        try {
            $ref = new \ReflectionClass($fqcn);
            if ($ref->hasConstant('NORMALIZER_PRIORITY')) {
                $value = $ref->getConstant('NORMALIZER_PRIORITY');
                if (\is_int($value)) {
                    return $value;
                }
            }
        } catch (\ReflectionException) {
        }

        return self::DEFAULT_PRIORITY;
    }
}
