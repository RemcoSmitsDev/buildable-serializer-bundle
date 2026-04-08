# Buildable Serializer Bundle

A Symfony bundle that generates optimised, build-time normalizer classes for the Symfony Serializer component.

Instead of relying on the generic reflection-based `ObjectNormalizer` at runtime, this bundle analyses your classes at compile time and writes plain PHP normalizer classes tailored to each model. The result is a faster serializer with zero runtime reflection overhead.

> **Note:** The bundle currently supports **normalizers only**. Support for denormalizers is planned and will be added in a future release.

---

## Benchmarks

Normalizing a single `Post` (with a nested `User` and `Address`) **200 000 times** in a local environment:

| | Time |
|---|---|
| Symfony `ObjectNormalizer` (before) | 3 883 ms |
| Generated normalizer (after) | 158 ms |

That is a **~24× speedup** — purely from eliminating runtime reflection and metadata overhead.

The benchmark was produced with:

```php
$start = microtime(true);
for ($i = 0; $i < 200_000; $i++) {
    $this->serializer->normalize($post);
}
$elapsed = microtime(true) - $start;
dd("Time: " . round($elapsed * 1000) . " ms");
```

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
#[Serializable]
class User
{
    #[Groups(["user:read", "user:list"])]
    private int $id;

    #[Groups(["user:read", "user:list"])]
    private string $firstName;

    #[Groups(["user:read", "user:list"])]
    private string $lastName;

    #[Groups(["user:read"])]
    #[SerializedName("email_address")]
    private string $email;

    #[Groups(["user:read"])]
    private ?Address $address = null;

    #[Ignore]
    private string $passwordHash = "";

    #[Groups(["user:read"])]
    private bool $active = true;

    public function __construct(
        int $id,
        string $firstName,
        string $lastName,
        string $email,
    ) {
        $this->id = $id;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): void
    {
        $this->address = $address;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}

#[Serializable]
class Post
{
    #[Groups(["post:read", "post:list"])]
    private int $id;

    #[Groups(["post:read", "post:list"])]
    private string $title;

    #[Groups(["post:read"])]
    private string $content;

    #[Groups(["post:read", "post:list"])]
    #[MaxDepth(1)]
    private User $author;

    public function __construct(
        int $id,
        string $title,
        string $content,
        User $author,
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->content = $content;
        $this->author = $author;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }
}

#[Serializable]
class Address
{
    #[Groups(["address:read", "user:read"])]
    public string $street;

    #[Groups(["address:read", "user:read"])]
    public string $city;

    #[Groups(["address:read", "user:read"])]
    #[SerializedName("postal_code")]
    public string $postalCode;

    #[Groups(["address:read", "user:read"])]
    public string $country;

