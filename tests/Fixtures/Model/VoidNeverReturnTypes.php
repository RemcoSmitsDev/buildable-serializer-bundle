<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Fixture model with methods that return void or never.
 *
 * These methods should NOT be treated as getters even if they
 * match the getter name pattern (get*, is*, has*) because they
 * don't return a usable value.
 */
class VoidNeverReturnTypes
{
    private int $id;
    private string $name;
    private bool $active = true;
    private bool $initialized = false;

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    /**
     * Valid getter - should be detected as a property.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Valid getter - should be detected as a property.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Valid is* getter - should be detected as a property.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Valid has* getter - should be detected as a property.
     */
    public function hasName(): bool
    {
        return $this->name !== '';
    }

    /**
     * Virtual property - returns bool but has NO backing property.
     * Should be detected as a getter and exposed as "empty" property.
     * This is a computed/derived value with no corresponding class property.
     */
    public function isEmpty(): bool
    {
        return $this->name === '';
    }

    /**
     * Returns void - should NOT be detected as a getter.
     * Even though it matches the "get*" pattern.
     */
    public function getReady(): void
    {
        $this->initialized = true;
    }

    /**
     * Returns void - should NOT be detected as a getter.
     * Even though it matches the "is*" pattern.
     */
    public function isInitializing(): void
    {
        $this->initialized = true;
    }

    /**
     * Returns void - should NOT be detected as a getter.
     * Even though it matches the "has*" pattern.
     */
    public function hasLoaded(): void
    {
        $this->initialized = true;
    }

    /**
     * Returns never - should NOT be detected as a getter.
     * Even though it matches the "get*" pattern.
     */
    public function getError(): never
    {
        throw new \RuntimeException('This method always throws');
    }

    /**
     * Returns never - should NOT be detected as a getter.
     * Even though it matches the "is*" pattern.
     */
    public function isFatal(): never
    {
        throw new \LogicException('Fatal error - this method never returns');
    }

    /**
     * Returns never - should NOT be detected as a getter.
     * Even though it matches the "has*" pattern.
     */
    public function hasFailed(): never
    {
        exit(1);
    }

    /**
     * Setter returning void - should NOT be detected.
     * This doesn't match a getter pattern anyway.
     */
    public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}
