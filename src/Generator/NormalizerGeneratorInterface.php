<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator;

use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;

interface NormalizerGeneratorInterface
{
    /**
     * Generate a PHP normalizer source file for the class described by `$metadata`
     * and write it to the generator's configured output directory.
     *
     * The output directory is determined by the generator's own internal configuration
     * (`%kernel.cache_dir%/buildable_serializer`). If the target directory does not exist it
     * must be created recursively. Existing files are overwritten on re-generation.
     *
     * @param ClassMetadata $metadata Fully-built metadata for the domain class.
     *
     * @return string Absolute path of the PHP file that was written to disk.
     *
     * @throws \RuntimeException When the output directory cannot be created or the
     *                           file cannot be written.
     */
    public function generateAndWrite(ClassMetadata $metadata): string;

    /**
     * Return the fully-qualified class name of the normalizer that would be (or was)
     * generated for the given metadata, without performing any file I/O.
     *
     * This method is pure: it derives the FQCN solely from the configured
     * `$generatedNamespace`, the source class namespace, and the source class short name.
     * It is safe to call before or after {@see generateAndWrite()}.
     *
     * Example: for `App\Entity\User` with namespace `BuildableSerializerBundle\Generated`
     * the result would be `BuildableSerializerBundle\Generated\App\Entity\UserNormalizer`.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata
     *
     * @return string Fully-qualified class name of the generated (or to-be-generated) normalizer.
     */
    public function resolveNormalizerFqcn(ClassMetadata $metadata): string;

    /**
     * Return the absolute filesystem path where the normalizer source file for the
     * given metadata would be (or was) written, without performing any file I/O.
     *
     * This is the counterpart of {@see resolveNormalizerFqcn()} on the filesystem side.
     * Useful for building the classmap entry (`FQCN => filePath`) without actually
     * generating the file.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata Metadata for the domain class.
     *
     * @return string Absolute path of the PHP file (may or may not exist yet).
     */
    public function resolveFilePath(ClassMetadata $metadata): string;

    /**
     * Generate PHP normalizer source files for all provided class metadata objects
     * and write them to the generator's configured output directory.
     *
     * This is a batch operation equivalent to calling {@see generateAndWrite()} for
     * each metadata object, but may be optimized for bulk generation.
     *
     * @param iterable<ClassMetadata<object>> $metadataCollection
     *
     * @return array<string> Array of absolute paths of written files, in input order.
     *
     * @throws \RuntimeException When the output directory cannot be created or a
     *                           file cannot be written.
     */
    public function generateAll(iterable $metadataCollection): array;
}
