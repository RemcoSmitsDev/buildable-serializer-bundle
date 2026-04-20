<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Trait;

use RemcoSmitsDev\BuildableSerializerBundle\Exception\MissingRequiredFieldException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\TypeMismatchException;
use RemcoSmitsDev\BuildableSerializerBundle\Exception\UnexpectedNullException;

/**
 * Trait providing object and collection extraction helpers for generated
 * denormalizers.
 *
 * These helpers delegate to the serializer chain via `$this->denormalizer`,
 * which is injected by Symfony when the implementing class uses
 * {@see \Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait}.
 *
 * This means nested objects, enums, DateTime values, and any other type
 * supported by the serializer chain are handled automatically without
 * requiring specific denormalizers to be injected.
 *
 * ### `$required` vs. `$default` interaction
 *
 * The `$required` flag only throws when the key is missing from `$data` AND
 * no default value was supplied (`$default === null`). A non-null default is
 * treated as an authoritative fallback, so the helper returns it without
 * touching `$required`. This is what makes the chained-call pattern used
 * for `#[SerializedName]` fallback work cleanly: the generator can nest
 * `extract*` calls so the outer call reads the canonical alias and its
 * `default:` argument is another `extract*` call that reads the PHP-name
 * fallback. When the outer key is missing but the inner call produced a
 * value, the outer call simply returns that value — it does NOT throw even
 * if `required: true` was passed on the outer call.
 *
 * ### Key fallback for `#[SerializedName]`
 *
 * When a property carries a `#[SerializedName]` alias that differs from its
 * PHP name, the denormalizer generator emits nested `extract*` calls — for
 * example:
 *
 * ```php
 * address: $this->extractObject(
 *     $data,
 *     'shipping_address',
 *     Address::class,
 *     required: true,
 *     default: $this->extractObject(
 *         $data,
 *         'shippingAddress',
 *         Address::class,
 *         required: false,
 *         default: null,
 *         format: $format,
 *         context: $context,
 *     ),
 *     format: $format,
 *     context: $context,
 * )
 * ```
 *
 * The outer call reads the canonical alias; when that key is absent, its
 * `default:` argument — an inner `extract*` call that reads the PHP-name
 * fallback — has already been evaluated and is used as the result. Each
 * `extract*` helper therefore only needs to deal with a single key at a
 * time; the generator composes the lookup order.
 */
trait ObjectExtractorTrait
{
    /**
     * Extract a single (possibly nullable) object value for the given key and
     * delegate its conversion to the serializer chain.
     *
     * @param array<string, mixed> $data
     * @param class-string         $className
     * @param array<string, mixed> $context
     */
    private function extractObject(
        array $data,
        string $key,
        string $className,
        bool $required,
        ?object $default,
        ?string $format,
        array $context,
    ): ?object {
        if (!array_key_exists($key, $data)) {
            // A non-null default takes precedence over the required check:
            // it represents either the user-declared constructor default or
            // the value resolved by a fallback-key lookup (see the
            // chained-call pattern documented on the trait).
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingRequiredFieldException($key);
            }

            return null;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        // Already an instance of the expected type (e.g. OBJECT_TO_POPULATE use cases)
        if (is_object($value) && is_a($value, $className)) {
            return $value;
        }

        /** @var object $result */
        $result = $this->denormalizer->denormalize($value, $className, $format, $context);

        return $result;
    }

    /**
     * Extract a required, non-nullable object value for the given key.
     *
     * @param array<string, mixed> $data
     * @param class-string         $className
     * @param array<string, mixed> $context
     */
    private function extractRequiredObject(
        array $data,
        string $key,
        string $className,
        ?string $format,
        array $context,
    ): object {
        if (!array_key_exists($key, $data)) {
            throw new MissingRequiredFieldException($key);
        }

        $value = $data[$key];

        if ($value === null) {
            throw new UnexpectedNullException($key, $className);
        }

        if (is_object($value) && is_a($value, $className)) {
            return $value;
        }

        /** @var object $result */
        $result = $this->denormalizer->denormalize($value, $className, $format, $context);

        return $result;
    }

