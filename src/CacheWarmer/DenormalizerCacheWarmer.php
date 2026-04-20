<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\CacheWarmer;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerWriterInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Cache warmer that generates PHP denormalizer source files for every
 * discovered domain class.
 *
 * Mirrors {@see NormalizerCacheWarmer} but writes denormalizer files instead.
 *
 * Just like its sibling, this warmer intentionally ignores the $cacheDir
 * argument supplied by the framework and always writes to the bundle's own
 * configured cache directory so that the compiler pass can reliably locate
 * the generated files regardless of the active Symfony environment.
 */
final class DenormalizerCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param DenormalizerWriterInterface $writer    Writer that produces the PHP denormalizer source files.
     * @param ClassDiscoveryInterface     $discovery Strategy used to locate classes that need denormalizers.
     */
    public function __construct(
        private readonly DenormalizerWriterInterface $writer,
        private readonly ClassDiscoveryInterface $discovery,
    ) {}

    /**
     * Generate denormalizer PHP files for all discovered classes.
     *
     * The $cacheDir parameter supplied by the framework is intentionally ignored
     * here: we always write to the bundle's own configured cache directory so
     * that the compiler pass can reliably find the generated files regardless of
     * which Symfony environment is active.
     *
     * @param string      $cacheDir Symfony's application cache directory (unused; see above).
     * @param string|null $buildDir Symfony's build directory (unused).
     *
     * @return list<string> Absolute paths of all generated files (may be empty).
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $classes = $this->discovery->discoverClasses();

        if ($classes === []) {
            return [];
        }

        return $this->writer->writeAll($classes);
    }

    public function isOptional(): bool
    {
        return false;
    }
}
