<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Discovery\Commands;

class CreateUserCommand
{
    public function __construct(
        public readonly string $username,
        public readonly string $email,
    ) {}
}
