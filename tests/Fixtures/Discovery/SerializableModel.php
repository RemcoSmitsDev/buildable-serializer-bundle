<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Discovery;

use BuildableSerializerBundle\Attribute\Serializable;

#[Serializable]
final class SerializableModel
{
    public function __construct(
        public readonly string $name,
        public readonly int $value = 0,
    ) {}
}
