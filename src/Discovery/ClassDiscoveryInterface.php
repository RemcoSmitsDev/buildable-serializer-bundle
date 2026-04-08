<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Discovery;

use BuildableSerializerBundle\Metadata\ClassMetadata;

/**
 * Contract for discovering PHP classes that should have normalizers generated.
 *
 * Implementations may discover classes in different ways:
 *
 * - {@see FinderClassDiscovery} — scans PSR-4 namespace-prefix → directory
 *   mappings for classes marked with {@see \BuildableSerializerBundle\Attribute\Serializable}.
 */
interface ClassDiscoveryInterface
{
    /**
     * Return the list of ClassMetadata objects for all concrete, instantiable
     * classes that should have a normalizer generated.
     *
     * Implementations MUST:
     *
     * - Return only concrete, instantiable classes (no interfaces, abstract
     *   classes, traits, or enums).
     * - Return results in a stable, deterministic order.
     *
     * @return list<ClassMetadata<object>>
     */
    public function discoverClasses(): array;
}
