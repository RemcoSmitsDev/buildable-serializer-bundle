<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Generator;

final class GeneratedNormalizerInfo implements \Stringable
{
    public function __construct(
        private readonly string $fqcn,
        private readonly string $filePath,
    ) {}

    public function __toString(): string
    {
        return sprintf('GeneratedNormalizerInfo(%s => %s)', $this->fqcn, $this->filePath);
    }
}
