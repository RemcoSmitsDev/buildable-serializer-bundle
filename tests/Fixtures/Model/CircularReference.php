<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

class CircularReference
{
    private ?CircularReference $parent = null;
    private ?CircularReference $child = null;

    public function __construct(
        private string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getChild(): ?self
    {
        return $this->child;
    }

    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }

    public function setChild(?self $child): void
    {
        $this->child = $child;
    }
}
