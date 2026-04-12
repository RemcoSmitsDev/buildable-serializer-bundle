<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NamespaceB;

final class User
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
