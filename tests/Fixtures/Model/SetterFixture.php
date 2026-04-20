<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Fixture that exposes its state exclusively through public setter methods.
 *
 * Used by the denormalizer tests to verify that the `SETTER` mutator strategy
 * is correctly detected and that the generated `populate()` method emits
 * `$object->setX($value)` calls for each writable property.
 *
 * Notes:
 *   - The constructor takes no arguments, so instantiation falls through to
 *     `new SetterFixture()` and every field is populated via a setter.
 *   - `$name` is discovered through the getter-based accessor path and
 *     written via `setName(string $name): void` — a plain void setter.
 *   - `$age` uses a setter that returns `self`, exercising the branch in
 *     {@see \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory::isValidMutatorMethod()}
 *     that accepts `self`/`static`/owning-class return types as setters.
 *   - `$email` is nullable; the generated code must use
 *     `extractNullableString()` for this field.
 */
final class SetterFixture
{
    private string $name = '';

    private int $age = 0;

    private ?string $email = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Plain void setter — the canonical shape recognised by the mutator
     * discovery logic.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Self-returning setter. Some codebases prefer this style to enable
     * method chaining; it must still be classified as a SETTER (not a WITHER)
     * because the returned instance is `$this` and no new object is created.
     */
    public function setAge(int $age): self
    {
        $this->age = $age;

        return $this;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }
}
