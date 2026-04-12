<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\CacheWarmer;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerWriterInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final class NormalizerCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param NormalizerWriterInterface $writer    Writer that produces the PHP normalizer source files.
     * @param ClassDiscoveryInterface   $discovery Strategy used to locate classes that need normalizers.
     */
    public function __construct(
        private readonly NormalizerWriterInterface $writer,
        private readonly ClassDiscoveryInterface $discovery,
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
