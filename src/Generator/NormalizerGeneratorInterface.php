<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator;

use RemcoSmitsDev\BuildableSerializerBundle\Metadata\ClassMetadata;

/**
 * Interface for generating PHP normalizer source code.
 *
 * This interface follows the single responsibility principle by focusing
 * solely on code generation. File I/O operations (writing, path resolution)
 * are handled by {@see NormalizerWriterInterface}.
 */
interface NormalizerGeneratorInterface
{
    /**
     * Generate and return the complete PHP source code string for a normalizer
     * class that handles the class described by the given {@see ClassMetadata}.
     *
     * The returned string is ready to be written verbatim to a `.php` file.
     *
     * @template T of object
     *
     * @param ClassMetadata<T> $metadata Fully-built metadata for the domain class.
     *
     * @return string The generated PHP source code for the normalizer class.
     */
    public function generate(ClassMetadata $metadata): string;
}
