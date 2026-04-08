<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Discovery;

use BuildableSerializerBundle\Attribute\Serializable;
use BuildableSerializerBundle\Metadata\ClassMetadata;
use BuildableSerializerBundle\Metadata\MetadataFactoryInterface;
use Symfony\Component\Finder\Finder;

/**
 * Discovers classes marked with #[Serializable] by scanning configured directories
 * with symfony/finder and confirming the attribute via ReflectionClass.
 *
 * Configuration shape:
 *
 *     buildable_serializer:
 *         paths:
 *             'App\Model':  '%kernel.project_dir%/src/Model'
 *             'App\Entity': '%kernel.project_dir%/src/Entity'
 *
 * For each configured path entry the namespace prefix is used together with the
 * file's relative location under the directory to compute the FQCN without
 * reading the file content.
 */
final class FinderClassDiscovery implements ClassDiscoveryInterface
{
    /**
     * @param MetadataFactoryInterface $metadataFactory Factory used to build fully-populated ClassMetadata.
     * @param array<string, string>    $paths           Namespace-prefix => absolute directory path.
     */
    public function __construct(
        private readonly MetadataFactoryInterface $metadataFactory,
        private readonly array $paths,
    ) {}

    /** @return list<ClassMetadata<object>> */
    public function discoverClasses(): array
    {
        $metadataCollection = [];

        foreach ($this->paths as $namespacePrefix => $directory) {
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
                if ($file->getRealPath() === false) {
                    continue;
                }

                $fqcn = $this->pathToFqcn($file->getRealPath(), $realDir, $namespacePrefix);

                if (class_exists($fqcn) === false) {
                    require_once $file->getRealPath();
                }

                $ref = new \ReflectionClass($fqcn);

                if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait() || $ref->isEnum()) {
                    continue;
                }

                if ($ref->getAttributes(Serializable::class, \ReflectionAttribute::IS_INSTANCEOF) === []) {
                    continue;
                }

                $metadataCollection[] = $this->metadataFactory->getMetadataFor($fqcn);
            }
        }

        usort(
            $metadataCollection,
            static fn(ClassMetadata $a, ClassMetadata $b): int => $a->getClassName() <=> $b->getClassName(),
        );

        return $metadataCollection;
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
