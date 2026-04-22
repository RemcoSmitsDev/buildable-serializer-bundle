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
    public const GENERATED_NAMESPACE = 'BuildableSerializerBundle\\Generated';

    /** @inheritdoc */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();

        /** @var array{
         *     normalizers: array{
         *         paths: array<string, array{path: string, exclude: string|string[]|null}>,
         *         features: array{groups: bool, max_depth: bool, circular_reference: bool, skip_null_values: bool, preserve_empty_objects: bool, context: bool, attributes: bool, strict_types: bool}
         *     },
         *     denormalizers: array{
         *         paths: array<string, array{path: string, exclude: string|string[]|null}>,
         *         features: array{groups: bool, attributes: bool, strict_types: bool}
         *     }
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
     *     normalizers: array{
     *         paths: array<string, array{path: string, exclude: string|string[]|null}>,
     *         features: array{groups: bool, max_depth: bool, circular_reference: bool, skip_null_values: bool, preserve_empty_objects: bool, context: bool, attributes: bool, strict_types: bool}
     *     },
     *     denormalizers: array{
     *         paths: array<string, array{path: string, exclude: string|string[]|null}>,
     *         features: array{groups: bool, attributes: bool, strict_types: bool}
     *     }
     * } $config
     */
    private function registerParameters(ContainerBuilder $container, array $config): void
    {
        $prefix = self::PARAMETER_PREFIX;

        // ---- Normalizers configuration ----------------------------------------
        $container->setParameter("{$prefix}.normalizers", $config['normalizers']);
        $container->setParameter("{$prefix}.normalizers.paths", $config['normalizers']['paths']);
        $container->setParameter("{$prefix}.normalizers.features", $config['normalizers']['features']);

        // Convenience flat aliases for normalizer feature flags
        $container->setParameter("{$prefix}.normalizers.features.groups", $config['normalizers']['features']['groups']);
        $container->setParameter(
            "{$prefix}.normalizers.features.max_depth",
            $config['normalizers']['features']['max_depth'],
        );
        $container->setParameter(
            "{$prefix}.normalizers.features.circular_reference",
            $config['normalizers']['features']['circular_reference'],
        );
        $container->setParameter(
            "{$prefix}.normalizers.features.skip_null_values",
            $config['normalizers']['features']['skip_null_values'],
        );
        $container->setParameter(
            "{$prefix}.normalizers.features.preserve_empty_objects",
            $config['normalizers']['features']['preserve_empty_objects'],
        );
        $container->setParameter(
            "{$prefix}.normalizers.features.context",
            $config['normalizers']['features']['context'],
        );
        $container->setParameter(
            "{$prefix}.normalizers.features.attributes",
            $config['normalizers']['features']['attributes'],
        );
        $container->setParameter(
            "{$prefix}.normalizers.features.strict_types",
            $config['normalizers']['features']['strict_types'],
        );

        // ---- Denormalizers configuration ----------------------------------------
        $container->setParameter("{$prefix}.denormalizers", $config['denormalizers']);
        $container->setParameter("{$prefix}.denormalizers.paths", $config['denormalizers']['paths']);
        $container->setParameter("{$prefix}.denormalizers.features", $config['denormalizers']['features']);

        // Convenience flat aliases for denormalizer feature flags
        $container->setParameter(
            "{$prefix}.denormalizers.features.groups",
            $config['denormalizers']['features']['groups'],
        );
        $container->setParameter(
            "{$prefix}.denormalizers.features.attributes",
            $config['denormalizers']['features']['attributes'],
        );
        $container->setParameter(
            "{$prefix}.denormalizers.features.strict_types",
            $config['denormalizers']['features']['strict_types'],
        );
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
