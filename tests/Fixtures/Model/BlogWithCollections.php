<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Model;

class BlogWithCollections
{
    public function __construct(
        private int $id,
        private string $title,
        /** @var string[] */
        private array $tags = [],
        /** @var Author[] */
        private array $authors = [],
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @return Author[]
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }
}
