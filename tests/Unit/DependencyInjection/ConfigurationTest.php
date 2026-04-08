<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Unit\DependencyInjection;

use BuildableSerializerBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * @covers \BuildableSerializerBundle\DependencyInjection\Configuration
 */
final class ConfigurationTest extends TestCase
{
    private function processConfig(array $config): array
    {
        return (new Processor())->processConfiguration(
            new Configuration(),
            [
                $config,
            ],
        );
    }

    public function testDefaultValues(): void
    {
        $config = $this->processConfig([]);

        $this->assertSame('%kernel.project_dir%/var/buildable_serializer', $config['cache_dir']);
        $this->assertSame("BuildableSerializer\Generated", $config['generated_namespace']);
        $this->assertSame([], $config['paths']);
        $this->assertArrayNotHasKey('classes', $config);
        $this->assertArrayNotHasKey('namespaces', $config);
        $this->assertArrayNotHasKey('exclude', $config);
    }

    public function testDefaultFeaturesValues(): void
    {
        $config = $this->processConfig([]);

        $this->assertTrue($config['features']['groups']);
        $this->assertTrue($config['features']['max_depth']);
        $this->assertTrue($config['features']['circular_reference']);
        $this->assertFalse($config['features']['name_converter']);
        $this->assertTrue($config['features']['skip_null_values']);
    }

    public function testDefaultGenerationValues(): void
    {
        $config = $this->processConfig([]);

        $this->assertTrue($config['generation']['strict_types']);
        $this->assertArrayNotHasKey('psr4', $config['generation']);
    }

    public function testCanSetCacheDir(): void
    {
        $config = $this->processConfig(['cache_dir' => '/tmp/my_cache']);

        $this->assertSame('/tmp/my_cache', $config['cache_dir']);
    }

    public function testCacheDirWithKernelParameter(): void
    {
        $config = $this->processConfig([
            'cache_dir' => '%kernel.cache_dir%/custom',
        ]);

        $this->assertSame('%kernel.cache_dir%/custom', $config['cache_dir']);
    }

    public function testCanSetGeneratedNamespace(): void
    {
        $config = $this->processConfig([
            'generated_namespace' => "My\Generated\Normalizers",
        ]);

        $this->assertSame("My\Generated\Normalizers", $config['generated_namespace']);
    }

    public function testGeneratedNamespaceWithBackslash(): void
    {
        $config = $this->processConfig([
            'generated_namespace' => "App\\Normalizer\\Generated",
        ]);

        $this->assertSame("App\\Normalizer\\Generated", $config['generated_namespace']);
    }

    public function testPathsDefaultsToEmptyArray(): void
    {
        $config = $this->processConfig([]);

        $this->assertIsArray($config['paths']);
        $this->assertEmpty($config['paths']);
    }

    public function testCanSetPaths(): void
    {
        $config = $this->processConfig([
            'paths' => ["App\Model" => '/tmp/src/Model'],
        ]);

        $this->assertSame(["App\Model" => '/tmp/src/Model'], $config['paths']);
    }

    public function testMultiplePaths(): void
    {
        $config = $this->processConfig([
            'paths' => [
                "App\Model" => '/tmp/src/Model',
                "App\Entity" => '/tmp/src/Entity',
            ],
        ]);

        $this->assertCount(2, $config['paths']);
        $this->assertSame('/tmp/src/Model', $config['paths']["App\Model"]);
        $this->assertSame('/tmp/src/Entity', $config['paths']["App\Entity"]);
    }

    public function testCanDisableGroups(): void
    {
        $config = $this->processConfig(['features' => ['groups' => false]]);

        $this->assertFalse($config['features']['groups']);
    }

    public function testCanDisableMaxDepth(): void
    {
        $config = $this->processConfig(['features' => ['max_depth' => false]]);

        $this->assertFalse($config['features']['max_depth']);
    }

    public function testCanDisableCircularReference(): void
    {
        $config = $this->processConfig([
            'features' => ['circular_reference' => false],
        ]);

        $this->assertFalse($config['features']['circular_reference']);
    }

    public function testCanEnableNameConverter(): void
    {
        $config = $this->processConfig([
            'features' => ['name_converter' => true],
        ]);

        $this->assertTrue($config['features']['name_converter']);
    }

    public function testCanDisableNameConverter(): void
    {
        $config = $this->processConfig([
            'features' => ['name_converter' => false],
        ]);

        $this->assertFalse($config['features']['name_converter']);
    }

    public function testCanDisableSkipNullValues(): void
    {
        $config = $this->processConfig([
            'features' => ['skip_null_values' => false],
        ]);

        $this->assertFalse($config['features']['skip_null_values']);
    }

    public function testCanDisableAllFeatures(): void
    {
        $config = $this->processConfig([
            'features' => [
                'groups' => false,
                'max_depth' => false,
                'circular_reference' => false,
                'name_converter' => false,
                'skip_null_values' => false,
            ],
        ]);

        $this->assertFalse($config['features']['groups']);
        $this->assertFalse($config['features']['max_depth']);
        $this->assertFalse($config['features']['circular_reference']);
        $this->assertFalse($config['features']['name_converter']);
        $this->assertFalse($config['features']['skip_null_values']);
    }

    public function testCanDisableStrictTypes(): void
    {
        $config = $this->processConfig([
            'generation' => ['strict_types' => false],
        ]);

        $this->assertFalse($config['generation']['strict_types']);
    }

    public function testGenerationDefaults(): void
    {
        $config = $this->processConfig([]);

        $this->assertTrue($config['generation']['strict_types']);
        $this->assertArrayNotHasKey('psr4', $config['generation']);
    }

    public function testFullConfiguration(): void
    {
        $config = $this->processConfig([
            'cache_dir' => '/var/cache/serializer',
            'generated_namespace' => "My\Normalizers",
            'paths' => [
                "App\Model" => '/tmp/src/Model',
                "App\Entity" => '/tmp/src/Entity',
            ],
            'features' => [
                'groups' => true,
                'max_depth' => false,
                'circular_reference' => true,
                'name_converter' => false,
                'skip_null_values' => true,
            ],
            'generation' => [
                'strict_types' => true,
            ],
        ]);

        $this->assertSame('/var/cache/serializer', $config['cache_dir']);
        $this->assertSame("My\Normalizers", $config['generated_namespace']);
        $this->assertCount(2, $config['paths']);
        $this->assertSame('/tmp/src/Model', $config['paths']["App\Model"]);
        $this->assertSame('/tmp/src/Entity', $config['paths']["App\Entity"]);
        $this->assertTrue($config['features']['groups']);
        $this->assertFalse($config['features']['max_depth']);
        $this->assertTrue($config['features']['circular_reference']);
        $this->assertFalse($config['features']['name_converter']);
        $this->assertTrue($config['features']['skip_null_values']);
        $this->assertTrue($config['generation']['strict_types']);
        $this->assertArrayNotHasKey('psr4', $config['generation']);
    }
}
