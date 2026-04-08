<?php

declare(strict_types=1);

namespace BuildableSerializerBundle\Attribute;

/**
 * Marks a class for build-time normalizer generation.
 *
 * Place this attribute on any concrete model, entity, or DTO class. Make sure
 * the class's namespace and source directory are listed under
 * `buildable_serializer.paths` in your bundle configuration. A dedicated
 * normalizer will be generated during cache warm-up or via the console command.
 *
 * Example:
 *
 *     use BuildableSerializerBundle\Attribute\Serializable;
 *
 *     #[Serializable]
 *     final class ProductDto
 *     {
 *         public function __construct(
 *             public readonly int    $id,
 *             public readonly string $name,
 *         ) {}
 *     }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Serializable {}
