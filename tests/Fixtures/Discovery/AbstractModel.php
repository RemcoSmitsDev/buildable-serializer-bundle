<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Fixtures\Discovery;

use Buildable\SerializerBundle\Attribute\Serializable;

/** Abstract class — must be skipped by discovery even with #[Serializable]. */
#[Serializable]
abstract class AbstractModel
{
    abstract public function getId(): int;
}
