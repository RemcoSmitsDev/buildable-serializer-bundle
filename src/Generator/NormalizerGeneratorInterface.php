<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Generator;

use Buildable\SerializerBundle\Metadata\ClassMetadata;

/**
 * Contract for generating optimised PHP normalizer source files from class metadata.
 *
 * Depending on this interface — rather than the concrete {@see NormalizerGenerator} —
 * lets consumer services (the cache warmer, the console command) be tested in isolation
 * without touching the filesystem.
 *
 * ### Typical consumer workflow
 *
 * ```php
 * // 1. Resolve the FQCN before (or after) writing, so the classmap can be built.
 * $fqcn     = $generator->resolveNormalizerFqcn($metadata);
 *
 * // 2. Write the source file and receive its absolute path.
 * $filePath = $generator->generateAndWrite($metadata);
 *
 * // 3. Build the GeneratedNormalizerInfo value object for downstream use.
 * $shortName = substr($fqcn, strrpos($fqcn, '\\') + 1);
 * $info = new GeneratedNormalizerInfo($fqcn, $filePath, $shortName);
 * ```
 *
 * @see NormalizerGenerator   The production implementation.
 * @see GeneratedNormalizerInfo
 * @see \Buildable\SerializerBundle\CacheWarmer\NormalizerCacheWarmer
 * @see \Buildable\SerializerBundle\Command\GenerateNormalizersCommand
 */
interface NormalizerGeneratorInterface
{
    /**
     * Generate a PHP normalizer source file for the class described by `$metadata`
     * and write it to the generator's configured output directory.
     *
     * The output directory is determined by the generator's own internal configuration
     * (`buildable_serializer.cache_dir`). If the target directory does not exist it
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
     * Example: for `App\Entity\User` with namespace `BuildableSerializer\Generated`
     * the result would be `BuildableSerializer\Generated\App\Entity\UserNormalizer`.
     *
     * @param ClassMetadata $metadata Metadata for the domain class whose normalizer FQCN is needed.
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
     * @param ClassMetadata $metadata Metadata for the domain class.
     *
     * @return string Absolute path of the PHP file (may or may not exist yet).
     */
    public function resolveFilePath(ClassMetadata $metadata): string;
}
