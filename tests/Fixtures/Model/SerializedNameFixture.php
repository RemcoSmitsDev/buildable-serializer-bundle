<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fixture exercising the `#[SerializedName]` key-aliasing behaviour of the
 * generated denormalizer.
 *
 * For each field that carries a serialized-name alias, the generator must
 * accept BOTH the alias and the original PHP property / parameter name in
 * the input payload. The primary (canonical) alias is the one declared via
 * the attribute; it takes precedence when both keys are present.
 *
 * ### Covered shapes
 *
 *   - Promoted constructor parameter with a `#[SerializedName]` attribute
 *     placed on the parameter (attached at runtime to the backing property):
 *     `$emailAddress` ↔ `"email_address"`.
 *   - Promoted constructor parameter with an alias that shares the PHP name
 *     (`$id` ↔ `"id"`): here the generator must NOT emit a redundant
 *     two-element candidate list — a single string is sufficient.
 *   - Optional promoted parameter with an alias and a default value
 *     (`$displayName` ↔ `"display_name"`, defaults to `null`).
 *   - Non-promoted public mutable property with a `#[SerializedName]`
 *     attribute placed on the property declaration, populated via the
 *     direct-property mutator strategy during the populate() phase
 *     (`$homePage` ↔ `"home_page"`).
 *
 * The fixture intentionally mixes constructor-populated and populate-phase
 * fields so integration tests can assert key-alias resolution in both
 * `construct()` and `populate()` branches of the generated code.
 */
final class SerializedNameFixture
{
    /**
     * Mutable public property populated via the PROPERTY mutator strategy
     * during the populate() phase. Uses `home_page` in the serialized
     * payload but exposes `$homePage` as the PHP property name.
     */
    #[SerializedName('home_page')]
    public ?string $homePage = null;

    public function __construct(
        // No alias: the serialized name equals the PHP name, so the
        // generator should fall through to its compact single-key code path.
        public readonly int $id,

        // Required, aliased. The payload may use either `"email_address"`
        // (canonical) or `"emailAddress"` (PHP-name fallback).
        #[SerializedName('email_address')]
        public readonly string $emailAddress,

        // Optional, aliased, with a default. The payload may use either
        // `"display_name"` (canonical) or `"displayName"` (PHP-name
        // fallback); when both are missing the default (`null`) applies.
        #[SerializedName('display_name')]
        public readonly ?string $displayName = null,
    ) {}
}
