<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\CacheWarmer;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerWriterInterface;
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

    /** @inheritdoc */
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
