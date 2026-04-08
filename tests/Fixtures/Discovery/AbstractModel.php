<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Discovery;

use BuildableSerializerBundle\Attribute\Serializable;

/** Abstract class — must be skipped by discovery even with #[Serializable]. */
#[Serializable]
abstract class AbstractModel
{
    abstract public function getId(): int;
}
