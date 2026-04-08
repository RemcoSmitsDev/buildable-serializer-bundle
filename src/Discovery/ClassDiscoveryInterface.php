<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Discovery;

use Buildable\SerializerBundle\Metadata\ClassMetadata;

/**
 * Contract for discovering PHP classes that should have normalizers generated.
 *
 * Implementations may discover classes in different ways:
 *
 * - {@see FinderClassDiscovery} — scans PSR-4 namespace-prefix → directory
 *   mappings for classes marked with {@see \Buildable\SerializerBundle\Attribute\Serializable}.
 */
interface ClassDiscoveryInterface
{
    /**
     * Return the list of fully-qualified class names for which a normalizer
     * should be generated.
     *
     * Implementations MUST:
     *
     * - Return only concrete, instantiable classes (no interfaces, abstract
     *   classes, traits, or enums).
     *
     * @return iterable<ClassMetadata<object>>
     */
    public function discoverClasses(): iterable;
}
