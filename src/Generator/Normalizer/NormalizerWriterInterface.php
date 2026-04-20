<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer;

use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;

/**
 * Service responsible for writing generated normalizer source files to disk.
 *
 * This interface separates the file I/O concerns from the pure code generation
 * logic handled by {@see NormalizerGeneratorInterface}.
 *
 * Path and FQCN resolution is handled by {@see NormalizerPathResolverInterface}.
 */
interface NormalizerWriterInterface
{
    /**
     * Write a PHP normalizer source file for the class described by `$metadata`
     * to the configured output directory.
     *
     * The output directory is determined by the writer's own internal configuration
     * (`%kernel.cache_dir%/buildable_serializer`). If the target directory does not exist it
     * must be created recursively. Existing files are overwritten on re-generation.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata Fully-built metadata for the domain class.
     *
     * @return string Absolute path of the PHP file that was written to disk.
     *
     * @throws \RuntimeException When the output directory cannot be created or the
     *                           file cannot be written.
     */
    public function write(ClassMetadata $metadata): string;

    /**
     * Write PHP normalizer source files for all provided class metadata objects
     * to the configured output directory.
     *
     * This is a batch operation equivalent to calling {@see write()} for
     * each metadata object, but may be optimized for bulk generation.
     *
     * @param iterable<ClassMetadata<object>> $metadataCollection
     *
     * @return array<string> Array of absolute paths of written files, in input order.
     *
     * @throws \RuntimeException When the output directory cannot be created or a
     *                           file cannot be written.
     */
    public function writeAll(iterable $metadataCollection): array;
}
