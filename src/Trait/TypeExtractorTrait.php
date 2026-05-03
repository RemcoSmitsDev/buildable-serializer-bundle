<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Trait;

use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Trait providing scalar-type extraction and coercion helpers for
 * generated denormalizers.
 *
 * These helpers honor the `AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT`
 * context flag:
 *
 *   - Strict mode (default): a value whose type does not match the expected
 *     scalar type causes a {@see NotNormalizableValueException}.
 *   - Lenient mode: the value is coerced to the expected type when possible,
 *     following the rules documented in the denormalizer plan.
 *
 * Missing required fields always throw {@see MissingConstructorArgumentsException},
 * regardless of the type-enforcement flag — EXCEPT when the caller supplied
 * a non-null `$default` (see below).
 *
 * ### `$required` vs. `$default` interaction
 *
 * The `$required` flag only throws when the key is missing from `$data` AND
 * no default value was supplied (`$default === null`). A non-null default is
 * treated as an authoritative fallback, so the helper returns it without
 * touching `$required`. This is what makes the chained-call pattern used for
 * `#[SerializedName]` fallback work cleanly: the generator can nest
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
 * email: $this->extractString(
 *     $data,
 *     'email_address',
 *     required: true,
 *     default: $this->extractString(
 *         $data,
 *         'emailAddress',
 *         required: false,
 *         default: null,
 *         context: $context,
 *     ),
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
trait TypeExtractorTrait
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractInt(array $data, string $key, bool $required, ?int $default, array $context): int
    {
        if (!array_key_exists($key, $data)) {
            // A non-null default takes precedence over the required check:
            // it represents either the user-declared constructor default or
            // the value resolved by a fallback-key lookup (see the
            // chained-call pattern documented on the trait).
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return 0;
        }

        $value = $data[$key];

        if ($value === null) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf('The field "%s" expects a non-null value of type "int", but null was given.', $key),
                null,
                ['int'],
                $key,
                true,
            );
        }

        if (is_int($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "int", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['int'],
                $key,
                true,
            );
        }

        return $this->coerceToInt($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractNullableInt(array $data, string $key, bool $required, ?int $default, array $context): ?int
    {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return null;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "int", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['int'],
                $key,
                true,
            );
        }

        return $this->coerceToInt($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractFloat(array $data, string $key, bool $required, ?float $default, array $context): float
    {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return 0.0;
        }

        $value = $data[$key];

        if ($value === null) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf('The field "%s" expects a non-null value of type "float", but null was given.', $key),
                null,
                ['float'],
                $key,
                true,
            );
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            // int is always safely representable as float in PHP's serializer
            return (float) $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "float", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['float'],
                $key,
                true,
            );
        }

        return $this->coerceToFloat($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractNullableFloat(
        array $data,
        string $key,
        bool $required,
        ?float $default,
        array $context,
    ): ?float {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return null;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "float", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['float'],
                $key,
                true,
            );
        }

        return $this->coerceToFloat($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractString(array $data, string $key, bool $required, ?string $default, array $context): string
    {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return '';
        }

        $value = $data[$key];

        if ($value === null) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf('The field "%s" expects a non-null value of type "string", but null was given.', $key),
                null,
                ['string'],
                $key,
                true,
            );
        }

        if (is_string($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "string", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['string'],
                $key,
                true,
            );
        }

        return $this->coerceToString($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractNullableString(
        array $data,
        string $key,
        bool $required,
        ?string $default,
        array $context,
    ): ?string {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return null;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "string", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['string'],
                $key,
                true,
            );
        }

        return $this->coerceToString($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractBool(array $data, string $key, bool $required, ?bool $default, array $context): bool
    {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return false;
        }

        $value = $data[$key];

        if ($value === null) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf('The field "%s" expects a non-null value of type "bool", but null was given.', $key),
                null,
                ['bool'],
                $key,
                true,
            );
        }

        if (is_bool($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "bool", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['bool'],
                $key,
                true,
            );
        }

        return $this->coerceToBool($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function extractNullableBool(
        array $data,
        string $key,
        bool $required,
        ?bool $default,
        array $context,
    ): ?bool {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return null;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "bool", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['bool'],
                $key,
                true,
            );
        }

        return $this->coerceToBool($value, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @return array<mixed>
     */
    private function extractArray(array $data, string $key, bool $required, ?array $default, array $context): array
    {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return [];
        }

        $value = $data[$key];

        if ($value === null) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf('The field "%s" expects a non-null value of type "array", but null was given.', $key),
                null,
                ['array'],
                $key,
                true,
            );
        }

        if (is_array($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "array", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['array'],
                $key,
                true,
            );
        }

        // Lenient: wrap scalar in array
        return [$value];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     *
     * @return array<mixed>|null
     */
    private function extractNullableArray(
        array $data,
        string $key,
        bool $required,
        ?array $default,
        array $context,
    ): ?array {
        if (!array_key_exists($key, $data)) {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw new MissingConstructorArgumentsException(
                    sprintf('Cannot create object because the required field "%s" is missing.', $key),
                    0,
                    null,
                    [$key],
                );
            }

            return null;
        }

        $value = $data[$key];

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!$this->isTypeEnforcementDisabled($context)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "array", but "%s" was given.',
                    $key,
                    get_debug_type($value),
                ),
                $value,
                ['array'],
                $key,
                true,
            );
        }

        return [$value];
    }

    /**
     * Returns true when the `DISABLE_TYPE_ENFORCEMENT` context flag is set to true.
     *
     * @param array<string, mixed> $context
     */
    private function isTypeEnforcementDisabled(array $context): bool
    {
        return (bool) ($context[AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT] ?? false);
    }

    private function coerceToInt(mixed $value, string $key): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            if ((float) (int) $value !== $value) {
                throw NotNormalizableValueException::createForUnexpectedDataType(
                    sprintf(
                        'The field "%s" expects a value of type "int", but a float (%s) with a fractional part was given.',
                        $key,
                        (string) $value,
                    ),
                    $value,
                    ['int'],
                    $key,
                    true,
                );
            }

            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            $intVal = (int) $value;
            if ((string) $intVal === $value) {
                return $intVal;
            }

            $floatVal = (float) $value;
            if ((float) (int) $floatVal === $floatVal) {
                return (int) $floatVal;
            }

            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf(
                    'The field "%s" expects a value of type "int", but a string ("%s") with a fractional part was given.',
                    $key,
                    $value,
                ),
                $value,
                ['int'],
                $key,
                true,
            );
        }

        throw NotNormalizableValueException::createForUnexpectedDataType(
            sprintf('The field "%s" expects a value of type "int", but "%s" was given.', $key, get_debug_type($value)),
            $value,
            ['int'],
            $key,
            true,
        );
    }

    private function coerceToFloat(mixed $value, string $key): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw NotNormalizableValueException::createForUnexpectedDataType(
            sprintf(
                'The field "%s" expects a value of type "float", but "%s" was given.',
                $key,
                get_debug_type($value),
            ),
            $value,
            ['float'],
            $key,
            true,
        );
    }

    private function coerceToString(mixed $value, string $key): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        throw NotNormalizableValueException::createForUnexpectedDataType(
            sprintf(
                'The field "%s" expects a value of type "string", but "%s" was given.',
                $key,
                get_debug_type($value),
            ),
            $value,
            ['string'],
            $key,
            true,
        );
    }

    private function coerceToBool(mixed $value, string $key): bool
    {
        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_float($value)) {
            return $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (\in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        throw NotNormalizableValueException::createForUnexpectedDataType(
            sprintf('The field "%s" expects a value of type "bool", but "%s" was given.', $key, get_debug_type($value)),
            $value,
            ['bool'],
            $key,
            true,
        );
    }
}
