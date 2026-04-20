<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

/**
 * Fixture with an intersection-typed constructor parameter.
 *
 * Used by {@see \RemcoSmitsDev\BuildableSerializerBundle\Tests\Unit\Metadata\ConstructorMetadataExtractorTest}
 * to exercise the `ReflectionIntersectionType` branch of
 * {@see \RemcoSmitsDev\BuildableSerializerBundle\Metadata\ConstructorMetadataExtractor}.
 *
 * The fixture is never instantiated in tests — only its `ReflectionClass`
 * is inspected — so the intersection type does not need to be satisfiable
 * with a real object.
 *
 * Intersection types are a PHP 8.1 feature. On 8.1 they cannot be combined
 * with `null` (nullable intersection types were introduced in 8.2 via DNF
 * types), so this fixture intentionally uses a plain, non-nullable
 * intersection.
 */
final class IntersectionTypeFixture
{
    public function __construct(
        public \Countable&\Stringable $value,
    ) {}
}
