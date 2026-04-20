<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Backed enum fixture used in denormalizer tests.
 *
 * Exercises enum default-value extraction (e.g. `Status::PENDING` as a
 * constructor default) and enum denormalization via the serializer chain's
 * built-in `BackedEnumNormalizer`.
 */
enum StatusFixture: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
