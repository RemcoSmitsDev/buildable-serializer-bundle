<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator;

use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;

/**
 * Service responsible for resolving paths and fully-qualified class names
 * for generated normalizers.
 *
 * This interface separates the path resolution concerns from the file I/O
 * operations handled by {@see NormalizerWriterInterface} and the code generation
 * handled by {@see NormalizerGeneratorInterface}.
 */
interface NormalizerPathResolverInterface
{
    /**
     * Return the fully-qualified class name of the normalizer that would be (or was)
     * generated for the given metadata, without performing any file I/O.
     *
     * This method is pure: it derives the FQCN solely from the configured
     * `$generatedNamespace`, the source class namespace, and the source class short name.
     *
     * Example: for `App\Entity\User` with namespace `BuildableSerializerBundle\Generated`
     * the result would be `BuildableSerializerBundle\Generated\N12345678_UserNormalizer`.
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
}
