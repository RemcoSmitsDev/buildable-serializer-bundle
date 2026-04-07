<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Generator;

/**
 * Immutable value object returned by {@see NormalizerGenerator::generate()}.
 *
 * Carries all information about a single generated normalizer class that
 * downstream consumers — such as the cache warmer and the console command —
 * need in order to:
 *
 * - Write a classmap entry (`$fqcn => $filePath`) to the autoload file.
 * - Report what was generated to the operator.
 * - Optionally require/load the file before the container is compiled.
 *
 * All properties are intentionally public so consumers can read them without
 * accessor boilerplate. The object is effectively read-only after construction;
 * no mutation methods are provided.
 *
 * Example usage:
 *
 * ```php
 * $info = $generator->generate($metadata, $outputDir);
 *
 * echo $info->fqcn;                // Buildable\Generated\Normalizer\UserNormalizer
 * echo $info->filePath;            // /var/cache/prod/normalizers/UserNormalizer.php
 * echo $info->normalizedClassName; // UserNormalizer
 * ```
 *
 * @see NormalizerGenerator
 * @see \Buildable\SerializerBundle\CacheWarmer\NormalizerCacheWarmer
 * @see \Buildable\SerializerBundle\Command\GenerateNormalizersCommand
 */
final class GeneratedNormalizerInfo implements \Stringable
{
    /**
     * The fully-qualified class name of the generated normalizer.
     *
     * Example: `Buildable\Generated\Normalizer\UserNormalizer`
     *
     * This value is used as the key in the autoload classmap so the class can
     * be resolved by the Composer autoloader (or the bundle's own classmap
     * loader registered via the compiler pass).
     *
     * @var class-string
     */
    public string $fqcn;

    /**
     * Absolute path to the generated PHP file on disk.
     *
     * Example: `/var/cache/prod/normalizers/UserNormalizer.php`
     *
     * This value is used as the value in the autoload classmap and is also
     * returned from {@see \Buildable\SerializerBundle\CacheWarmer\NormalizerCacheWarmer::warmUp()}
     * so that Symfony's preload mechanism can include the file if desired.
     */
    public string $filePath;

    /**
     * The unqualified (short) class name of the generated normalizer.
     *
     * Example: `UserNormalizer`
     *
     * Derived from the source class name by appending a configurable suffix
     * (default: `Normalizer`). Useful for display purposes in console output.
     */
    public string $normalizedClassName;

    /**
     * @param class-string $fqcn                The fully-qualified class name of the generated normalizer.
     * @param string       $filePath            Absolute path to the generated PHP file on disk.
     * @param string       $normalizedClassName The unqualified class name (short name) of the generated normalizer.
     */
    public function __construct(string $fqcn, string $filePath, string $normalizedClassName)
    {
        $this->fqcn = $fqcn;
        $this->filePath = $filePath;
        $this->normalizedClassName = $normalizedClassName;
    }

    /**
     * Return a human-readable summary of this value object, useful for
     * debugging and verbose console output.
     */
    public function __toString(): string
    {
        return sprintf('GeneratedNormalizerInfo(%s => %s)', $this->fqcn, $this->filePath);
    }
}
