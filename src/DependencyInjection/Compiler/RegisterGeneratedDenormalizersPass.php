<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Compiler;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerPathResolver;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\DenormalizerWriter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass that:
 *
 *   1. Discovers every domain class configured under
 *      `buildable_serializer.denormalizers.paths`.
 *   2. Uses {@see DenormalizerWriter} to emit a dedicated, generated
 *      denormalizer PHP file for each discovered class.
 *   3. Registers every generated denormalizer as a private service tagged
 *      with `serializer.denormalizer` at a high priority, so Symfony's
 *      serializer chain picks them up before the built-in reflection-based
 *      {@see \Symfony\Component\Serializer\Normalizer\ObjectNormalizer}.
 *   4. Replaces the `$denormalizers` argument of the `serializer` service
 *      (argument index 1) with a priority-sorted iterator of every tagged
 *      denormalizer, mirroring the way Symfony itself wires its serializer
 *      internally.
 *
 * This pass is the denormalizer counterpart of
 * {@see RegisterGeneratedNormalizersPass}.
 */
final class RegisterGeneratedDenormalizersPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private const PATHS_PARAM = 'buildable_serializer.denormalizers.paths';
    private const DISCOVERY_SERVICE = 'buildable_serializer.discovery.denormalizers';
    private const DENORMALIZER_TAG = 'serializer.denormalizer';
    private const NORMALIZER_TAG = 'serializer.normalizer';
    private const SERIALIZER_SERVICE = 'serializer';
    private const DEFAULT_PRIORITY = 200;

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasParameter(self::PATHS_PARAM) === false) {
            return;
        }

        /** @var array<string, string|array{path: string, exclude?: string|string[]|null}> $paths */
        $paths = $container->getParameter(self::PATHS_PARAM);

        if ($paths === []) {
            return;
        }

        /** @var DenormalizerWriter $writer */
        $writer = $container->get(DenormalizerWriter::class);

        /** @var DenormalizerPathResolver $pathResolver */
        $pathResolver = $container->get(DenormalizerPathResolver::class);

        /** @var ClassDiscoveryInterface $discovery */
        $discovery = $container->get(self::DISCOVERY_SERVICE);

        $metadataCollection = $discovery->discoverClasses();

        if ($metadataCollection === []) {
            return;
        }

        foreach ($metadataCollection as $classMetadata) {
            $fqcn = $pathResolver->resolveDenormalizerFqcn($classMetadata);
            $filePath = $writer->write($classMetadata);

            $definition = new Definition($fqcn);
            $definition->setPublic(false);
            $definition->setAutowired(false);
            $definition->setAutoconfigured(false);
            $definition->addTag(self::NORMALIZER_TAG, ['priority' => self::DEFAULT_PRIORITY]);
            $definition->setFile($filePath);
            $container->setDefinition(self::DENORMALIZER_TAG . '.' . $fqcn, $definition);
        }

        $serializerDef = $container->getDefinition(self::SERIALIZER_SERVICE);

        $serializerDef->replaceArgument(0, $this->findAndSortTaggedServices(self::NORMALIZER_TAG, $container));
    }
}
