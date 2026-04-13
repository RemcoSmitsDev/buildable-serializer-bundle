<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;

/**
 * Fixture class for testing the #[Context] attribute support.
 *
 * This class demonstrates various uses of the Context attribute:
 * - Common context (applied to both normalization and denormalization)
 * - Normalization-specific context
 * - Denormalization-specific context
 * - Group-specific context (context applied only for specific groups)
 * - Multiple Context attributes on the same property
 */
class BlogWithContext
{
    #[Groups(['blog:read', 'blog:list'])]
    private int $id;

    #[Groups(['blog:read', 'blog:list'])]
    private string $title;

    /**
     * Property with normalization context - custom date format for serialization.
     */
    #[Groups(['blog:read', 'blog:list']), Context(normalizationContext: [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
    private DateTimeImmutable $createdAt;

    /**
     * Property with denormalization context - custom date format for deserialization.
     */
    #[Groups(['blog:read']), Context(denormalizationContext: [DateTimeNormalizer::FORMAT_KEY => 'd/m/Y H:i:s'])]
    private DateTimeImmutable $updatedAt;

    /**
     * Property with common context - applied to both normalization and denormalization.
     */
    #[Groups(['blog:read']), Context(context: [DateTimeNormalizer::FORMAT_KEY => 'c'])]
    private DateTimeImmutable $publishedAt;

    /**
     * Nested object with context.
     */
    #[Groups(['blog:read']), Context(normalizationContext: ['custom_key' => 'custom_value'])]
    private Author $author;

    /**
     * Collection with context.
     *
     * @var TagFixture[]
     */
    #[Groups(['blog:read']), Context(normalizationContext: ['collection_context' => true])]
    private array $tags;

    /**
     * Property with group-specific context.
     * Different date formats for different serialization groups.
     */
    #[
        Groups(['blog:read', 'blog:list', 'blog:api']),
        Context(normalizationContext: [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d H:i:s'], groups: ['blog:read']),
        Context(normalizationContext: [DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'], groups: ['blog:list']),
        Context(normalizationContext: [DateTimeNormalizer::FORMAT_KEY => 'c'], groups: ['blog:api']),
    ]
    private DateTimeImmutable $scheduledAt;

    /**
     * Property with mixed unconditional and group-specific context.
     * The unconditional context is always applied, group-specific context only when group is active.
     */
    #[
        Groups(['blog:read', 'blog:list']),
        Context(normalizationContext: ['always_applied' => true]),
        Context(normalizationContext: ['only_for_read' => true], groups: ['blog:read']),
    ]
    private DateTimeImmutable $archivedAt;

    public function __construct(
        int $id,
        string $title,
        Author $author,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
        ?DateTimeImmutable $publishedAt = null,
        ?DateTimeImmutable $scheduledAt = null,
        ?DateTimeImmutable $archivedAt = null,
        array $tags = [],
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->author = $author;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable();
        $this->publishedAt = $publishedAt ?? new DateTimeImmutable();
        $this->scheduledAt = $scheduledAt ?? new DateTimeImmutable();
        $this->archivedAt = $archivedAt ?? new DateTimeImmutable();
        $this->tags = $tags;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPublishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getScheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getArchivedAt(): DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    /**
     * @return TagFixture[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}
