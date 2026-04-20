<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Trait;

/**
 * Runtime trait used by generated denormalizers.
 *
 * Combines the scalar-type extraction helpers from {@see TypeExtractorTrait}
 * and the object / collection extraction helpers from {@see ObjectExtractorTrait}
 * into a single trait that generated denormalizer classes can "use" in one line.
 *
 * Generated denormalizers also pull in
 * {@see \Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait}
 * separately (at the class level) so that `$this->denormalizer` is populated
 * by Symfony's serializer chain before any of the extract* helpers below are
 * invoked.
 *
 * Example generated usage:
 *
 * ```php
 * final class PersonDenormalizer implements DenormalizerInterface, DenormalizerAwareInterface
 * {
 *     use GeneratedDenormalizerTrait;
 *     use DenormalizerAwareTrait;
 *
 *     // ...
 * }
 * ```
 *
 * @see TypeExtractorTrait
 * @see ObjectExtractorTrait
 */
trait GeneratedDenormalizerTrait
{
    use TypeExtractorTrait;
    use ObjectExtractorTrait;
}
