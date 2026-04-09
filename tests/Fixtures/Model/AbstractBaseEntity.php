<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Abstract base entity used to test class-inheritance scenarios.
 *
 * Declares private promoted constructor parameters that are exposed only
 * through public getter methods. Concrete child classes that do NOT define
 * their own constructor will inherit this constructor, which is the exact
 * scenario that triggered the "Property ChildClass::$prop does not exist"
 * error in MetadataFactory::buildClassMetadata().
 */
abstract class AbstractBaseEntity
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
