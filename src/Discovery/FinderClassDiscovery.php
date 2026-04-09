<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Discovery;

use BuildableSerializerBundle\Metadata\ClassMetadata;
use BuildableSerializerBundle\Metadata\MetadataFactoryInterface;
use Symfony\Component\Finder\Finder;

/**
 * Discovers concrete PHP classes by scanning configured PSR-4 directories with
 * symfony/finder and loading each matching file to reflect the class.
 *
 * Configuration supports two modes:
 *
 * 1. Directory mode — scans all PHP files recursively:
 *
 *        paths:
 *            'App\Model': '%kernel.project_dir%/src/Model'
 *
 * 2. Glob mode — scans only files matching the pattern:
 *
 *        paths:
 *            'App\Command': '%kernel.project_dir%/src/Command/*.php'
 *            'App\Handler': '%kernel.project_dir%/src/Handler/*Handler.php'
 *
 * For each configured path entry the namespace prefix is used together with the
 * file's relative location under the directory to compute the FQCN without
 * reading the file content.
 */
final class FinderClassDiscovery implements ClassDiscoveryInterface
{
    /**
     * @param MetadataFactoryInterface $metadataFactory Factory used to build fully-populated ClassMetadata.
     * @param array<string, string>    $paths           Namespace-prefix => absolute directory path or glob pattern.
     */
    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory,
        private readonly array $paths,
    ) {}

    /** @return list<ClassMetadata<object>> */
    public function discoverClasses(): array
    {
        $metadataCollection = [];

        foreach ($this->paths as $namespacePrefix => $pathOrPattern) {
            if ($this->isGlobPattern($pathOrPattern)) {
                $this->discoverFromGlob($namespacePrefix, $pathOrPattern, $metadataCollection);
            } else {
                $this->discoverFromDirectory($namespacePrefix, $pathOrPattern, $metadataCollection);
            }
        }

        usort(
            $metadataCollection,
            static fn(ClassMetadata $a, ClassMetadata $b): int => $a->getClassName() <=> $b->getClassName(),
        );

        return $metadataCollection;
    }

    /**
     * Discover classes from a plain directory path (scans all PHP files recursively).
     *
     * @param list<ClassMetadata<object>> $metadataCollection
     */
    private function discoverFromDirectory(string $namespacePrefix, string $directory, array &$metadataCollection): void
    {
        $realDir = realpath($directory);

        if ($realDir === false || is_dir($realDir) === false) {
            throw new \InvalidArgumentException(sprintf(
                'The directory "%s" configured for namespace prefix "%s" does not exist or is not a directory.',
                $directory,
                $namespacePrefix,
            ));
        }

        $finder = Finder::create()->files()->in($realDir)->name('*.php');

        foreach ($finder as $file) {
            $this->processFile($file->getRealPath(), $realDir, $namespacePrefix, $metadataCollection);
        }
    }

    /**
     * Discover classes from a glob pattern.
     *
     * @param list<ClassMetadata<object>> $metadataCollection
     */
    private function discoverFromGlob(string $namespacePrefix, string $pattern, array &$metadataCollection): void
    {
        [$baseDir, $namePattern] = $this->parseGlobPattern($pattern);

        $realDir = realpath($baseDir);

        if ($realDir === false || is_dir($realDir) === false) {
            throw new \InvalidArgumentException(sprintf(
                'The base directory "%s" (from pattern "%s") configured for namespace prefix "%s" does not exist or is not a directory.',
                $baseDir,
                $pattern,
                $namespacePrefix,
            ));
        }

        $finder = Finder::create()->files()->in($realDir)->name($namePattern);

        foreach ($finder as $file) {
            $this->processFile($file->getRealPath(), $realDir, $namespacePrefix, $metadataCollection);
        }
    }

    /**
     * Process a single PHP file: require it if needed, reflect the class, and add metadata.
     *
     * @param list<ClassMetadata<object>> $metadataCollection
     */
    private function processFile(
        string|false $filePath,
        string $baseDir,
        string $namespacePrefix,
        array &$metadataCollection,
    ): void {
        if ($filePath === false) {
            return;
        }

        $fqcn = $this->pathToFqcn($filePath, $baseDir, $namespacePrefix);

        if (class_exists($fqcn) === false) {
            require_once $filePath;
        }

        if (class_exists($fqcn) === false) {
            return;
        }

        $ref = new \ReflectionClass($fqcn);

        if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait() || $ref->isEnum()) {
            return;
        }

        $metadataCollection[] = $this->metadataFactory->getMetadataFor($fqcn);
    }

    /**
     * Check if a path contains glob pattern characters.
     */
    private function isGlobPattern(string $path): bool
    {
        return str_contains($path, '*') || str_contains($path, '?');
    }

    /**
     * Parse a glob pattern into base directory and filename pattern.
     *
     * @return array{0: string, 1: string} [baseDir, namePattern]
     */
    private function parseGlobPattern(string $pattern): array
    {
        $lastSeparator = max((int) strrpos($pattern, '/'), (int) strrpos($pattern, \DIRECTORY_SEPARATOR));

        if ($lastSeparator === 0) {
            return ['.', $pattern];
        }

        $dirPart = substr($pattern, 0, $lastSeparator);
        $namePart = substr($pattern, $lastSeparator + 1);

        // If the directory part contains globs, find the non-glob prefix
        if ($this->isGlobPattern($dirPart)) {
            $firstGlobPos = min(
                ($p1 = strpos($dirPart, '*')) === false ? \PHP_INT_MAX : $p1,
                ($p2 = strpos($dirPart, '?')) === false ? \PHP_INT_MAX : $p2,
            );

            $prefixPart = substr($dirPart, 0, $firstGlobPos);
            $lastSepBeforeGlob = max((int) strrpos($prefixPart, '/'), (int) strrpos($prefixPart, \DIRECTORY_SEPARATOR));

            $baseDir = $lastSepBeforeGlob > 0 ? substr($dirPart, 0, $lastSepBeforeGlob) : $dirPart;

            // Build a combined pattern: subdir glob + filename pattern
            $subdirGlob = $lastSepBeforeGlob > 0 ? substr($dirPart, $lastSepBeforeGlob + 1) : '';
            if ($subdirGlob !== '') {
                $namePart = $subdirGlob . '/' . $namePart;
            }

            return [$baseDir, $namePart];
        }

        return [$dirPart, $namePart];
    }

    /**
     * Derive the FQCN from a real file path using the PSR-4 namespace prefix and
     * the configured base directory, without reading the file content.
     *
     * @return class-string
     */
    private function pathToFqcn(string $filePath, string $baseDir, string $namespacePrefix): string
    {
        $relative = substr($filePath, \strlen($baseDir) + 1); // strip base dir + separator
        $relative = substr($relative, 0, -4); // strip .php
        $relative = str_replace(\DIRECTORY_SEPARATOR, "\\", $relative);

        /** @var class-string */
        return rtrim($namespacePrefix, "\\") . "\\" . $relative;
    }
}
