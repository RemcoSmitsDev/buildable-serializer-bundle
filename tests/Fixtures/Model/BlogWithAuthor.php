<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Tests\Fixtures\Model;

class BlogWithAuthor
{
    public function __construct(
        private int $id,
        private string $title,
        private Author $author,
        private ?Author $coAuthor = null,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function getCoAuthor(): ?Author
    {
        return $this->coAuthor;
    }
}
