<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Fixtures\Model;

class TagFixture
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
