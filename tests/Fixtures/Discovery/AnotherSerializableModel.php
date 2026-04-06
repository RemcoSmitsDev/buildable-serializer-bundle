<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Fixtures\Discovery;

use Buildable\SerializerBundle\Attribute\Serializable;

#[Serializable]
final class AnotherSerializableModel
{
    public string $title = '';
}
