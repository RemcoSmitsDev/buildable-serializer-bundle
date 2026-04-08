<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Model;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Comprehensive user fixture model used across unit, integration, and functional tests.
 *
 * Covers the following metadata features:
 *   - Promoted constructor parameters (readonly)
 *   - Public mutable properties
 *   - Getter methods (get*, is*, has*)
 *   - #[Groups] on properties and getters
 *   - #[Ignore] to exclude a property
 *   - #[SerializedName] to override the serialized key
 *   - #[MaxDepth] on a nested-object property
 *   - Nullable types
 *   - Nested object reference (AddressFixture)
 *   - Typed collection (TagFixture[])
 */
class UserFixture
{
    /**
     * The user's unique identifier.
     * Belongs to both "user:read" and "user:write" groups.
     */
    #[Groups(['user:read', 'user:write'])]
    #[SerializedName('id')]
    public readonly int $id;

    /**
     * The user's display name.
     * Available in both read and write contexts.
     */
    #[Groups(['user:read', 'user:write'])]
    public readonly string $name;

    /**
     * The user's e-mail address.
     * Read-only group; write operations should not expose the raw e-mail.
     */
    #[Groups(['user:read'])]
    #[SerializedName('email_address')]
    public readonly string $email;

    /**
     * Optional biography text.
     * Only included in the "user:detail" group.
     */
    #[Groups(['user:detail'])]
    public ?string $biography = null;

    /**
     * The user's primary address.
     * Nested object – requires recursive normalizer delegation.
     * Max depth of 2 prevents infinite recursion in deeply nested structures.
     */
    #[Groups(['user:read', 'user:detail'])]
    #[MaxDepth(2)]
    public ?AddressFixture $address = null;

    /**
     * Tags associated with this user.
     * Typed collection of TagFixture objects (array<TagFixture>).
     */
    #[Groups(['user:read', 'user:detail'])]
    public array $tags = [];

    /**
     * Internal password hash.
     * Must NEVER appear in any serialized output.
     */
    #[Ignore]
    public string $passwordHash = '';

    /**
     * Raw internal notes visible only to administrators.
     * Excluded from all standard serialization groups.
     */
    #[Groups(['admin:read'])]
    public ?string $adminNotes = null;

    /**
     * Score used for internal ranking. No group constraints → always included
     * unless explicitly excluded by the context.
     */
    public int $score = 0;

    private bool $active = true;

    private \DateTimeImmutable $createdAt;

    private ?string $locale = null;

    public function __construct(int $id, string $name, string $email)
    {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Whether the user account is currently active.
     * Available in the "user:read" group.
     */
    #[Groups(['user:read'])]
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * The ISO 8601 creation timestamp.
     * Available in the "user:read" and "user:detail" groups.
     */
    #[Groups(['user:read', 'user:detail'])]
    #[SerializedName('created_at')]
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The user's locale string (e.g. "en_US").
     * Only included in the "user:detail" group; nullable.
     */
    #[Groups(['user:detail'])]
    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Whether the user has a primary address set.
     * Exposed as a boolean convenience flag; no group constraint.
     */
    public function hasAddress(): bool
    {
        return $this->address !== null;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function addTag(TagFixture $tag): void
    {
        $this->tags[] = $tag;
    }
}
