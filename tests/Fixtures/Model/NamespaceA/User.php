<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model\NamespaceA;

final class User
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
