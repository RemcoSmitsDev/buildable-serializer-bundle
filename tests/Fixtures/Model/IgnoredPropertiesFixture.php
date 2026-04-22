<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Tests\Fixtures\Model;

use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * Fixture for testing that #[Ignore]-annotated properties and constructor
 * parameters are completely skipped during both normalization and
 * denormalization.
 *
 * ### Properties / parameters covered
 *
 *   - `$title`   — a plain, visible promoted constructor parameter with no
 *                  attributes. Acts as the "control" field that must always
 *                  appear in the normalized output and always be read from
 *                  input during denormalization.
 *
 *   - `$secret`  — a promoted constructor parameter marked with #[Ignore].
 *                  The normalizer must NOT include it in the output array.
 *                  The denormalizer must NOT read it from the input payload;
 *                  its constructor default (`''`) must always be used instead,
 *                  even when a `"secret"` key is explicitly present in the data.
 *
 *   - `$internalScore` — a plain public property (populated during the
 *                  populate() phase, not via the constructor) marked with
 *                  #[Ignore].  The normalizer must NOT include it in the
 *                  output array.  The denormalizer must NOT write to it
 *                  during population, even when `"internalScore"` is present
 *                  in the input payload; the property must retain its
 *                  declaration-site default value of `0`.
 */
final class IgnoredPropertiesFixture
{
    #[Ignore]
    public int $internalScore = 0;

    public function __construct(
        public readonly string $title,
        #[Ignore]
        public readonly string $secret = '',
    ) {}

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getInternalScore(): int
    {
        return $this->internalScore;
    }
}
