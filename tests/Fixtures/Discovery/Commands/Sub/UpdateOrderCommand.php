<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\Sub;

class UpdateOrderCommand
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $status,
    ) {}
}
