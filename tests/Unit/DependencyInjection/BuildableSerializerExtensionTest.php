<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Unit\DependencyInjection;

use BuildableSerializerBundle\DependencyInjection\BuildableSerializerExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \BuildableSerializerBundle\DependencyInjection\BuildableSerializerExtension
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
        $container->setParameter('kernel.cache_dir', '/tmp/project/var/cache/test');

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

    public function testLoadRegistersParameters(): void
    {
        $container = $this->loadExtension([
            ['paths' => ["App\Model" => '/tmp']],
        ]);

        $this->assertTrue($container->hasParameter('buildable_serializer.paths'));
        $this->assertSame(
            ["App\Model" => ['path' => '/tmp', 'exclude' => null]],
            $container->getParameter('buildable_serializer.paths'),
        );
    }

    public function testLoadRegistersEmptyPathsByDefault(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.paths'));
        $this->assertSame([], $container->getParameter('buildable_serializer.paths'));
    }

    public function testLoadRegistersMultiplePaths(): void
    {
        $container = $this->loadExtension([
            [
                'paths' => [
                    "App\Model" => '/tmp/src/Model',
                    "App\Entity" => '/tmp/src/Entity',
                ],
            ],
        ]);

        $paths = $container->getParameter('buildable_serializer.paths');

        $this->assertCount(2, $paths);
        $this->assertSame(['path' => '/tmp/src/Model', 'exclude' => null], $paths["App\Model"]);
        $this->assertSame(['path' => '/tmp/src/Entity', 'exclude' => null], $paths["App\Entity"]);
    }

    public function testLoadRegistersDefaultCacheDir(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.cache_dir'));

        // The default value uses a kernel parameter placeholder that the
        // ContainerBuilder does not resolve until compile time, so we assert
        // that the stored value references the expected path fragment.
        $cacheDir = $container->getParameter('buildable_serializer.cache_dir');
        $this->assertIsString($cacheDir);
        $this->assertStringContainsString('buildable_serializer', $cacheDir);
    }

    public function testLoadRegistersCustomCacheDir(): void
    {
        $container = $this->loadExtension([
            ['cache_dir' => '/custom/cache/dir'],
        ]);

        $this->assertSame('/custom/cache/dir', $container->getParameter('buildable_serializer.cache_dir'));
    }

    public function testLoadRegistersGeneratedNamespace(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.generated_namespace'));

        $namespace = $container->getParameter('buildable_serializer.generated_namespace');
        $this->assertIsString($namespace);
        $this->assertNotEmpty($namespace);
    }

    public function testLoadRegistersCustomGeneratedNamespace(): void
    {
        $container = $this->loadExtension([
            ['generated_namespace' => "My\Custom\Namespace"],
        ]);

        $this->assertSame("My\Custom\Namespace", $container->getParameter('buildable_serializer.generated_namespace'));
    }

    public function testLoadRegistersFeatureParameters(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.features'));

        $features = $container->getParameter('buildable_serializer.features');
        $this->assertIsArray($features);
        $this->assertArrayHasKey('groups', $features);
        $this->assertArrayHasKey('max_depth', $features);
        $this->assertArrayHasKey('circular_reference', $features);
        $this->assertArrayHasKey('name_converter', $features);
        $this->assertArrayHasKey('skip_null_values', $features);
    }

    public function testLoadRegistersFeatureParametersWithDefaultValues(): void
    {
        $container = $this->loadExtension([[]]);

        $features = $container->getParameter('buildable_serializer.features');
        $this->assertTrue($features['groups']);
        $this->assertTrue($features['max_depth']);
        $this->assertTrue($features['circular_reference']);
        $this->assertFalse($features['name_converter']);
        $this->assertTrue($features['skip_null_values']);
    }

    public function testLoadRegistersFeatureFlatAliases(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.features.groups'));
        $this->assertTrue($container->hasParameter('buildable_serializer.features.max_depth'));
        $this->assertTrue($container->hasParameter('buildable_serializer.features.circular_reference'));
        $this->assertTrue($container->hasParameter('buildable_serializer.features.name_converter'));
        $this->assertTrue($container->hasParameter('buildable_serializer.features.skip_null_values'));
    }

    public function testLoadRegistersFeatureWithOverriddenValues(): void
    {
        $container = $this->loadExtension([
            [
                'features' => [
                    'groups' => false,
                    'max_depth' => false,
                    'circular_reference' => true,
                    'name_converter' => false,
                    'skip_null_values' => true,
                ],
            ],
        ]);

        $features = $container->getParameter('buildable_serializer.features');
        $this->assertFalse($features['groups']);
        $this->assertFalse($features['max_depth']);
        $this->assertTrue($features['circular_reference']);
        $this->assertFalse($features['name_converter']);
        $this->assertTrue($features['skip_null_values']);

        // Flat aliases must match
        $this->assertFalse($container->getParameter('buildable_serializer.features.groups'));
        $this->assertFalse($container->getParameter('buildable_serializer.features.max_depth'));
        $this->assertTrue($container->getParameter('buildable_serializer.features.circular_reference'));
    }

    public function testLoadRegistersFeatureNameConverterCanBeEnabled(): void
    {
        $container = $this->loadExtension([
            ['features' => ['name_converter' => true]],
        ]);

        $features = $container->getParameter('buildable_serializer.features');
        $this->assertTrue($features['name_converter']);
        $this->assertTrue($container->getParameter('buildable_serializer.features.name_converter'));
    }

    public function testLoadRegistersGenerationParameters(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertTrue($container->hasParameter('buildable_serializer.generation'));

        $generation = $container->getParameter('buildable_serializer.generation');
        $this->assertIsArray($generation);
        $this->assertArrayNotHasKey('psr4', $generation);
        $this->assertArrayHasKey('strict_types', $generation);
    }

    public function testLoadRegistersGenerationParametersWithDefaultValues(): void
    {
        $container = $this->loadExtension([[]]);

        $generation = $container->getParameter('buildable_serializer.generation');
        $this->assertTrue($generation['strict_types']);
    }

    public function testLoadRegistersGenerationFlatAliases(): void
    {
        $container = $this->loadExtension([[]]);

        $this->assertFalse($container->hasParameter('buildable_serializer.generation.psr4'));
        $this->assertTrue($container->hasParameter('buildable_serializer.generation.strict_types'));
    }

    public function testLoadRegistersGenerationWithOverriddenValues(): void
    {
        $container = $this->loadExtension([
            [
                'generation' => [
                    'strict_types' => false,
                ],
            ],
        ]);

        $generation = $container->getParameter('buildable_serializer.generation');
        $this->assertFalse($generation['strict_types']);
    }

    public function testLoadRegistersNormalizerGeneratorService(): void
    {
        $container = $this->loadExtensionForServices([[]]);

        $this->assertTrue(
            $container->hasDefinition("BuildableSerializerBundle\Generator\NormalizerGenerator"),
            'NormalizerGenerator service should be registered.',
        );
    }

    public function testLoadRegistersMetadataFactoryService(): void
    {
        $container = $this->loadExtensionForServices([[]]);

        $this->assertTrue(
            $container->hasDefinition("BuildableSerializerBundle\Metadata\MetadataFactory"),
            'MetadataFactory service should be registered.',
        );
    }
}