    public function __construct(
        string $street,
        string $city,
        string $postalCode,
        string $country,
    ) {
        $this->street = $street;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->country = $country;
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountry(): string
    {
        return $this->country;
    }
}
```

### 2. Clear (or warm) the cache

```bash
php bin/console cache:clear
# or
php bin/console cache:warmup
```

That's it. The Symfony Serializer will now use the generated normalizers automatically — no other code changes are required.

### 3. Generated normalizers

The bundle writes one normalizer per model into the configured `cache_dir`. For the models above it produces:

**`UserNormalizer.php`**

```php
/**
 * @generated
 *
 * Normalizer for \App\Model\User.
 *
 * THIS FILE IS AUTO-GENERATED. DO NOT EDIT MANUALLY.
 */
final class UserNormalizer implements NormalizerInterface, GeneratedNormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    /** Priority in the Symfony Serializer normalizer chain (higher = earlier). */
    public const NORMALIZER_PRIORITY = 200;
    /**
     * @param \App\Model\User $object
     * @param array<string, mixed>      $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $objectHash = spl_object_hash($object);
        $context['circular_reference_limit_counters'] ??= [];
        if (isset($context['circular_reference_limit_counters'][$objectHash])) {
            $limit = (int) ($context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT] ?? 1);
            if ($context['circular_reference_limit_counters'][$objectHash] >= $limit) {
                if (isset($context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER])) {
                    return $context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER]($object, $format, $context);
                }
                throw new CircularReferenceException(sprintf('A circular reference has been detected when serializing the object of class "%s" (configured limit: %d).', 'App\Model\User', $limit));
            }
            ++$context['circular_reference_limit_counters'][$objectHash];
        } else {
            $context['circular_reference_limit_counters'][$objectHash] = 1;
        }
        $groups = (array) ($context[AbstractNormalizer::GROUPS] ?? []);
        $groupsLookup = array_fill_keys($groups, true);
        $skipNullValues = (bool) ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false);
        $data = [];
        if ($groups === [] || isset($groupsLookup['user:read']) || isset($groupsLookup['user:list'])) {
            $data['id'] = $object->getId();
        }
        if ($groups === [] || isset($groupsLookup['user:read']) || isset($groupsLookup['user:list'])) {
            $data['firstName'] = $object->getFirstName();
        }
        if ($groups === [] || isset($groupsLookup['user:read']) || isset($groupsLookup['user:list'])) {
            $data['lastName'] = $object->getLastName();
        }
        if ($groups === [] || isset($groupsLookup['user:read'])) {
            $data['email_address'] = $object->getEmail();
        }
        if ($groups === [] || isset($groupsLookup['user:read'])) {
            $_val = $object->getAddress();
            if ($_val !== null) {
                $data['address'] = $this->normalizer->normalize($_val, $format, $context);
            } elseif (!$skipNullValues) {
                $data['address'] = null;
            }
        }
        if ($groups === [] || isset($groupsLookup['user:read'])) {
            $data['active'] = $object->isActive();
        }
        return $data;
    }
    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof User;
    }
    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [User::class => true];
    }
}
```

**`PostNormalizer.php`**

```php
/**
 * @generated
 *
 * Normalizer for \App\Model\Post.
 *
 * THIS FILE IS AUTO-GENERATED. DO NOT EDIT MANUALLY.
 */
final class PostNormalizer implements NormalizerInterface, GeneratedNormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    /** Priority in the Symfony Serializer normalizer chain (higher = earlier). */
    public const NORMALIZER_PRIORITY = 200;
    /**
     * @param \App\Model\Post $object
     * @param array<string, mixed>      $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $objectHash = spl_object_hash($object);
        $context['circular_reference_limit_counters'] ??= [];
        if (isset($context['circular_reference_limit_counters'][$objectHash])) {
            $limit = (int) ($context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT] ?? 1);
            if ($context['circular_reference_limit_counters'][$objectHash] >= $limit) {
                if (isset($context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER])) {
                    return $context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER]($object, $format, $context);
                }
                throw new CircularReferenceException(sprintf('A circular reference has been detected when serializing the object of class "%s" (configured limit: %d).', 'App\Model\Post', $limit));
            }
            ++$context['circular_reference_limit_counters'][$objectHash];
        } else {
            $context['circular_reference_limit_counters'][$objectHash] = 1;
        }
        $groups = (array) ($context[AbstractNormalizer::GROUPS] ?? []);
        $groupsLookup = array_fill_keys($groups, true);
        $skipNullValues = (bool) ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false);
        $data = [];
        if ($groups === [] || isset($groupsLookup['post:read']) || isset($groupsLookup['post:list'])) {
            $data['id'] = $object->getId();
        }
        if ($groups === [] || isset($groupsLookup['post:read']) || isset($groupsLookup['post:list'])) {
            $data['title'] = $object->getTitle();
        }
        if ($groups === [] || isset($groupsLookup['post:read'])) {
            $data['content'] = $object->getContent();
        }
        if ($groups === [] || isset($groupsLookup['post:read']) || isset($groupsLookup['post:list'])) {
            $_depthKey = sprintf(AbstractObjectNormalizer::DEPTH_KEY_PATTERN, 'App\Model\Post', 'author');
            $_currentDepth = (int) ($context[$_depthKey] ?? 0);
            // max-depth: author (limit=1)
            if ($_currentDepth < 1) {
                $context[$_depthKey] = $_currentDepth + 1;
                $_val = $object->getAuthor();
                if ($_val !== null || !$skipNullValues) {
                    $data['author'] = $_val !== null ? $this->normalizer->normalize($_val, $format, $context) : null;
                }
            }
        }
        return $data;
    }
    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Post;
    }
    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Post::class => true];
    }
}
```

**`AddressNormalizer.php`**

```php
/**
 * @generated
 *
 * Normalizer for \App\Model\Address.
 *
 * THIS FILE IS AUTO-GENERATED. DO NOT EDIT MANUALLY.
 */
final class AddressNormalizer implements NormalizerInterface, GeneratedNormalizerInterface
{
    /** Priority in the Symfony Serializer normalizer chain (higher = earlier). */
    public const NORMALIZER_PRIORITY = 200;
    /**
     * @param \App\Model\Address $object
     * @param array<string, mixed>      $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $groups = (array) ($context[AbstractNormalizer::GROUPS] ?? []);
        $groupsLookup = array_fill_keys($groups, true);
        $skipNullValues = (bool) ($context[AbstractObjectNormalizer::SKIP_NULL_VALUES] ?? false);
        $data = [];
        if ($groups === [] || isset($groupsLookup['address:read']) || isset($groupsLookup['user:read'])) {
            $data['street'] = $object->street;
        }
        if ($groups === [] || isset($groupsLookup['address:read']) || isset($groupsLookup['user:read'])) {
            $data['city'] = $object->city;
        }
        if ($groups === [] || isset($groupsLookup['address:read']) || isset($groupsLookup['user:read'])) {
            $data['postal_code'] = $object->postalCode;
        }
        if ($groups === [] || isset($groupsLookup['address:read']) || isset($groupsLookup['user:read'])) {
            $data['country'] = $object->country;
        }
        return $data;
    }
    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Address;
    }
    /**
     * @return array<class-string|'*'|'object'|string, bool|null>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Address::class => true];
    }
}
```

A few things worth noting in the generated output:

- **`#[Ignore]`** — `$passwordHash` is completely absent from `UserNormalizer`; the field is never read or written.
- **`#[SerializedName]`** — `$email` is emitted as `email_address` and `$postalCode` as `postal_code`.
- **`#[MaxDepth(1)]`** — `PostNormalizer` wraps the `author` property in a depth-counter guard so that nested `User` objects are not serialized beyond one level.
- **Nullable object property** — `address` in `UserNormalizer` uses a dedicated branch: it only calls `normalize()` when the value is non-null, and falls back to `null` (or skips entirely when `SKIP_NULL_VALUES` is set).
- **`NormalizerAwareInterface`** — `UserNormalizer` and `PostNormalizer` receive the parent normalizer via `NormalizerAwareTrait` so they can delegate nested objects. `AddressNormalizer` has no nested objects and therefore does not need it.

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
