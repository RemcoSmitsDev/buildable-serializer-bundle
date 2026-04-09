<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery;

/** Abstract class — must be skipped by discovery. */
abstract class AbstractModel
{
    abstract public function getId(): int;
}
