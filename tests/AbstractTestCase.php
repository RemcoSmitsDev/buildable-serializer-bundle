<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerGenerator;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerPathResolver;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\DenormalizerWriter;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerGenerator;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerPathResolver;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerWriter;
use RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

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
                'preserve_empty_objects' => true,
                'context' => true,
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

    protected function makeDenormalizerGenerator(string $namespace = self::GENERATED_NAMESPACE): DenormalizerGenerator
    {
        return new DenormalizerGenerator(
            metadataFactory: $this->makeMetadataFactory(),
            generatedNamespace: $namespace,
            features: [
                'groups' => true,
                'strict_types' => true,
            ],
        );
    }

    protected function makeDenormalizerPathResolver(
        string $tempDir,
        string $namespace = self::GENERATED_NAMESPACE,
    ): DenormalizerPathResolver {
        return new DenormalizerPathResolver(cacheDir: $tempDir, generatedNamespace: $namespace);
    }

    protected function makeDenormalizerWriter(
        string $tempDir,
        string $namespace = self::GENERATED_NAMESPACE,
    ): DenormalizerWriter {
        return new DenormalizerWriter(
            generator: $this->makeDenormalizerGenerator($namespace),
            pathResolver: $this->makeDenormalizerPathResolver($tempDir, $namespace),
        );
    }

    /**
     * Generate, write, and load the denormalizer class for the given FQCN,
     * returning an instantiated denormalizer ready to be wired up with a
     * mock or real DenormalizerInterface via `setDenormalizer()`.
     *
     * @template T of object
     *
     * @param class-string<T> $targetFqcn FQCN of the domain class to build a denormalizer for.
     * @param string          $tempDir    Directory where the generated file should be written.
     *
     * @return object The instantiated generated denormalizer.
     */
    protected function loadDenormalizerFor(string $targetFqcn, string $tempDir): DenormalizerInterface
    {
        $generator = $this->makeDenormalizerGenerator();
        $factory = $generator->getMetadataFactory();
        $metadata = $factory->getMetadataFor($targetFqcn);

        $pathResolver = $this->makeDenormalizerPathResolver($tempDir);
        $writer = $this->makeDenormalizerWriter($tempDir);

        $fqcn = $pathResolver->resolveDenormalizerFqcn($metadata);

        if (!class_exists($fqcn, false)) {
            $filePath = $writer->write($metadata);
            require_once $filePath;
        }

        return new $fqcn();
    }
}
