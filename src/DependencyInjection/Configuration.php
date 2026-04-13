<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines and validates the configuration tree for the BuildableSerializerBundle.
 *
 * Example YAML configuration:
 *
 *     buildable_serializer:
 *         normalizers:
 *             paths:
 *                 # Simple string: scans all PHP files recursively
 *                 'App\Model': '%kernel.project_dir%/src/Model'
 *
 *                 # With single exclude pattern
 *                 'App\Entity':
 *                     path: '%kernel.project_dir%/src/Entity'
 *                     exclude: '*Helper.php'
 *
 *                 # With multiple exclude patterns
 *                 'App\Command':
 *                     path: '%kernel.project_dir%/src/Command'
 *                     exclude:
 *                         - '*Helper.php'
 *                         - '*Test.php'
 *             features:
 *                 groups: true
 *                 max_depth: true
 *                 circular_reference: true
 *                 skip_null_values: true
 *                 context: true
 *                 strict_types: true
 *
 *         denormalizers:
 *             paths:
 *                 'App\DTO': '%kernel.project_dir%/src/DTO'
 *             features:
 *                 groups: true
 *                 strict_types: true
 *
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('buildable_serializer');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode->children()->append($this->buildNormalizersNode())->append($this->buildDenormalizersNode())->end();

        return $treeBuilder;
    }

    /**
     * Build the normalizers configuration node with all features.
     */
    private function buildNormalizersNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('normalizers');

        /** @var ArrayNodeDefinition $node */
        $node = $treeBuilder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
            ->append($this->buildPathsNode())
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
            ->booleanNode('skip_null_values')
            ->defaultTrue()
            ->info('Emit logic to skip null-valued properties when the '
            . '"skip_null_values" context key is set to true.')
            ->end()
            ->booleanNode('context')
            ->defaultTrue()
            ->info('Emit logic to merge property-specific context from #[Context] attributes '
            . 'when calling nested normalizers.')
            ->end()
            ->booleanNode('strict_types')
            ->defaultTrue()
            ->info('Prepend "declare(strict_types=1);" to every generated file.')
            ->end()
            ->end()
            ->end()
            ->end();

        return $node;
    }

    /**
     * Build the denormalizers configuration node with only the groups feature.
     */
    private function buildDenormalizersNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('denormalizers');

        /** @var ArrayNodeDefinition $node */
        $node = $treeBuilder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
            ->append($this->buildPathsNode())
            ->arrayNode('features')
            ->info('Toggle individual serializer features in the generated denormalizers.')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('groups')
            ->defaultTrue()
            ->info('Emit group-filtering logic in generated denormalizers. '
            . 'When false, group context keys are ignored entirely.')
            ->end()
            ->booleanNode('strict_types')
            ->defaultTrue()
            ->info('Prepend "declare(strict_types=1);" to every generated file.')
            ->end()
            ->end()
            ->end()
            ->end();

        return $node;
    }

    /**
     * Build the paths configuration node (shared between normalizers and denormalizers).
     */
    private function buildPathsNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('paths');

        /** @var ArrayNodeDefinition $node */
        $node = $treeBuilder->getRootNode();

        $node
            ->info(
                'PSR-4 map of namespace-prefix => path configuration. '
                . 'Value can be a string (directory) or an array with "path" and optional "exclude" keys.',
            )
            ->useAttributeAsKey('namespace')
            ->arrayPrototype()
            ->beforeNormalization()
            ->ifString()
            ->then(static fn(string $v): array => ['path' => $v])
            ->end()
            ->children()
            ->scalarNode('path')
            ->isRequired()
            ->cannotBeEmpty()
            ->info('Directory path or glob pattern to scan for PHP files.')
            ->end()
            ->variableNode('exclude')
            ->defaultNull()
            ->info(
                'Glob pattern(s) for files to exclude. Can be a string or array of strings (e.g. "*Helper.php" or ["*Helper.php", "*Test.php"]).',
            )
            ->validate()
            ->ifTrue(static fn($v): bool => $v !== null && !\is_string($v) && !\is_array($v))
            ->thenInvalid('The "exclude" option must be a string, an array of strings, or null.')
            ->end()
            ->validate()
            ->ifTrue(
                static fn($v): bool => (
                    \is_array($v)
                    && \count(array_filter($v, static fn($item): bool => !\is_string($item))) > 0
                ),
            )
            ->thenInvalid('The "exclude" option array must contain only strings.')
            ->end()
            ->end()
            ->end()
            ->end()
            ->defaultValue([]);

        return $node;
    }
}
