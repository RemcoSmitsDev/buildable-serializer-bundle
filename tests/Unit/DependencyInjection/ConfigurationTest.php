<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Configuration
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

        $this->assertSame([], $config['paths']);
        $this->assertArrayNotHasKey('classes', $config);
        $this->assertArrayNotHasKey('namespaces', $config);
        $this->assertArrayNotHasKey('exclude', $config);
        $this->assertArrayNotHasKey('generated_namespace', $config);
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

        $this->assertSame(["App\Model" => ['path' => '/tmp/src/Model', 'exclude' => null]], $config['paths']);
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
        $this->assertSame(['path' => '/tmp/src/Model', 'exclude' => null], $config['paths']["App\Model"]);
        $this->assertSame(['path' => '/tmp/src/Entity', 'exclude' => null], $config['paths']["App\Entity"]);
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

        $this->assertCount(2, $config['paths']);
        $this->assertSame(['path' => '/tmp/src/Model', 'exclude' => null], $config['paths']["App\Model"]);
        $this->assertSame(['path' => '/tmp/src/Entity', 'exclude' => null], $config['paths']["App\Entity"]);
        $this->assertTrue($config['features']['groups']);
        $this->assertFalse($config['features']['max_depth']);
        $this->assertTrue($config['features']['circular_reference']);
        $this->assertFalse($config['features']['name_converter']);
        $this->assertTrue($config['features']['skip_null_values']);
        $this->assertTrue($config['generation']['strict_types']);
        $this->assertArrayNotHasKey('psr4', $config['generation']);
    }

    public function testPathsWithExcludeOption(): void
    {
        $config = $this->processConfig([
            'paths' => [
                "App\Model" => [
                    'path' => '/tmp/src/Model',
                    'exclude' => '*Helper.php',
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['paths']["App\Model"]['path']);
        $this->assertSame('*Helper.php', $config['paths']["App\Model"]['exclude']);
    }

    public function testPathsWithExcludeDefaultsToNull(): void
    {
        $config = $this->processConfig([
            'paths' => [
                "App\Model" => [
                    'path' => '/tmp/src/Model',
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['paths']["App\Model"]['path']);
        $this->assertNull($config['paths']["App\Model"]['exclude']);
    }

    public function testPathsStringAndArrayFormatCanBeMixed(): void
    {
        $config = $this->processConfig([
            'paths' => [
                "App\Model" => '/tmp/src/Model',
                "App\Entity" => [
                    'path' => '/tmp/src/Entity',
                    'exclude' => '*Repository.php',
                ],
            ],
        ]);

        $this->assertSame(['path' => '/tmp/src/Model', 'exclude' => null], $config['paths']["App\Model"]);
        $this->assertSame(
            ['path' => '/tmp/src/Entity', 'exclude' => '*Repository.php'],
            $config['paths']["App\Entity"],
        );
    }

    public function testPathsWithExcludeAsArray(): void
    {
        $config = $this->processConfig([
            'paths' => [
                "App\Model" => [
                    'path' => '/tmp/src/Model',
                    'exclude' => ['*Helper.php', '*Test.php'],
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['paths']["App\Model"]['path']);
        $this->assertSame(['*Helper.php', '*Test.php'], $config['paths']["App\Model"]['exclude']);
    }

    public function testPathsWithExcludeAsEmptyArray(): void
    {
        $config = $this->processConfig([
            'paths' => [
                "App\Model" => [
                    'path' => '/tmp/src/Model',
                    'exclude' => [],
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['paths']["App\Model"]['path']);
        $this->assertSame([], $config['paths']["App\Model"]['exclude']);
    }

    public function testPathsMixedExcludeFormats(): void
    {
        $config = $this->processConfig([
            'paths' => [
                "App\Model" => '/tmp/src/Model',
                "App\Entity" => [
                    'path' => '/tmp/src/Entity',
                    'exclude' => '*Repository.php',
                ],
                "App\Command" => [
                    'path' => '/tmp/src/Command',
                    'exclude' => ['*Helper.php', '*Test.php'],
                ],
            ],
        ]);

        $this->assertSame(['path' => '/tmp/src/Model', 'exclude' => null], $config['paths']["App\Model"]);
        $this->assertSame('*Repository.php', $config['paths']["App\Entity"]['exclude']);
        $this->assertSame(['*Helper.php', '*Test.php'], $config['paths']["App\Command"]['exclude']);
    }
}
