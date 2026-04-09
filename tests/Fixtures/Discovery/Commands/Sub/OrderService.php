<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands\Sub;

class OrderService
{
    public function processOrder(int $orderId): void {}
}
