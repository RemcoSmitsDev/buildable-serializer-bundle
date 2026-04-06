<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\DependencyInjection\Compiler;

use Buildable\SerializerBundle\Normalizer\GeneratedNormalizerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers generated normalizer classes as tagged Symfony Serializer services
 * AND directly injects them into the serializer service's constructor argument.
 *
 * ### Why direct injection?
 *
 * Symfony resolves the `tagged_iterator` for `serializer.normalizer` during the
 * TYPE_OPTIMIZE compilation phase and inlines all collected services directly
 * into the Serializer constructor.  Any service that was not yet referenced by
 * the time `RemoveUnusedDefinitionsPass` (TYPE_REMOVE) runs will be pruned as
 * "unused", even if it carries the correct tag.
 *
 * To guarantee our generated normalizers survive compilation and appear first
 * in the normalizer chain (before ObjectNormalizer), this pass:
 *
 *  1. Creates a DI Definition for every generated normalizer file found in the
 *     configured cache directory.
 *  2. Prepends a Reference to each generated normalizer directly into the
 *     Symfony Serializer service's first constructor argument (the normalizers
 *     array), so the compiler sees an explicit reference and never removes the
 *     service.
 *  3. Still adds the `serializer.normalizer` tag so the normalizer appears in
 *     `debug:container --tag=serializer.normalizer` output.
 *
 * ### Priority
 *
 * Generated normalizers are prepended in front of all existing normalizers.
 * Within the generated set, classes with a higher `NORMALIZER_PRIORITY` constant
 * are placed closer to the front.  The default priority is 200, which is well
 * above Symfony's ObjectNormalizer (-1000).
 */
final class RegisterGeneratedNormalizersPass implements CompilerPassInterface
{
    private const CACHE_DIR_PARAM = "buildable_serializer.cache_dir";
    private const NAMESPACE_PARAM = "buildable_serializer.generated_namespace";
    private const NORMALIZER_TAG = "serializer.normalizer";
    private const SERIALIZER_SERVICE = "serializer";
    private const DEFAULT_PRIORITY = 200;

