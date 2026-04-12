<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerPathResolver;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerWriter;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

abstract class AbstractTestCase extends TestCase
{
    protected const GENERATED_NAMESPACE = 'BuildableTest\Generated';

    protected function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/buildable_test_' . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    protected function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeTempDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    protected function makeMetadataFactory(): MetadataFactory
    {
        $phpDoc = new PhpDocExtractor();
        $reflection = new ReflectionExtractor();
        $extractor = new PropertyInfoExtractor(
            listExtractors: [$reflection],
            typeExtractors: [$phpDoc, $reflection],
            accessExtractors: [$reflection],
        );

        return new MetadataFactory($extractor);
    }

    protected function makeGenerator(string $namespace = self::GENERATED_NAMESPACE): NormalizerGenerator
    {
        return new NormalizerGenerator(
            metadataFactory: $this->makeMetadataFactory(),
            generatedNamespace: $namespace,
            features: [
                'groups' => true,
                'max_depth' => true,
                'circular_reference' => true,
                'skip_null_values' => true,
                'strict_types' => true,
            ],
        );
    }

    protected function makePathResolver(
        string $tempDir,
        string $namespace = self::GENERATED_NAMESPACE,
    ): NormalizerPathResolver {
        return new NormalizerPathResolver(cacheDir: $tempDir, generatedNamespace: $namespace);
    }

    protected function makeWriter(string $tempDir, string $namespace = self::GENERATED_NAMESPACE): NormalizerWriter
    {
        return new NormalizerWriter(
            generator: $this->makeGenerator($namespace),
            pathResolver: $this->makePathResolver($tempDir, $namespace),
        );
    }
}
