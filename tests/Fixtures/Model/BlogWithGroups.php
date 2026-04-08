<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Model;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

class BlogWithGroups
{
    #[Groups(['blog:read', 'blog:list'])]
    public int $id;

    #[Groups(['blog:read', 'blog:list'])]
    public string $title;

    #[Groups(['blog:read'])]
    public string $content;

    #[Ignore]
    public string $internalField = 'ignored';

    #[SerializedName('author_name')]
    #[Groups(['blog:read'])]
    public string $authorName = 'Test Author';

    public function __construct(int $id, string $title, string $content)
    {
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
    }
}
