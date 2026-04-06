<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Fixtures\Discovery\Sub;

use Buildable\SerializerBundle\Attribute\Serializable;

#[Serializable]
final class NestedSerializableModel
{
    public int $id = 0;
}
