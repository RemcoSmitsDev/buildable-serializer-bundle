<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines and validates the configuration tree for the BuildableSerializerBundle.
 *
 * Example YAML configuration:
 *
 *     buildable_serializer:
 *         cache_dir: '%kernel.project_dir%/var/buildable_serializer'
 *         generated_namespace: 'BuildableSerializer\Generated'
 *         paths:
 *             # Directory mode: scans all PHP files recursively
 *             'App\Model': '%kernel.project_dir%/src/Model'
 *             # Glob mode: scans only files matching the filename pattern
 *             'App\Command': '%kernel.project_dir%/src/Command/*Command.php'
 *         features:
 *             groups: true
 *             max_depth: true
 *             circular_reference: true
 *             name_converter: false
 *             skip_null_values: true
 *         generation:
 *             strict_types: true
 *
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('buildable_serializer');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
            ->scalarNode('cache_dir')
            ->defaultValue('%kernel.project_dir%/var/buildable_serializer')
            ->info('Directory where generated normalizer PHP files will be written.')
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('generated_namespace')
            ->defaultValue("BuildableSerializer\Generated")
            ->info('Root PHP namespace used for all generated normalizer classes.')
            ->cannotBeEmpty()
            ->end()
            ->arrayNode('paths')
            ->info(
                'PSR-4 map of namespace-prefix => directory or glob pattern. '
                . 'Directory paths scan all *.php files recursively. '
                . 'Glob patterns (containing *, ?, or [) filter files by name (e.g. "src/**/*Command.php").',
            )
            ->useAttributeAsKey('namespace')
            ->scalarPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('features')
            ->info('Toggle individual serializer features in the generated normalizers.')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('groups')
            ->defaultTrue()
            ->info('Emit group-filtering logic in generated normalizers. '
            . 'When false, group context keys are ignored entirely.')
            ->end()
            ->booleanNode('max_depth')
            ->defaultTrue()
            ->info('Emit max-depth checking logic in generated normalizers. '
            . 'Allows limiting the depth of nested-object serialization.')
            ->end()
            ->booleanNode('circular_reference')
            ->defaultTrue()
            ->info('Emit circular-reference detection logic in generated normalizers.')
            ->end()
            ->booleanNode('name_converter')
            ->defaultFalse()
            ->info('Respect a name converter service when mapping PHP property ' . 'names to serialized keys.')
            ->end()
            ->booleanNode('skip_null_values')
            ->defaultTrue()
            ->info('Emit logic to skip null-valued properties when the '
            . '"skip_null_values" context key is set to true.')
            ->end()
            ->end()
            ->end()
            ->arrayNode('generation')
            ->info('Options controlling how the PHP source files are generated.')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('strict_types')
            ->defaultTrue()
            ->info('Prepend "declare(strict_types=1);" to every generated file.')
            ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
