<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Discovery\Sub;

use BuildableSerializerBundle\Attribute\Serializable;

#[Serializable]
final class NestedSerializableModel
{
    public int $id = 0;
}
