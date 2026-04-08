<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Tests\Fixtures\Model;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Address fixture model used in nested-object normalization tests.
 *
 * This class intentionally uses a mix of accessor styles (promoted constructor
 * parameters, public properties, and getters) so that the metadata factory and
 * generator are exercised across all three discovery paths.
 */
final class AddressFixture
{
    /**
     * Postal / ZIP code — exposed via a getter so that the getter-discovery
     * path is exercised during metadata extraction.
     */
    private string $postalCode;

    /**
     * An optional freeform note that is explicitly excluded from every
     * serialization group.
     */
    #[Groups(['internal'])]
    public ?string $internalNote = null;

    /**
     * @param string      $street    Street name and number.
     * @param string      $city      City name.
     * @param string      $country   ISO 3166-1 alpha-2 country code (e.g. "US", "DE").
     * @param string|null $state     State / province / region (optional).
     * @param string      $postalCode Postal or ZIP code.
     */
    public function __construct(
        #[Groups(['address', 'address:read'])]
        public readonly string $street,

        #[Groups(['address', 'address:read'])]
        public readonly string $city,

        #[Groups(['address', 'address:read'])]
        #[SerializedName('country_code')]
        public readonly string $country,

        #[Groups(['address', 'address:read'])]
        public readonly ?string $state = null,

        string $postalCode = '',
    ) {
        $this->postalCode = $postalCode;
    }

    /**
     * Return the postal / ZIP code.
     *
     * Using a getter rather than a promoted parameter to exercise the
     * getter-based accessor discovery in {@see \BuildableSerializerBundle\Metadata\MetadataFactory}.
     */
    #[Groups(['address', 'address:read'])]
    #[SerializedName('postal_code')]
    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    /**
     * Return a single-line formatted address string.
     *
     * This getter has no serialization attributes so it should NOT appear
     * in the generated normalizer output — it is a computed helper only.
     * It is used in tests to verify that unannotated getters are still
     * discovered but produce plain, non-nested scalar entries.
     */
    public function getFormatted(): string
    {
        $parts = array_filter([
            $this->street,
            $this->city,
            $this->state,
            $this->postalCode,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Return whether the address has a state / province component.
     *
     * Named with the "has" prefix to exercise the `hasXxx()` getter-detection
     * branch in the metadata factory.
     */
    public function hasState(): bool
    {
        return $this->state !== null && $this->state !== '';
    }
}
