<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery;

final class SerializableModel
{
    public function __construct(
        public readonly string $name,
        public readonly int $value = 0,
    ) {}
}
