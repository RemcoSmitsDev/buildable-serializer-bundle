<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Model;

class ScalarTypesFixture
{
    public function __construct(
        private string $name,
        private mixed $meta,
        private ?string $description = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getMeta(): mixed
    {
        return $this->meta;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
