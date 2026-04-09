<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands;

class DeleteUserCommand
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
