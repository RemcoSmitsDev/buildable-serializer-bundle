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

        $this->assertArrayHasKey('normalizers', $config);
        $this->assertArrayHasKey('denormalizers', $config);
        $this->assertSame([], $config['normalizers']['paths']);
        $this->assertSame([], $config['denormalizers']['paths']);
    }

    public function testDefaultNormalizersFeaturesValues(): void
    {
        $config = $this->processConfig([]);

        $this->assertTrue($config['normalizers']['features']['groups']);
        $this->assertTrue($config['normalizers']['features']['max_depth']);
        $this->assertTrue($config['normalizers']['features']['circular_reference']);
        $this->assertTrue($config['normalizers']['features']['skip_null_values']);
    }

    public function testDefaultDenormalizersFeaturesValues(): void
    {
        $config = $this->processConfig([]);

        $this->assertTrue($config['denormalizers']['features']['groups']);
        $this->assertTrue($config['denormalizers']['features']['strict_types']);
        $this->assertArrayNotHasKey('max_depth', $config['denormalizers']['features']);
        $this->assertArrayNotHasKey('circular_reference', $config['denormalizers']['features']);
        $this->assertArrayNotHasKey('skip_null_values', $config['denormalizers']['features']);
    }

    public function testDefaultStrictTypesValues(): void
    {
        $config = $this->processConfig([]);

        $this->assertTrue($config['normalizers']['features']['strict_types']);
        $this->assertTrue($config['denormalizers']['features']['strict_types']);
    }

    public function testNormalizersPathsDefaultsToEmptyArray(): void
    {
        $config = $this->processConfig([]);

        $this->assertIsArray($config['normalizers']['paths']);
        $this->assertEmpty($config['normalizers']['paths']);
    }

    public function testDenormalizersPathsDefaultsToEmptyArray(): void
    {
        $config = $this->processConfig([]);

        $this->assertIsArray($config['denormalizers']['paths']);
        $this->assertEmpty($config['denormalizers']['paths']);
    }

    public function testCanSetNormalizersPaths(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => ["App\Model" => '/tmp/src/Model'],
            ],
        ]);

        $this->assertSame(
            ["App\Model" => ['path' => '/tmp/src/Model', 'exclude' => null]],
            $config['normalizers']['paths'],
        );
    }

    public function testCanSetDenormalizersPaths(): void
    {
        $config = $this->processConfig([
            'denormalizers' => [
                'paths' => ["App\DTO" => '/tmp/src/DTO'],
            ],
        ]);

        $this->assertSame(
            ["App\DTO" => ['path' => '/tmp/src/DTO', 'exclude' => null]],
            $config['denormalizers']['paths'],
        );
    }

    public function testMultipleNormalizersPaths(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => [
                    "App\Model" => '/tmp/src/Model',
                    "App\Entity" => '/tmp/src/Entity',
                ],
            ],
        ]);

        $this->assertCount(2, $config['normalizers']['paths']);
        $this->assertSame(
            ['path' => '/tmp/src/Model', 'exclude' => null],
            $config['normalizers']['paths']["App\Model"],
        );
        $this->assertSame(
            ['path' => '/tmp/src/Entity', 'exclude' => null],
            $config['normalizers']['paths']["App\Entity"],
        );
    }

    public function testCanDisableNormalizersGroups(): void
    {
        $config = $this->processConfig([
            'normalizers' => ['features' => ['groups' => false]],
        ]);

        $this->assertFalse($config['normalizers']['features']['groups']);
    }

    public function testCanDisableDenormalizersGroups(): void
    {
        $config = $this->processConfig([
            'denormalizers' => ['features' => ['groups' => false]],
        ]);

        $this->assertFalse($config['denormalizers']['features']['groups']);
    }

    public function testCanDisableNormalizersMaxDepth(): void
    {
        $config = $this->processConfig([
            'normalizers' => ['features' => ['max_depth' => false]],
        ]);

        $this->assertFalse($config['normalizers']['features']['max_depth']);
    }

    public function testCanDisableNormalizersCircularReference(): void
    {
        $config = $this->processConfig([
            'normalizers' => ['features' => ['circular_reference' => false]],
        ]);

        $this->assertFalse($config['normalizers']['features']['circular_reference']);
    }

    public function testCanDisableNormalizersSkipNullValues(): void
    {
        $config = $this->processConfig([
            'normalizers' => ['features' => ['skip_null_values' => false]],
        ]);

        $this->assertFalse($config['normalizers']['features']['skip_null_values']);
    }

    public function testCanDisableAllNormalizersFeatures(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'features' => [
                    'groups' => false,
                    'max_depth' => false,
                    'circular_reference' => false,
                    'skip_null_values' => false,
                ],
            ],
        ]);

        $this->assertFalse($config['normalizers']['features']['groups']);
        $this->assertFalse($config['normalizers']['features']['max_depth']);
        $this->assertFalse($config['normalizers']['features']['circular_reference']);
        $this->assertFalse($config['normalizers']['features']['skip_null_values']);
    }

    public function testCanDisableNormalizersStrictTypes(): void
    {
        $config = $this->processConfig([
            'normalizers' => ['features' => ['strict_types' => false]],
        ]);

        $this->assertFalse($config['normalizers']['features']['strict_types']);
    }

    public function testCanDisableDenormalizersStrictTypes(): void
    {
        $config = $this->processConfig([
            'denormalizers' => ['features' => ['strict_types' => false]],
        ]);

        $this->assertFalse($config['denormalizers']['features']['strict_types']);
    }

    public function testFullConfiguration(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => [
                    "App\Model" => '/tmp/src/Model',
                    "App\Entity" => '/tmp/src/Entity',
                ],
                'features' => [
                    'groups' => true,
                    'max_depth' => false,
                    'circular_reference' => true,
                    'skip_null_values' => true,
                    'strict_types' => true,
                ],
            ],
            'denormalizers' => [
                'paths' => [
                    "App\DTO" => '/tmp/src/DTO',
                ],
                'features' => [
                    'groups' => false,
                    'strict_types' => false,
                ],
            ],
        ]);

        // Normalizers assertions
        $this->assertCount(2, $config['normalizers']['paths']);
        $this->assertSame(
            ['path' => '/tmp/src/Model', 'exclude' => null],
            $config['normalizers']['paths']["App\Model"],
        );
        $this->assertSame(
            ['path' => '/tmp/src/Entity', 'exclude' => null],
            $config['normalizers']['paths']["App\Entity"],
        );
        $this->assertTrue($config['normalizers']['features']['groups']);
        $this->assertFalse($config['normalizers']['features']['max_depth']);
        $this->assertTrue($config['normalizers']['features']['circular_reference']);
        $this->assertTrue($config['normalizers']['features']['skip_null_values']);
        $this->assertTrue($config['normalizers']['features']['strict_types']);

        // Denormalizers assertions
        $this->assertCount(1, $config['denormalizers']['paths']);
        $this->assertSame(['path' => '/tmp/src/DTO', 'exclude' => null], $config['denormalizers']['paths']["App\DTO"]);
        $this->assertFalse($config['denormalizers']['features']['groups']);
        $this->assertFalse($config['denormalizers']['features']['strict_types']);
    }

    public function testNormalizersPathsWithExcludeOption(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => [
                    "App\Model" => [
                        'path' => '/tmp/src/Model',
                        'exclude' => '*Helper.php',
                    ],
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['normalizers']['paths']["App\Model"]['path']);
        $this->assertSame('*Helper.php', $config['normalizers']['paths']["App\Model"]['exclude']);
    }

    public function testDenormalizersPathsWithExcludeOption(): void
    {
        $config = $this->processConfig([
            'denormalizers' => [
                'paths' => [
                    "App\DTO" => [
                        'path' => '/tmp/src/DTO',
                        'exclude' => '*Request.php',
                    ],
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/DTO', $config['denormalizers']['paths']["App\DTO"]['path']);
        $this->assertSame('*Request.php', $config['denormalizers']['paths']["App\DTO"]['exclude']);
    }

    public function testPathsWithExcludeDefaultsToNull(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => [
                    "App\Model" => [
                        'path' => '/tmp/src/Model',
                    ],
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['normalizers']['paths']["App\Model"]['path']);
        $this->assertNull($config['normalizers']['paths']["App\Model"]['exclude']);
    }

    public function testPathsStringAndArrayFormatCanBeMixed(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => [
                    "App\Model" => '/tmp/src/Model',
                    "App\Entity" => [
                        'path' => '/tmp/src/Entity',
                        'exclude' => '*Repository.php',
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['path' => '/tmp/src/Model', 'exclude' => null],
            $config['normalizers']['paths']["App\Model"],
        );
        $this->assertSame(
            ['path' => '/tmp/src/Entity', 'exclude' => '*Repository.php'],
            $config['normalizers']['paths']["App\Entity"],
        );
    }

    public function testPathsWithExcludeAsArray(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => [
                    "App\Model" => [
                        'path' => '/tmp/src/Model',
                        'exclude' => ['*Helper.php', '*Test.php'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['normalizers']['paths']["App\Model"]['path']);
        $this->assertSame(['*Helper.php', '*Test.php'], $config['normalizers']['paths']["App\Model"]['exclude']);
    }

    public function testPathsWithExcludeAsEmptyArray(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => [
                    "App\Model" => [
                        'path' => '/tmp/src/Model',
                        'exclude' => [],
                    ],
                ],
            ],
        ]);

        $this->assertSame('/tmp/src/Model', $config['normalizers']['paths']["App\Model"]['path']);
        $this->assertSame([], $config['normalizers']['paths']["App\Model"]['exclude']);
    }

    public function testPathsMixedExcludeFormats(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
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
            ],
        ]);

        $this->assertSame(
            ['path' => '/tmp/src/Model', 'exclude' => null],
            $config['normalizers']['paths']["App\Model"],
        );
        $this->assertSame('*Repository.php', $config['normalizers']['paths']["App\Entity"]['exclude']);
        $this->assertSame(['*Helper.php', '*Test.php'], $config['normalizers']['paths']["App\Command"]['exclude']);
    }

    public function testIndependentNormalizersAndDenormalizersConfiguration(): void
    {
        $config = $this->processConfig([
            'normalizers' => [
                'paths' => ["App\Model" => '/tmp/src/Model'],
                'features' => ['groups' => false, 'strict_types' => false],
            ],
            'denormalizers' => [
                'paths' => ["App\DTO" => '/tmp/src/DTO'],
                'features' => ['groups' => true, 'strict_types' => true],
            ],
        ]);

        // Normalizers should have its own config
        $this->assertSame(
            ["App\Model" => ['path' => '/tmp/src/Model', 'exclude' => null]],
            $config['normalizers']['paths'],
        );
        $this->assertFalse($config['normalizers']['features']['groups']);
        $this->assertFalse($config['normalizers']['features']['strict_types']);

        // Denormalizers should have its own independent config
        $this->assertSame(
            ["App\DTO" => ['path' => '/tmp/src/DTO', 'exclude' => null]],
            $config['denormalizers']['paths'],
        );
        $this->assertTrue($config['denormalizers']['features']['groups']);
        $this->assertTrue($config['denormalizers']['features']['strict_types']);
    }
}
