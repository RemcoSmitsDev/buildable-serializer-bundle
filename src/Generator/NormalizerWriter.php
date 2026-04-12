<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator;

use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;

/**
 * Service responsible for writing generated normalizer PHP source files to disk.
 *
 * This class handles all file I/O operations, separating the concerns of code
 * generation (handled by {@see NormalizerGeneratorInterface}) from file system
 * operations. Path resolution is delegated to {@see NormalizerPathResolverInterface}.
 */
final class NormalizerWriter implements NormalizerWriterInterface
{
    /**
     * @param NormalizerGeneratorInterface     $generator    Generator that produces PHP source code.
     * @param NormalizerPathResolverInterface  $pathResolver Resolver for normalizer paths and FQCNs.
     */
    public function __construct(
        private readonly NormalizerGeneratorInterface $generator,
        private readonly NormalizerPathResolverInterface $pathResolver,
    ) {}

    /**
     * @inheritdoc
     */
    public function write(ClassMetadata $metadata): string
    {
        $source = $this->generator->generate($metadata);
        $filePath = $this->pathResolver->resolveFilePath($metadata);
        $directory = \dirname($filePath);

        if (is_dir($directory) === false) {
            if (mkdir($directory, 0755, true) === false && is_dir($directory) === false) {
                throw new \RuntimeException(\sprintf('Failed to create directory "%s".', $directory));
            }
        }

        if (file_put_contents($filePath, $source) === false) {
            throw new \RuntimeException(\sprintf('Failed to write file "%s".', $filePath));
        }

        return $filePath;
    }

    /**
     * @inheritdoc
     */
    public function writeAll(iterable $metadataCollection): array
    {
        $paths = [];

        foreach ($metadataCollection as $metadata) {
            $paths[] = $this->write($metadata);
        }

        return $paths;
    }
}
