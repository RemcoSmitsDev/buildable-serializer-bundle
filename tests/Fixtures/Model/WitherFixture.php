<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Immutable fixture that exposes its state exclusively through wither methods.
 *
 * Used by the denormalizer tests to verify that the `WITHER` mutator strategy
 * is correctly detected and that the generated `populate()` method emits
 * `$object = $object->withX($value)` reassignments for each writable property.
 *
 * Notes:
 *   - The constructor takes no required arguments, so instantiation falls
 *     through to `new WitherFixture()` and every field is populated via a
 *     wither during the population phase.
 *   - Every field is declared `readonly` so the only legal way to "mutate"
 *     the object is to produce a new instance — exactly the immutable
 *     pattern the wither strategy is designed for.
 *   - `withTitle()` returns `self`, `withBody()` returns `static`, and
 *     `withSlug()` returns the owning class explicitly. All three branches
 *     of the wither-return-type check in
 *     {@see \RemcoSmitsDev\BuildableSerializerBundle\Metadata\MetadataFactory::isValidMutatorMethod()}
 *     are therefore exercised by this fixture.
 *   - `$slug` is nullable, which means the generator must emit the
 *     `extractNullableString()` helper for that field.
 *
 * The generated populate() method for this class should look roughly like:
 *
 * ```php
 * if (array_key_exists('title', $data) && !isset($skip['title'])) {
 *     $object = $object->withTitle(
 *         $this->extractString($data, 'title', required: false, default: null, context: $context)
 *     );
 * }
 *
 * if (array_key_exists('body', $data) && !isset($skip['body'])) {
 *     $object = $object->withBody(
 *         $this->extractString($data, 'body', required: false, default: null, context: $context)
 *     );
 * }
 *
 * if (array_key_exists('slug', $data) && !isset($skip['slug'])) {
 *     $object = $object->withSlug(
 *         $this->extractNullableString($data, 'slug', required: false, default: null, context: $context)
 *     );
 * }
 * ```
 */
final class WitherFixture
{
    public function __construct(
        public readonly string $title = '',
        public readonly string $body = '',
        public readonly ?string $slug = null,
    ) {}

    /**
     * Wither returning `self` — produces a new instance with a different title.
     */
    public function withTitle(string $title): self
    {
        return new self($title, $this->body, $this->slug);
    }

    /**
     * Wither returning `static` — exercises the `static` return-type branch
     * of the wither detection in the metadata factory.
     */
    public function withBody(string $body): static
    {
        return new self($this->title, $body, $this->slug);
    }

    /**
     * Wither returning the owning class explicitly. Some codebases prefer
     * this style over `self`/`static` for clarity; the detection logic must
     * treat it identically.
     */
    public function withSlug(?string $slug): WitherFixture
    {
        return new self($this->title, $this->body, $slug);
    }
}
