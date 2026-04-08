<?php

declare(strict_types=1);

namespace BuildableSerializerBundle;

use BuildableSerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * BuildableSerializerBundle provides build-time optimized normalizer generation
 * for the Symfony Serializer component.
 *
 * By generating PHP normalizer classes at build/cache-warm time, this bundle
 * eliminates the runtime overhead of reflection-based normalization and enables
 * AOT (ahead-of-time) optimizations for high-throughput serialization workloads.
 */
class BuildableSerializerBundle extends Bundle
{
    /**
     * Registers compiler passes that wire generated normalizers into the
     * Symfony Serializer service at container compile time.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Priority -1000 ensures this pass runs last in TYPE_BEFORE_OPTIMIZATION,
        // after every bundle extension and every other compiler pass in this phase
        // has registered its services. This guarantees that our call to
        // findTaggedServiceIds('serializer.normalizer') captures a complete
        // snapshot — no normalizer added by a later pass can be missed when we
        // replace the TaggedIteratorArgument with the final flat Reference array.
        $container->addCompilerPass(
            new RegisterGeneratedNormalizersPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -1000,
        );
    }
}
