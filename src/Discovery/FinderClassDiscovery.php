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
 * Configuration supports two formats:
 *
 * 1. Simple string — scans all PHP files recursively:
 *
 *        paths:
 *            'App\Model': '%kernel.project_dir%/src/Model'
 *
 * 2. With exclude — scans directory but excludes files matching the pattern:
 *
 *        paths:
 *            'App\Entity':
 *                path: '%kernel.project_dir%/src/Entity'
 *                exclude: '*Helper.php'
 *
 * For each configured path entry the namespace prefix is used together with the
 * file's relative location under the directory to compute the FQCN without
 * reading the file content.
 */
final class FinderClassDiscovery implements ClassDiscoveryInterface
{
    /**
     * @param MetadataFactoryInterface $metadataFactory Factory used to build fully-populated ClassMetadata.
     * @param array<string, string|array{path: string, exclude: string|string[]|null}> $paths Namespace-prefix => path config.
     */
    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory,
        private readonly array $paths,
    ) {}

    /** @return list<ClassMetadata<object>> */
    public function discoverClasses(): array
    {
        $metadataCollection = [];

        foreach ($this->paths as $namespacePrefix => $config) {
            [$directory, $exclude] = $this->normalizeConfig($config);

            $this->discoverFromDirectory($namespacePrefix, $directory, $exclude, $metadataCollection);
        }

        usort(
            $metadataCollection,
            static fn(ClassMetadata $a, ClassMetadata $b): int => $a->getClassName() <=> $b->getClassName(),
        );

        return $metadataCollection;
    }

    /**
     * Normalize the path configuration to a consistent format.
     *
     * @param string|array{path: string, exclude: string|string[]|null} $config
     * @return array{0: string, 1: string|string[]|null} [directory, exclude]
     */
    private function normalizeConfig(string|array $config): array
    {
        if (\is_string($config)) {
            return [$config, null];
        }

        return [$config['path'], $config['exclude'] ?? null];
    }

    /**
     * Discover classes from a directory path, optionally excluding files matching pattern(s).
     *
     * @param string|string[]|null $exclude
     * @param list<ClassMetadata<object>> $metadataCollection
     */
    private function discoverFromDirectory(
        string $namespacePrefix,
        string $directory,
        string|array|null $exclude,
        array &$metadataCollection,
    ): void {
        $realDir = realpath($directory);

        if ($realDir === false || is_dir($realDir) === false) {
            throw new \InvalidArgumentException(sprintf(
                'The directory "%s" configured for namespace prefix "%s" does not exist or is not a directory.',
                $directory,
                $namespacePrefix,
            ));
        }

        $finder = Finder::create()->files()->in($realDir)->name('*.php');

        if ($exclude !== null && $exclude !== []) {
            $patterns = \is_array($exclude) ? $exclude : [$exclude];
            foreach ($patterns as $pattern) {
                $finder->notName($pattern);
            }
        }

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
