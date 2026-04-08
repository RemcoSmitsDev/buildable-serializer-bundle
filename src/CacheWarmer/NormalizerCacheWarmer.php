<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\CacheWarmer;

use Buildable\SerializerBundle\Discovery\ClassDiscoveryInterface;
use Buildable\SerializerBundle\Generator\NormalizerGenerator;
use Buildable\SerializerBundle\Generator\NormalizerGeneratorInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Warms up the Symfony application cache by generating optimised PHP normalizer
 * classes for every class discovered by the configured discovery strategy.
 *
 * This cache warmer is registered with the `kernel.cache_warmer` tag (priority 10)
 * so it runs early in the warm-up sequence, before the container is dumped. This
 * ensures that the {@see \Buildable\SerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass}
 * can locate the generated files on the subsequent container rebuild triggered by
 * `cache:warmup --no-debug` or `cache:clear`.
 *
 * ### Warm-up process
 *
 *  1. Ask the {@see ClassDiscoveryInterface} for all concrete classes that require
 *     a generated normalizer.
 *  2. Pass the list to {@see NormalizerGenerator::generateAll()} which writes one
 *     PHP file per class into the configured cache directory.
 *  3. Log a summary of how many files were written (when a logger is available).
 *
 * ### Optional warm-up
 *
 * The warmer is marked as **optional** (i.e. {@see isOptional()} returns `true`).
 * This means the application will boot correctly even when the cache has not been
 * warmed yet – it will simply fall back to the standard Symfony ObjectNormalizer.
 * The warm-up merely enables the build-time optimisation.
 *
 * @see NormalizerGenerator
 * @see ClassDiscoveryInterface
 */
final class NormalizerCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param NormalizerGeneratorInterface $generator  Generator that produces the PHP normalizer source files.
     * @param ClassDiscoveryInterface      $discovery  Strategy used to locate classes that need normalizers.
     * @param string                       $cacheDir   Absolute path to the directory where generated files are written.
     */
    public function __construct(
        private readonly NormalizerGeneratorInterface $generator,
        private readonly ClassDiscoveryInterface $discovery,
        private readonly string $cacheDir,
    ) {}

    /**
     * Generate normalizer PHP files for all discovered classes.
     *
     * The $cacheDir parameter supplied by the framework is intentionally ignored
     * here: we always write to the bundle's own configured cache directory so
     * that the compiler pass can reliably find the generated files regardless of
     * which Symfony environment is active.
     *
     * @param string $cacheDir Symfony's application cache directory (unused; see above).
     *
     * @return list<string> Absolute paths of all generated files (may be empty).
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->ensureCacheDirectoryExists();

        return $this->generator->generateAll($this->discovery->discoverClasses());
    }

    /**
     * Return true to mark this warmer as optional.
     *
     * Marking the warmer optional means Symfony will not abort the boot sequence
     * when this warmer fails or produces no output. The application can still
     * start, using the standard reflection-based normalizers as a fallback.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * Ensure the configured cache directory exists, creating it (and any missing
     * parent directories) with standard directory permissions.
     *
     * @throws \RuntimeException When the directory cannot be created.
     */
    private function ensureCacheDirectoryExists(): void
    {
        if (is_dir($this->cacheDir)) {
            return;
        }

        if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException(sprintf(
                'Failed to create the buildable serializer cache directory "%s". '
                . 'Please check that the parent directory is writable.',
                $this->cacheDir,
            ));
        }
    }
}
