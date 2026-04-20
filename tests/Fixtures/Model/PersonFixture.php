<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Person fixture used in denormalizer tests.
 *
 * Exercises the following denormalization scenarios:
 *   - Required constructor parameter without default (`name`)
 *   - Optional scalar parameter with int default (`age`)
 *   - Optional enum parameter with enum-case default (`status`)
 *   - Optional nullable nested object (`address`)
 *   - Nullable scalar without default but with nullable type (`nickname`)
 *   - Promoted public parameters so the property population phase also sees them
 */
final class PersonFixture
{
    public function __construct(
        public string $name,
        public int $age = 18,
        public StatusFixture $status = StatusFixture::PENDING,
        public ?AddressFixture $address = null,
        public ?string $nickname = null,
    ) {}
}
