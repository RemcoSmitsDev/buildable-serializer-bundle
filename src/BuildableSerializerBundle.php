<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle;

use RemcoSmitsDev\BuildableSerializerBundle\DependencyInjection\Compiler\RegisterGeneratedNormalizersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class BuildableSerializerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterGeneratedNormalizersPass());
    }
}
