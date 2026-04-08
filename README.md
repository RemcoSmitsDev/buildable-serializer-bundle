# Buildable Serializer Bundle

A Symfony bundle that generates optimised, build-time normalizer classes for the Symfony Serializer component.

Instead of relying on the generic reflection-based `ObjectNormalizer` at runtime, this bundle analyses your classes at compile time and writes plain PHP normalizer classes tailored to each model. The result is a faster serializer with zero runtime reflection overhead.

> **Note:** The bundle currently supports **normalizers only**. Support for denormalizers is planned and will be added in a future release.

---

## How it works

1. **Mark your classes** with the `#[Serializable]` attribute.
2. **Configure the bundle** with the namespaces and directories to scan.
3. **On container compilation** (e.g. `cache:clear` or `cache:warmup`), the bundle:
   - Scans the configured paths for classes carrying the `#[Serializable]` attribute.
   - Inspects each class's properties, constructor parameters, and getter methods via reflection and property-info extractors to build rich `ClassMetadata`.
   - Generates a dedicated PHP normalizer class per model using [nikic/php-parser](https://github.com/nikic/PHP-Parser) and writes it to the configured cache directory.
   - Registers every generated normalizer as a Symfony service tagged with `serializer.normalizer` at priority **200** (well above `ObjectNormalizer`'s -1000), so they are used first.

Generated normalizers are plain, human-readable PHP classes — you can inspect them in your cache directory at any time.

---

## Installation

```bash
composer require buildable/serializer-bundle
```

Register the bundle in `config/bundles.php` if it is not picked up automatically by Symfony Flex:

```php
return [
    // ...
    BuildableSerializerBundle\BuildableSerializerBundle::class => ['all' => true],
];
```

---

## Configuration

Create `config/packages/buildable_serializer.yaml`:

```yaml
buildable_serializer:
    # Directory where generated normalizer PHP files are written.
    # Defaults to %kernel.project_dir%/var/buildable_serializer
    cache_dir: '%kernel.project_dir%/var/buildable_serializer'

    # Root PHP namespace used for all generated normalizer classes.
    generated_namespace: 'BuildableSerializer\Generated'

    # PSR-4 map of namespace-prefix => directory to scan for #[Serializable] classes.
    paths:
        'App\Model': '%kernel.project_dir%/src/Model'
        'App\Dto':   '%kernel.project_dir%/src/Dto'

    # Toggle individual serializer features in the generated normalizers.
    features:
        groups: true                # Emit group-filtering logic.
        max_depth: true             # Emit max-depth checking logic.
        circular_reference: true    # Emit circular-reference detection logic.
        name_converter: false       # Respect a name converter service.
        skip_null_values: true      # Emit logic to skip null-valued properties.

    # Options controlling the generated PHP source files.
    generation:
        strict_types: true          # Prepend declare(strict_types=1); to every file.
```

All options are optional and fall back to the defaults shown above.

---

## Usage

### 1. Mark your models

```php
use BuildableSerializerBundle\Attribute\Serializable;

#[Serializable]
final class ProductDto
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly ?float $price = null,
    ) {}
}
```

### 2. Clear (or warm) the cache

```bash
php bin/console cache:clear
# or
php bin/console cache:warmup
```

That's it. The Symfony Serializer will now use the generated normalizer for `ProductDto` automatically — no other code changes are required.

---

## Features

| Feature | Default | Description |
|---|---|---|
| `groups` | `true` | Honours the `groups` serialization context key |
| `max_depth` | `true` | Enforces the `max_depth` context constraint |
| `circular_reference` | `true` | Detects and handles circular object references |
| `name_converter` | `false` | Applies a name converter service to property keys |
| `skip_null_values` | `true` | Omits `null` properties when the context flag is set |

Disabling a feature you don't need produces leaner, faster generated code.

---

## Current limitations

- **Normalizers only.** The bundle generates normalizers (serialization) but does not yet generate denormalizers (deserialization). Denormalizer support is planned for a future release. Until then, deserialization falls back to Symfony's standard `ObjectNormalizer` / `ArrayDenormalizer`.

---

## Requirements

- PHP 8.1.*
- Symfony 6.4.*

---

## License

MIT — see [LICENSE](LICENSE) for details.
