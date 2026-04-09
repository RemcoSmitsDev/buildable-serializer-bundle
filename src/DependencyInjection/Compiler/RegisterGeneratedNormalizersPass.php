<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Compiler;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\NormalizerGenerator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Serializer\Serializer;

final class RegisterGeneratedNormalizersPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private const CACHE_DIR_PARAM = 'buildable_serializer.cache_dir';
    private const NAMESPACE_PARAM = 'buildable_serializer.generated_namespace';
    private const PATHS_PARAM = 'buildable_serializer.paths';
    private const NORMALIZER_TAG = 'serializer.normalizer';
    private const SERIALIZER_SERVICE = 'serializer';
    private const DEFAULT_PRIORITY = 200;

    public function process(ContainerBuilder $container): void
    {
        foreach ([self::CACHE_DIR_PARAM, self::NAMESPACE_PARAM, self::PATHS_PARAM] as $param) {
            if ($container->hasParameter($param) === false) {
                return;
            }
        }

        /** @var NormalizerGenerator $generator */
        $generator = $container->get(NormalizerGenerator::class);

        /** @var ClassDiscoveryInterface $discovery */
        $discovery = $container->get(ClassDiscoveryInterface::class);

        $metadataCollection = $discovery->discoverClasses();

        if ($metadataCollection === []) {
            return;
        }

        /** @var array<string, string> $classmap  fqcn => absolute file path */
        $classmap = [];

        foreach ($metadataCollection as $classMetadata) {
            $fqcn = $generator->resolveNormalizerFqcn($classMetadata);
            $filePath = $generator->generateAndWrite($classMetadata);

            $definition = new Definition($fqcn);
            $definition->setPublic(false);
            $definition->setAutowired(false);
            $definition->setAutoconfigured(false);
            $definition->addTag('serializer.normalizer', ['priority' => self::DEFAULT_PRIORITY]);
            $definition->setFile($filePath);
            $container->setDefinition('serializer.normalizer.' . $fqcn, $definition);

            $classmap[$fqcn] = $filePath;
        }

        $serializerDef = $container->getDefinition(self::SERIALIZER_SERVICE);

        $serializerDef->replaceArgument(0, $this->findAndSortTaggedServices('serializer.normalizer', $container));
    }
}
