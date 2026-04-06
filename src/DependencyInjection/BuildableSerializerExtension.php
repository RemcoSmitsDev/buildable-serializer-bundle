<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Registers the BuildableSerializerBundle's services and exposes the resolved
 * configuration as container parameters.
 *
 * Parameters registered:
 *   - buildable_serializer.cache_dir            (string)
 *   - buildable_serializer.generated_namespace  (string)
 *   - buildable_serializer.paths                (array<string,string>)
 *   - buildable_serializer.features             (array{...})
 *   - buildable_serializer.features.groups      (bool)
 *   - buildable_serializer.features.max_depth   (bool)
 *   - buildable_serializer.features.circular_reference (bool)
 *   - buildable_serializer.features.name_converter     (bool)
 *   - buildable_serializer.features.skip_null_values   (bool)
 *   - buildable_serializer.generation           (array{...})
 *   - buildable_serializer.generation.strict_types     (bool)
 *   - buildable_serializer.generation.add_generated_tag (bool)
 */
final class BuildableSerializerExtension extends Extension
{
    /**
     * The container parameter prefix used for every value exported by this bundle.
     */
    private const PARAMETER_PREFIX = "buildable_serializer";

    /**
     * {@inheritDoc}
     *
     * Processes the user's configuration, registers it as container parameters,
     * and loads the bundle's service definitions from config/services.yaml.
     *
     * @param array<int|string, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();

        /** @var array{
         *     cache_dir: string,
         *     generated_namespace: string,
         *     paths: array<string, string>,
         *     features: array{groups: bool, max_depth: bool, circular_reference: bool, name_converter: bool, skip_null_values: bool},
         *     generation: array{strict_types: bool, add_generated_tag: bool}
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
     *     paths: array<string, string>,
     *     features: array{groups: bool, max_depth: bool, circular_reference: bool, name_converter: bool, skip_null_values: bool},
     *     generation: array{strict_types: bool, add_generated_tag: bool}
     * } $config
     */
    private function registerParameters(
        ContainerBuilder $container,
        array $config,
    ): void {
        $prefix = self::PARAMETER_PREFIX;

        // ---- Top-level scalar parameters ----------------------------------------
        $container->setParameter("{$prefix}.cache_dir", $config["cache_dir"]);
        $container->setParameter(
            "{$prefix}.generated_namespace",
            $config["generated_namespace"],
        );
        $container->setParameter("{$prefix}.paths", $config["paths"]);

        // ---- Structured sub-tree parameters (whole arrays) ----------------------
        $container->setParameter("{$prefix}.features", $config["features"]);
        $container->setParameter("{$prefix}.generation", $config["generation"]);

        // ---- Convenience flat aliases for frequently-used nested values ----------
        $container->setParameter(
            "{$prefix}.features.groups",
            $config["features"]["groups"],
        );
        $container->setParameter(
            "{$prefix}.features.max_depth",
            $config["features"]["max_depth"],
        );
        $container->setParameter(
            "{$prefix}.features.circular_reference",
            $config["features"]["circular_reference"],
        );
        $container->setParameter(
            "{$prefix}.features.name_converter",
            $config["features"]["name_converter"],
        );
        $container->setParameter(
            "{$prefix}.features.skip_null_values",
            $config["features"]["skip_null_values"],
        );

        $container->setParameter(
            "{$prefix}.generation.strict_types",
            $config["generation"]["strict_types"],
        );
        $container->setParameter(
            "{$prefix}.generation.add_generated_tag",
            $config["generation"]["add_generated_tag"],
        );
    }

    /**
     * Load service definitions from the bundle's config/services.yaml.
     */
    private function loadServices(ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(\dirname(__DIR__, 2) . "/config"),
        );

        $loader->load("services.yaml");
    }

    /**
     * {@inheritDoc}
     *
     * Returns the recommended configuration key used in the application's
     * config files (e.g. config/packages/buildable_serializer.yaml).
     */
    public function getAlias(): string
    {
        return "buildable_serializer";
    }
}
