<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Metadata;

/**
 * Contract for building class metadata used during normalizer generation.
 *
 * Implementations inspect a class (via Reflection, attributes, docblocks, etc.)
 * and return a fully-populated {@see ClassMetadata} value object that the
 * normalizer generator consumes to emit optimised PHP code.
 */
interface MetadataFactoryInterface
{
    /**
     * Return metadata for the given fully-qualified class name.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return ClassMetadata<T>
     *
     * @throws \InvalidArgumentException When the class does not exist.
     */
    public function getMetadataFor(string $className): ClassMetadata;

    /**
     * Return true when the factory is able to build metadata for the given class.
     *
     * @param class-string|string $className
     */
    public function hasMetadataFor(string $className): bool;
}
