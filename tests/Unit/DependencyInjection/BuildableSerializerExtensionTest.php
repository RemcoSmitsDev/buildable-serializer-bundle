<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\BuildableSerializerExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\BuildableSerializerExtension
 */
final class BuildableSerializerExtensionTest extends TestCase
{
    /**
     * Build a ContainerBuilder that is pre-populated with the kernel parameters
     * and stub service definitions that services.yaml references, so that the
     * extension's load() can complete without throwing a
     * ServiceNotFoundException.
     */
    private function makeContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Kernel parameters referenced by services.yaml parameter placeholders
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.project_dir', '/tmp/project');

        // Stub the property_info service that MetadataFactory depends on
        $container->register('property_info', \stdClass::class);

        return $container;
    }

    /**
     * Call extension->load() and return the container.
     *
     * The extension's loadServices() step requires symfony/yaml which is not a
     * hard dependency of this bundle. When the YAML component is absent, loading
     * services.yaml throws a RuntimeException with the message
     * "Unable to load YAML config files as the Symfony Yaml Component is not installed."
     *
     * We catch that specific exception and swallow it so that the parameter
     * registration tests — which only exercise registerParameters() — can still
     * run. Any other exception is re-thrown so the test fails with a useful message.
     *
     * Tests that verify service definitions (testLoadRegisters*Service) use
     * {@see loadExtensionForServices()} which skips if YAML is unavailable.
     *
     * @param array<int, array<string, mixed>> $configs
     */
    private function loadExtension(array $configs, ?ContainerBuilder $container = null): ContainerBuilder
    {
        $container ??= $this->makeContainer();
        $extension = new BuildableSerializerExtension();

        $extension->load($configs, $container);

        return $container;
    }

    /**
     * Call extension->load() and return the container, skipping the test if
     * symfony/yaml is unavailable (used for service-definition assertions).
     *
     * @param array<int, array<string, mixed>> $configs
     */
    private function loadExtensionForServices(array $configs, ?ContainerBuilder $container = null): ContainerBuilder
    {
        $container ??= $this->makeContainer();
        $extension = new BuildableSerializerExtension();

        try {
            $extension->load($configs, $container);
        } catch (\Symfony\Component\DependencyInjection\Exception\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Symfony Yaml Component is not installed')) {
                $this->markTestSkipped('symfony/yaml is not installed; service-definition tests require it.');
            }

            throw $e;
        }

        return $container;
    }

    public function testGetAliasReturnsCorrectKey(): void
    {
        $extension = new BuildableSerializerExtension();

        $this->assertSame('buildable_serializer', $extension->getAlias());
    }

    public function testLoadRegistersNormalizersParameters(): void
    {
        $container = $this->loadExtension([
            ['normalizers' => ['paths' => ["App\Model" => '/tmp']]],
        ]);

        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers'));
        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.paths'));
        $this->assertSame(
            ["App\Model" => ['path' => '/tmp', 'exclude' => null]],
            $container->getParameter('buildable_serializer.normalizers.paths'),
        );
    }

    public function testLoadRegistersEmptyNormalizersPathsByDefault(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.paths'));
        $this->assertSame([], $container->getParameter('buildable_serializer.normalizers.paths'));
    }

    public function testLoadRegistersMultipleNormalizersPaths(): void
    {
        $container = $this->loadExtension([
            [
                'normalizers' => [
                    'paths' => [
                        "App\Model" => '/tmp/src/Model',
                        "App\Entity" => '/tmp/src/Entity',
                    ],
                ],
            ],
        ]);

        $paths = $container->getParameter('buildable_serializer.normalizers.paths');

        $this->assertCount(2, $paths);
        $this->assertSame(['path' => '/tmp/src/Model', 'exclude' => null], $paths["App\Model"]);
        $this->assertSame(['path' => '/tmp/src/Entity', 'exclude' => null], $paths["App\Entity"]);
    }

    public function testLoadRegistersNormalizersFeatureParameters(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.features'));

        $features = $container->getParameter('buildable_serializer.normalizers.features');
        $this->assertIsArray($features);
        $this->assertArrayHasKey('groups', $features);
        $this->assertArrayHasKey('max_depth', $features);
        $this->assertArrayHasKey('circular_reference', $features);
        $this->assertArrayHasKey('skip_null_values', $features);
    }

    public function testLoadRegistersNormalizersFeatureParametersWithDefaultValues(): void
    {
        $container = $this->loadExtension([[]]);

        $features = $container->getParameter('buildable_serializer.normalizers.features');
        $this->assertTrue($features['groups']);
        $this->assertTrue($features['max_depth']);
        $this->assertTrue($features['circular_reference']);
        $this->assertTrue($features['skip_null_values']);
    }

    public function testLoadRegistersNormalizersFeatureFlatAliases(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.features.groups'));
        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.features.max_depth'));
        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.features.circular_reference'));
        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.features.skip_null_values'));
    }

    public function testLoadRegistersNormalizersFeatureWithOverriddenValues(): void
    {
        $container = $this->loadExtension([
            [
                'normalizers' => [
                    'features' => [
                        'groups' => false,
                        'max_depth' => false,
                        'circular_reference' => true,
                        'skip_null_values' => true,
                    ],
                ],
            ],
        ]);

        $features = $container->getParameter('buildable_serializer.normalizers.features');
        $this->assertFalse($features['groups']);
        $this->assertFalse($features['max_depth']);
        $this->assertTrue($features['circular_reference']);
        $this->assertTrue($features['skip_null_values']);

        // Flat aliases must match
        $this->assertFalse($container->getParameter('buildable_serializer.normalizers.features.groups'));
        $this->assertFalse($container->getParameter('buildable_serializer.normalizers.features.max_depth'));
        $this->assertTrue($container->getParameter('buildable_serializer.normalizers.features.circular_reference'));
    }

    public function testLoadRegistersNormalizersStrictTypesParameter(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.normalizers.features.strict_types'));
        $this->assertTrue($container->getParameter('buildable_serializer.normalizers.features.strict_types'));
    }

    public function testLoadRegistersNormalizersStrictTypesWithOverriddenValue(): void
    {
        $container = $this->loadExtension([
            [
                'normalizers' => [
                    'features' => [
                        'strict_types' => false,
                    ],
                ],
            ],
        ]);

        $this->assertFalse($container->getParameter('buildable_serializer.normalizers.features.strict_types'));
    }

    public function testLoadRegistersDenormalizersParameters(): void
    {
        $container = $this->loadExtension([
            ['denormalizers' => ['paths' => ["App\DTO" => '/tmp']]],
        ]);

        $this->assertTrue($container->hasParameter('buildable_serializer.denormalizers'));
        $this->assertTrue($container->hasParameter('buildable_serializer.denormalizers.paths'));
        $this->assertSame(
            ["App\DTO" => ['path' => '/tmp', 'exclude' => null]],
            $container->getParameter('buildable_serializer.denormalizers.paths'),
        );
    }

    public function testLoadRegistersEmptyDenormalizersPathsByDefault(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.denormalizers.paths'));
        $this->assertSame([], $container->getParameter('buildable_serializer.denormalizers.paths'));
    }

    public function testLoadRegistersDenormalizersFeatureParameters(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.denormalizers.features'));

        $features = $container->getParameter('buildable_serializer.denormalizers.features');
        $this->assertIsArray($features);
        $this->assertArrayHasKey('groups', $features);
        // Denormalizers only have the groups feature
        $this->assertArrayNotHasKey('max_depth', $features);
        $this->assertArrayNotHasKey('circular_reference', $features);
        $this->assertArrayNotHasKey('skip_null_values', $features);
    }

    public function testLoadRegistersDenormalizersFeatureParametersWithDefaultValues(): void
    {
        $container = $this->loadExtension([[]]);

        $features = $container->getParameter('buildable_serializer.denormalizers.features');
        $this->assertTrue($features['groups']);
    }

    public function testLoadRegistersDenormalizersFeatureFlatAliases(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.denormalizers.features.groups'));
    }

    public function testLoadRegistersDenormalizersFeatureWithOverriddenValues(): void
    {
        $container = $this->loadExtension([
            [
                'denormalizers' => [
                    'features' => [
                        'groups' => false,
                    ],
                ],
            ],
        ]);

        $features = $container->getParameter('buildable_serializer.denormalizers.features');
        $this->assertFalse($features['groups']);

        // Flat alias must match
        $this->assertFalse($container->getParameter('buildable_serializer.denormalizers.features.groups'));
    }

    public function testLoadRegistersDenormalizersStrictTypesParameter(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.denormalizers.features.strict_types'));
        $this->assertTrue($container->getParameter('buildable_serializer.denormalizers.features.strict_types'));
    }

    public function testLoadRegistersDenormalizersStrictTypesWithOverriddenValue(): void
    {
        $container = $this->loadExtension([
            [
                'denormalizers' => [
                    'features' => [
                        'strict_types' => false,
                    ],
                ],
            ],
        ]);

        $this->assertFalse($container->getParameter('buildable_serializer.denormalizers.features.strict_types'));
    }

    public function testLoadRegistersNormalizerGeneratorService(): void
    {
        $container = $this->loadExtensionForServices([[]]);

        $this->assertTrue(
            $container->hasDefinition("RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator"),
            'NormalizerGenerator service should be registered.',
        );
    }

    public function testLoadRegistersMetadataFactoryService(): void
    {
        $container = $this->loadExtensionForServices([[]]);

        $this->assertTrue(
            $container->hasDefinition("RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory"),
            'MetadataFactory service should be registered.',
        );
    }

    public function testNormalizersAndDenormalizersAreIndependent(): void
    {
        $container = $this->loadExtension([
            [
                'normalizers' => [
                    'paths' => ["App\Model" => '/tmp/src/Model'],
                    'features' => ['groups' => false, 'strict_types' => false],
                ],
                'denormalizers' => [
                    'paths' => ["App\DTO" => '/tmp/src/DTO'],
                    'features' => ['groups' => true, 'strict_types' => true],
                ],
            ],
        ]);

        // Normalizers
        $this->assertSame(
            ["App\Model" => ['path' => '/tmp/src/Model', 'exclude' => null]],
            $container->getParameter('buildable_serializer.normalizers.paths'),
        );
        $this->assertFalse($container->getParameter('buildable_serializer.normalizers.features.groups'));
        $this->assertFalse($container->getParameter('buildable_serializer.normalizers.features.strict_types'));

        // Denormalizers
        $this->assertSame(
            ["App\DTO" => ['path' => '/tmp/src/DTO', 'exclude' => null]],
            $container->getParameter('buildable_serializer.denormalizers.paths'),
        );
        $this->assertTrue($container->getParameter('buildable_serializer.denormalizers.features.groups'));
        $this->assertTrue($container->getParameter('buildable_serializer.denormalizers.features.strict_types'));
    }
}
