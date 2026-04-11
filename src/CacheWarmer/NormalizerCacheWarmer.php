<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\CacheWarmer;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGeneratorInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final class NormalizerCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param NormalizerGeneratorInterface $generator  Generator that produces the PHP normalizer source files.
     * @param ClassDiscoveryInterface      $discovery  Strategy used to locate classes that need normalizers.
     */
    public function __construct(
        private readonly NormalizerGeneratorInterface $generator,
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

        // Use the buildDir (Symfony 6.3+) or fall back to cacheDir
        $targetDir = ($buildDir ?? $cacheDir) . '/buildable_serializer';

        $this->ensureDirectoryExists($targetDir);

        // Tell the generator to write to the correct directory
        return $this->generator->generateAll($classes, $targetDir);
    }

    public function isOptional(): bool
    {
        return false;
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (mkdir($dir, 0755, true) === false && is_dir($dir) === false) {
            throw new \RuntimeException(sprintf('Failed to create directory "%s".', $dir));
        }
    }
}
