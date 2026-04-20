<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Compiler;

use RemcoSmitsDev\BuildableSerializerBundle\Discovery\ClassDiscoveryInterface;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerPathResolver;
use RemcoSmitsDev\BuildableSerializerBundle\Generator\Normalizer\NormalizerWriter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class RegisterGeneratedNormalizersPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private const PATHS_PARAM = 'buildable_serializer.normalizers.paths';
    private const NORMALIZER_TAG = 'serializer.normalizer';
    private const SERIALIZER_SERVICE = 'serializer';
    private const DEFAULT_PRIORITY = 200;

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasParameter(self::PATHS_PARAM) === false) {
            return;
        }

        /** @var array<string, string> $paths */
        $paths = $container->getParameter(self::PATHS_PARAM);

        if ($paths === []) {
            return;
        }

        /** @var NormalizerWriter $writer */
        $writer = $container->get(NormalizerWriter::class);

        /** @var NormalizerPathResolver $pathResolver */
        $pathResolver = $container->get(NormalizerPathResolver::class);

        /** @var ClassDiscoveryInterface $discovery */
        $discovery = $container->get(ClassDiscoveryInterface::class);

        $metadataCollection = $discovery->discoverClasses();

        if ($metadataCollection === []) {
            return;
        }

        /** @var array<string, string> $classmap  fqcn => absolute file path */
        $classmap = [];

        foreach ($metadataCollection as $classMetadata) {
            $fqcn = $pathResolver->resolveNormalizerFqcn($classMetadata);
            $filePath = $writer->write($classMetadata);

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
