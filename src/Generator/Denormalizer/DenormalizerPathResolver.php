<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer;

use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;

/**
 * Service responsible for resolving paths and fully-qualified class names
 * for generated denormalizers.
 *
 * This class handles the mapping between source domain classes and their
 * corresponding generated denormalizer classes, including:
 * - Determining the FQCN of the generated denormalizer
 * - Determining the filesystem path where the denormalizer will be written
 */
final class DenormalizerPathResolver implements DenormalizerPathResolverInterface
{
    /**
     * @param string $cacheDir           Absolute path of the generation target directory.
     * @param string $generatedNamespace Root PHP namespace for all generated classes.
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly string $generatedNamespace,
    ) {}

    /**
     * @inheritdoc
     */
    public function resolveDenormalizerFqcn(ClassMetadata $metadata): string
    {
        return $this->resolveDenormalizerNamespace() . '\\' . $this->resolveDenormalizerClassName($metadata);
    }

    /**
     * @inheritdoc
     */
    public function resolveFilePath(ClassMetadata $metadata): string
    {
        return (
            rtrim($this->cacheDir, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . $this->buildPsr4RelativePath($metadata)
        );
    }

    /**
     * Get the namespace for generated denormalizers.
     */
    private function resolveDenormalizerNamespace(): string
    {
        return rtrim($this->generatedNamespace, '\\');
    }

    /**
     * Get the class name (without namespace) for a generated denormalizer.
     *
     * Uses a hash prefix to avoid collisions when classes with the same short
     * name exist in different namespaces.
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

        // Prefix with 'N' to ensure valid PHP class name (cannot start with a number).
        // 'N' is shared with the normalizer resolver on purpose: the class name
        // suffix ("Denormalizer" vs. "Normalizer") disambiguates them on disk.
        return 'N' . substr(hash('xxh3', $classNs), 0, 8) . '_';
    }

    /**
     * Build the relative file path under cacheDir using a flat structure.
     *
     * Instead of nested directories matching the namespace hierarchy,
     * all denormalizer files are placed directly in the cache directory
     * with a hashed namespace prefix to avoid collisions.
     *
     * Example:
     *   class = App\Entity\User
     *   → N12345678_UserDenormalizer.php (where 12345678 is hash of "App\Entity")
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     */
    private function buildPsr4RelativePath(ClassMetadata $metadata): string
    {
        return $this->buildNamespacePrefix($metadata) . $metadata->getShortName() . 'Denormalizer.php';
    }
}