    /**
     * Extract a list of objects (`array<int, T>` / `T[]`) for the given key.
     *
     * @param array<string, mixed>      $data
     * @param class-string              $className
     * @param array<int, object>|null   $default When non-null, returned in place of the "missing" sentinel `[]`.
     *                                           Used by the generator to chain fallback-key lookups for
     *                                           `#[SerializedName]` aliases.
     * @param array<string, mixed>      $context
     *
     * @return array<int, object>
     */
    private function extractArrayOfObjects(
        array $data,
        string $key,
        string $className,
        bool $required,
        ?array $default,
        ?string $format,
        array $context,
    ): array {
        if (!array_key_exists($key, $data)) {
            // A non-null default takes precedence over the required check:
            // it represents the value resolved by a fallback-key lookup
            // (see the chained-call pattern documented on the trait).
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingRequiredFieldException($key);
            }

            return [];
        }

        $value = $data[$key];

        if ($value === null) {
            return $default ?? [];
        }

        if (!is_array($value)) {
            throw new TypeMismatchException($key, sprintf('array<%s>', $className), get_debug_type($value));
        }

        $result = [];

        foreach ($value as $index => $item) {
            if (is_object($item) && is_a($item, $className)) {
                $result[] = $item;
                continue;
            }

            /** @var object $denormalized */
            $denormalized = $this->denormalizer->denormalize(
                $item,
                $className,
                $format,
                $context + ['_buildable_denormalizer_collection_index' => $index],
            );

            $result[] = $denormalized;
        }

        return $result;
    }

    /**
     * Extract a nullable list of objects (`?array<int, T>`) for the given key.
     *
     * @param array<string, mixed>      $data
     * @param class-string              $className
     * @param array<int, object>|null   $default When non-null, returned in place of the "missing" sentinel.
     *                                           Used by the generator to chain fallback-key lookups for
     *                                           `#[SerializedName]` aliases.
     * @param array<string, mixed>      $context
     *
     * @return array<int, object>|null
     */
    private function extractNullableArrayOfObjects(
        array $data,
        string $key,
        string $className,
        bool $required,
        ?array $default,
        ?string $format,
        array $context,
    ): ?array {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingRequiredFieldException($key);
            }

            return null;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new TypeMismatchException($key, sprintf('array<%s>', $className), get_debug_type($value));
        }

        $result = [];

        foreach ($value as $index => $item) {
            if (is_object($item) && is_a($item, $className)) {
                $result[] = $item;
                continue;
            }

            /** @var object $denormalized */
            $denormalized = $this->denormalizer->denormalize(
                $item,
                $className,
                $format,
                $context + ['_buildable_denormalizer_collection_index' => $index],
            );

            $result[] = $denormalized;
        }

        return $result;
    }

    /**
     * Extract a string-keyed map of objects (`array<string, T>`) for the given key.
     *
     * @param array<string, mixed>        $data
     * @param class-string                $className
     * @param array<string, object>|null  $default When non-null, returned in place of the "missing" sentinel `[]`.
     *                                             Used by the generator to chain fallback-key lookups for
     *                                             `#[SerializedName]` aliases.
     * @param array<string, mixed>        $context
     *
     * @return array<string, object>
     */
    private function extractMapOfObjects(
        array $data,
        string $key,
        string $className,
        bool $required,
        ?array $default,
        ?string $format,
        array $context,
    ): array {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingRequiredFieldException($key);
            }

            return [];
        }

        $value = $data[$key];

        if ($value === null) {
            return $default ?? [];
        }

        if (!is_array($value)) {
            throw new TypeMismatchException($key, sprintf('array<string, %s>', $className), get_debug_type($value));
        }

        $result = [];

        foreach ($value as $mapKey => $item) {
            $stringKey = (string) $mapKey;

            if (is_object($item) && is_a($item, $className)) {
                $result[$stringKey] = $item;
                continue;
            }

            /** @var object $denormalized */
            $denormalized = $this->denormalizer->denormalize(
                $item,
                $className,
                $format,
                $context + ['_buildable_denormalizer_map_key' => $stringKey],
            );

            $result[$stringKey] = $denormalized;
        }

        return $result;
    }
}
