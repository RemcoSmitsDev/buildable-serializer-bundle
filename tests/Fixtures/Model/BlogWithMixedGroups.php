<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fixture for testing group filtering with a mix of properties:
 * - Properties WITH groups (should serialize when their group matches or no groups specified)
 * - Properties WITHOUT groups (should only serialize when no groups specified in context)
 * - Ignored properties (should never serialize)
 */
class BlogWithMixedGroups
{
    /**
     * Property with groups - should serialize when 'blog:read' or 'blog:list' group is requested,
     * or when no groups are specified.
     */
    #[Groups(['blog:read', 'blog:list'])]
    public int $id;

    /**
     * Property with groups - should serialize when 'blog:read' or 'blog:list' group is requested,
     * or when no groups are specified.
     */
    #[Groups(['blog:read', 'blog:list'])]
    public string $title;

    /**
     * Property with groups - should serialize only when 'blog:read' group is requested,
     * or when no groups are specified.
     */
    #[Groups(['blog:read'])]
    public string $content;

    /**
     * Property WITHOUT groups - should ONLY serialize when no groups are specified in context.
     * When any group is specified (even if it doesn't match any property), this should be excluded.
     */
    public string $internalNote = 'internal data';

    /**
     * Another property WITHOUT groups - should ONLY serialize when no groups are specified.
     */
    #[SerializedName('debug_info')]
    public string $debugInfo = 'debug data';

    /**
     * Ignored property - should NEVER serialize regardless of groups.
     */
    #[Ignore]
    public string $secret = 'secret data';

    public function __construct(int $id, string $title, string $content)
    {
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
    }
}
