<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Discovery;

use BuildableSerializerBundle\Attribute\Serializable;

#[Serializable]
final class AnotherSerializableModel
{
    public string $title = '';
}
