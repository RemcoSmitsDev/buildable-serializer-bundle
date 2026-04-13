<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Metadata;

/**
 * Holds context data for a property, populated from the
 * {@see \Symfony\Component\Serializer\Attribute\Context} attribute.
 *
 * A single property can have multiple PropertyContext instances when the
 * Context attribute is used multiple times with different groups.
 *
 * The Context attribute supports:
 * - Common context (applied to both normalization and denormalization)
 * - Normalization-specific context
 * - Denormalization-specific context
 * - Optional groups to conditionally apply the context
 */
final class PropertyContext
{
    /**
     * @param array<string, mixed> $context Common context applied to both normalization and denormalization.
     * @param array<string, mixed> $normalizationContext Context applied only during normalization.
     * @param array<string, mixed> $denormalizationContext Context applied only during denormalization.
     * @param string[] $groups Optional groups to conditionally apply this context.
     *                         When empty, the context is always applied.
     *                         When specified, the context is only applied if one of these groups is active.
     */
    public function __construct(
        private readonly array $context = [],
        private readonly array $normalizationContext = [],
        private readonly array $denormalizationContext = [],
        private readonly array $groups = [],
    ) {}

    /**
     * Returns the common context applied to both normalization and denormalization.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Returns the context to be merged during normalization.
     * This includes both the common context and normalization-specific context.
     *
     * @return array<string, mixed>
     */
    public function getNormalizationContext(): array
    {
        return array_merge($this->context, $this->normalizationContext);
    }

    /**
     * Returns the context to be merged during denormalization.
     * This includes both the common context and denormalization-specific context.
     *
     * @return array<string, mixed>
     */
    public function getDenormalizationContext(): array
    {
        return array_merge($this->context, $this->denormalizationContext);
    }

    /**
     * Returns the groups for which this context should be applied.
     * An empty array means the context is always applied.
     *
     * @return string[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Returns true if this context has no group restrictions and should always be applied.
     */
    public function isUnconditional(): bool
    {
        return $this->groups === [];
    }

    /**
     * Returns true if this context should be applied for the given active groups.
     *
     * @param string[] $activeGroups The currently active serialization groups.
     */
    public function isApplicableForGroups(array $activeGroups): bool
    {
        // If no groups are specified, the context always applies
        if ($this->groups === []) {
            return true;
        }

        // If no active groups, apply all contexts (Symfony's default behavior)
        if ($activeGroups === []) {
            return true;
        }

        // Check if any of the context's groups match the active groups
        foreach ($this->groups as $group) {
            if (in_array($group, $activeGroups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if this context has any normalization context to apply.
     */
    public function hasNormalizationContext(): bool
    {
        return $this->context !== [] || $this->normalizationContext !== [];
    }

    /**
     * Returns true if this context has any denormalization context to apply.
     */
    public function hasDenormalizationContext(): bool
    {
        return $this->context !== [] || $this->denormalizationContext !== [];
    }
}