    // -------------------------------------------------------------------------
    // CompilerPassInterface
    // -------------------------------------------------------------------------

    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->hasParameter(self::CACHE_DIR_PARAM) ||
            !$container->hasParameter(self::NAMESPACE_PARAM)
        ) {
            return;
        }

        /** @var string $cacheDir */
        $cacheDir = (string) $container
            ->getParameterBag()
            ->resolveValue($container->getParameter(self::CACHE_DIR_PARAM));

        $resolved = realpath($cacheDir);
        if ($resolved !== false) {
            $cacheDir = $resolved;
        }

        /** @var string $generatedNamespace */
        $generatedNamespace = (string) $container->getParameter(
            self::NAMESPACE_PARAM,
        );

        if (!is_dir($cacheDir)) {
            return;
        }

        $files = $this->scanNormalizerFiles($cacheDir);
        if ($files === []) {
            return;
        }

        // ---- 1. Register every generated normalizer as a DI service ----------
        /** @var array<string, int> $registered  fqcn => priority */
        $registered = [];

        foreach ($files as $filePath) {
            $fqcn = $this->filePathToFqcn(
                $cacheDir,
                $filePath,
                $generatedNamespace,
            );
            if ($fqcn === null) {
                continue;
            }

            if (!$this->loadClass($fqcn, $filePath)) {
                continue;
            }

            if (!is_a($fqcn, GeneratedNormalizerInterface::class, true)) {
                continue;
            }

            if (
                $container->hasDefinition($fqcn) ||
                $container->hasAlias($fqcn)
            ) {
                $registered[$fqcn] = $this->resolvePriority($fqcn);
                continue;
            }

            $priority = $this->resolvePriority($fqcn);
            $arguments = $this->resolveConstructorArguments($fqcn, $container);

            $definition = new Definition($fqcn, $arguments);
            $definition->setPublic(false);
            $definition->setAutowired(false);
            $definition->setAutoconfigured(false);
            $definition->addTag(self::NORMALIZER_TAG, [
                "priority" => $priority,
            ]);

            $container->setDefinition($fqcn, $definition);

            $registered[$fqcn] = $priority;
        }

        if ($registered === []) {
            return;
        }

        // ---- 2. Sort by priority descending (highest = first in chain) -------
        arsort($registered);

        // ---- 3. Inject References directly into the serializer definition ----
        $this->injectIntoSerializer($container, array_keys($registered));
    }

    // -------------------------------------------------------------------------
    // Direct serializer injection
    // -------------------------------------------------------------------------

    /**
     * Prepend a Reference for each generated normalizer into the Symfony
     * Serializer service's first constructor argument (the normalizers list).
     *
     * ### Why direct injection instead of relying on the tag alone?
     *
     * Symfony 6.4 resolves the `serializer.normalizer` tagged_iterator lazily
     * (via a RewindableGenerator closure). Because no hard Reference objects are
     * created at compile time, RemoveUnusedDefinitionsPass (TYPE_REMOVE) cannot
     * see that our services are needed and prunes them as "unused".
     *
     * By prepending explicit Reference objects to the Serializer's first
     * constructor argument we create the hard references the pruning pass
     * follows, while still keeping the serializer.normalizer tag for
     * `debug:container` visibility.
     *
     * ### Complete-snapshot guarantee
     *
     * This method is only called from process(), which is registered at
     * TYPE_BEFORE_OPTIMIZATION priority -1000 — the very last pass in that
     * phase. By that point every bundle extension (which all run before any
     * compiler pass) and every other TYPE_BEFORE_OPTIMIZATION pass has already
     * registered its services. findTaggedServiceIds() therefore returns a
     * complete, stable list of normalizers; no later pass can add more services
     * to the TYPE_BEFORE_OPTIMIZATION phase.
     *
     * @param string[] $fqcns Ordered list of our generated normalizer FQCNs
     *                        (highest priority first).
     */
    private function injectIntoSerializer(
        ContainerBuilder $container,
        array $fqcns,
    ): void {
        if (!$container->hasDefinition(self::SERIALIZER_SERVICE)) {
            return;
        }

        $serializerDef = $container->getDefinition(self::SERIALIZER_SERVICE);

        try {
            $existing = $serializerDef->getArgument(0);
        } catch (\OutOfBoundsException) {
            // Serializer has no arguments yet — skip.
            return;
        }

        // Build References for our generated normalizers (highest priority first).
        /** @var list<Reference> $ourRefs */
        $ourRefs = [];
        foreach ($fqcns as $fqcn) {
            $ourRefs[] = new Reference($fqcn);
        }

        // Build a set of our own FQCNs for O(1) deduplication below.
        $ownFqcns = array_flip($fqcns);

        // Merge with existing normalizer argument.
        // The existing argument may be:
        //   - an array of References (already resolved by a prior pass)
        //   - a TaggedIteratorArgument / IteratorArgument (not yet resolved)
        //   - something else (e.g. a ServiceLocatorArgument)
        if (is_array($existing)) {
            // Already a plain array of References — prepend ours.
            $serializerDef->replaceArgument(
                0,
                array_merge($ourRefs, $existing),
            );
        } else {
            // Dynamic argument (TaggedIteratorArgument or similar): replace it
            // with a flat array that includes our References first, followed by
            // every other serializer.normalizer tagged service sorted by
            // priority. Because this pass runs last in TYPE_BEFORE_OPTIMIZATION
            // (priority -1000), findTaggedServiceIds() returns the complete,
            // final set of normalizers — no later pass can add more.
            $taggedIds = $container->findTaggedServiceIds(self::NORMALIZER_TAG);

            // Collect and sort non-generated normalizers by priority (desc).
            $taggedWithPriority = [];
            foreach ($taggedIds as $id => $tags) {
                // Skip our own services — they are already in $ourRefs.
                if (isset($ownFqcns[$id])) {
                    continue;
                }
                $taggedWithPriority[$id] = (int) ($tags[0]["priority"] ?? 0);
            }
            arsort($taggedWithPriority);

            $allRefs = $ourRefs;
            foreach (array_keys($taggedWithPriority) as $id) {
                $allRefs[] = new Reference($id);
            }

            $serializerDef->replaceArgument(0, $allRefs);
        }
    }

    // -------------------------------------------------------------------------
    // File scanning
    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function scanNormalizerFiles(string $cacheDir): array
    {
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $cacheDir,
                    \FilesystemIterator::SKIP_DOTS |
                        \FilesystemIterator::UNIX_PATHS,
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (\UnexpectedValueException) {
            return [];
        }

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            if ($fileInfo->getExtension() !== "php") {
                continue;
            }
            if (!str_ends_with($fileInfo->getFilename(), "Normalizer.php")) {
                continue;
            }
            $realPath = $fileInfo->getRealPath();
            if ($realPath !== false) {
                $files[] = $realPath;
            }
        }

        sort($files);

        return $files;
    }

    // -------------------------------------------------------------------------
    // Path ↔ FQCN conversion
    // -------------------------------------------------------------------------

    private function filePathToFqcn(
        string $cacheDir,
        string $filePath,
        string $generatedNamespace,
    ): ?string {
        $cacheDir = rtrim(str_replace("\\", "/", $cacheDir), "/");
        $filePath = str_replace("\\", "/", $filePath);
        $prefix = $cacheDir . "/";

        if (!str_starts_with($filePath, $prefix)) {
            return null;
        }

        $relative = substr($filePath, \strlen($prefix));
        $relative = substr($relative, 0, -\strlen(".php"));
        $relative = str_replace("/", "\\", $relative);

        $ns = rtrim($generatedNamespace, "\\");

        return $ns === "" ? $relative : $ns . "\\" . $relative;
    }

    // -------------------------------------------------------------------------
    // Class loading
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Priority resolution
    // -------------------------------------------------------------------------

    private function resolvePriority(string $fqcn): int
    {
        try {
            $ref = new \ReflectionClass($fqcn);
            if ($ref->hasConstant("NORMALIZER_PRIORITY")) {
                $val = $ref->getConstant("NORMALIZER_PRIORITY");
                if (\is_int($val)) {
                    return $val;
                }
            }
        } catch (\ReflectionException) {
        }

        return self::DEFAULT_PRIORITY;
    }

    // -------------------------------------------------------------------------
    // Constructor argument resolution
    // -------------------------------------------------------------------------

    /** @return list<Reference|null> */
    private function resolveConstructorArguments(
        string $fqcn,
        ContainerBuilder $container,
    ): array {
        try {
            $ref = new \ReflectionClass($fqcn);
            $constructor = $ref->getConstructor();
        } catch (\ReflectionException) {
            return [];
        }

        if (
            $constructor === null ||
            $constructor->getNumberOfParameters() === 0
        ) {
            return [];
        }

        $serializerInterfaces = [
            "Symfony\Component\Serializer\Normalizer\NormalizerInterface",
            "Symfony\Component\Serializer\Normalizer\DenormalizerInterface",
            "Symfony\Component\Serializer\SerializerInterface",
        ];

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                $args[] = null;
                continue;
            }

            $typeName = $type->getName();

            if (\in_array($typeName, $serializerInterfaces, true)) {
                $args[] = new Reference("serializer");
                continue;
            }

            if (
                $container->hasDefinition($typeName) ||
                $container->hasAlias($typeName)
            ) {
                $args[] = new Reference($typeName);
                continue;
            }

            $args[] = null;
        }

        return $args;
    }
}
