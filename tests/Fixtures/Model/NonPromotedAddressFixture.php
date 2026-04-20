<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fixture that mirrors a real-world "non-promoted constructor + explicit
 * public properties" pattern frequently seen in Symfony applications.
 *
 * Instead of using promoted constructor parameters (PHP 8.0+ syntax), this
 * class declares its state as a list of `public` properties and then assigns
 * them inside a regular constructor body. All serializer attributes live on
 * the property declarations — the constructor signature only lists plain
 * parameter names.
 *
 * The denormalizer generator has to link each constructor parameter to its
 * same-named class property so that attributes placed on the property
 * (`#[SerializedName]`, `#[Groups]`, `#[Ignore]`, `#[MaxDepth]`, `#[Context]`)
 * apply to the corresponding constructor argument too. Without that linking,
 * a payload that used `postal_code` (the serialized name) would be treated as
 * a missing field because the generator would only look for `postalCode`
 * (the PHP parameter name).
 *
 * ### Covered attribute placements
 *
 *   - `#[SerializedName]` on a public property whose name matches a
 *     non-promoted constructor parameter (`$postalCode` ↔ `"postal_code"`).
 *   - `#[Groups]` on every public property so the generator can propagate
 *     group constraints onto the corresponding {@see ConstructorParameterMetadata}.
 *   - `#[Ignore]` on a property to verify that the flag is surfaced on the
 *     linked constructor parameter metadata even though the attribute lives
 *     on the property (`$internalCode`).
 *   - `#[MaxDepth]` on a property to verify that the max-depth limit is
 *     carried through to the linked constructor parameter (`$country`).
 *   - `#[Context]` on a property to verify that per-property contexts are
 *     collected for the linked constructor parameter (`$street`).
 *
 * The fixture is never used to assert normalizer behaviour — only the
 * denormalizer generator and the constructor-metadata extractor consume it.
 */
final class NonPromotedAddressFixture
{
    #[Groups(['address:read', 'user:read'])]
    #[Context(context: ['trim' => true])]
    public string $street;

    #[Groups(['address:read', 'user:read'])]
    public string $city;

    #[Groups(['address:read', 'user:read'])]
    #[SerializedName('postal_code')]
    public string $postalCode;

    #[Groups(['address:read', 'user:read'])]
    #[MaxDepth(2)]
    public string $country;

    /**
     * An internal identifier that must never appear in the serialized
     * output. Having it here exercises the `#[Ignore]` → ignored-flag
     * linking between the property declaration and its same-named
     * constructor parameter.
     */
    #[Ignore]
    public ?string $internalCode = null;

    public function __construct(
        string $street,
        string $city,
        string $postalCode,
        string $country,
        ?string $internalCode = null,
    ) {
        $this->street = $street;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->country = $country;
        $this->internalCode = $internalCode;
    }
}
