<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class BuildableSerializerExtension extends Extension
{
    private const PARAMETER_PREFIX = 'buildable_serializer';

    /** @inheritdoc */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();

        /** @var array{
         *     cache_dir: string,
         *     generated_namespace: string,
         *     paths: array<string, array{path: string, exclude: string|string[]|null}>,
         *     features: array{groups: bool, max_depth: bool, circular_reference: bool, name_converter: bool, skip_null_values: bool},
         *     generation: array{strict_types: bool}
         * } $config
         */
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerParameters($container, $config);

        $this->loadServices($container);
    }

    /**
     * Register every resolved configuration value as a container parameter so
     * that service definitions in services.yaml can reference them.
     *
     * @param array{
     *     cache_dir: string,
     *     generated_namespace: string,
     *     paths: array<string, array{path: string, exclude: string|string[]|null}>,
     *     features: array{groups: bool, max_depth: bool, circular_reference: bool, name_converter: bool, skip_null_values: bool},
     *     generation: array{strict_types: bool}
     * } $config
     */
    private function registerParameters(ContainerBuilder $container, array $config): void
    {
        $prefix = self::PARAMETER_PREFIX;

        // ---- Top-level scalar parameters ----------------------------------------
        $container->setParameter("{$prefix}.cache_dir", $config['cache_dir']);
        $container->setParameter("{$prefix}.generated_namespace", $config['generated_namespace']);
        $container->setParameter("{$prefix}.paths", $config['paths']);

        // ---- Structured sub-tree parameters (whole arrays) ----------------------
        $container->setParameter("{$prefix}.features", $config['features']);
        $container->setParameter("{$prefix}.generation", $config['generation']);

        // ---- Convenience flat aliases for frequently-used nested values ----------
        $container->setParameter("{$prefix}.features.groups", $config['features']['groups']);
        $container->setParameter("{$prefix}.features.max_depth", $config['features']['max_depth']);
        $container->setParameter("{$prefix}.features.circular_reference", $config['features']['circular_reference']);
        $container->setParameter("{$prefix}.features.name_converter", $config['features']['name_converter']);
        $container->setParameter("{$prefix}.features.skip_null_values", $config['features']['skip_null_values']);

        $container->setParameter("{$prefix}.generation.strict_types", $config['generation']['strict_types']);
    }

    /**
     * Load service definitions from the bundle's config/services.yaml.
     */
    private function loadServices(ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));

        $loader->load('services.yaml');
    }

    /** @inheritDoc */
    public function getAlias(): string
    {
        return 'buildable_serializer';
    }
}
