<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Fixtures\Discovery;

use Buildable\SerializerBundle\Attribute\Serializable;

#[Serializable]
final class SerializableModel
{
    public function __construct(
        public readonly string $name,
        public readonly int $value = 0,
    ) {}
}
