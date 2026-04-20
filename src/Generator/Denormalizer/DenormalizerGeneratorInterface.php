<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer;

use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;

/**
 * Interface for generating PHP denormalizer source code.
 *
 * This interface follows the single responsibility principle by focusing
 * solely on code generation. File I/O operations (writing, path resolution)
 * are handled by {@see DenormalizerWriterInterface} and
 * {@see DenormalizerPathResolverInterface} respectively.
 *
 * Implementations emit a `final` PHP class that:
 *   - implements {@see \Symfony\Component\Serializer\Normalizer\DenormalizerInterface}
 *   - implements {@see \Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface}
 *     (to delegate nested-object denormalization back to the serializer chain)
 *   - implements {@see \RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer\GeneratedDenormalizerInterface}
 *     (so the compiler pass can recognise it)
 *   - uses {@see \RemcoSmitsDev\BuildableSerializerBundle\Trait\GeneratedDenormalizerTrait}
 *     for runtime extraction helpers
 */
interface DenormalizerGeneratorInterface
{
    /**
     * Generate and return the complete PHP source code string for a
     * denormalizer class that handles the class described by the given
     * {@see ClassMetadata}.
     *
     * The returned string is ready to be written verbatim to a `.php` file.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata Fully-built metadata for the domain class.
     *
     * @return string The generated PHP source code for the denormalizer class.
     */
    public function generate(ClassMetadata $metadata): string;
}
