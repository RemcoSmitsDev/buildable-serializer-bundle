<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests;

use Buildable\SerializerBundle\Generator\NormalizerGenerator;
use Buildable\SerializerBundle\Metadata\MetadataFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;

abstract class AbstractTestCase extends TestCase
{
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

    protected function makeGenerator(
        string $tempDir,
        string $namespace = "BuildableTest\Generated",
    ): NormalizerGenerator {
        return new NormalizerGenerator(
            metadataFactory: $this->makeMetadataFactory(),
            cacheDir: $tempDir,
            generatedNamespace: $namespace,
            features: [
                'groups' => true,
                'max_depth' => true,
                'circular_reference' => true,
                'name_converter' => false,
                'skip_null_values' => true,
            ],
            generation: [
                'psr4' => false,
                'strict_types' => true,
            ],
        );
    }
}
