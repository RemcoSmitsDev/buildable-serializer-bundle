<?php

declare(strict_types=1);

namespace RemcoSmitsDev\BuildableSerializerBundle\Generator\Denormalizer;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

/**
 * Converts PHP runtime values (typically constructor default values extracted
 * via reflection) into nikic/php-parser AST expression nodes.
 *
 * The generated denormalizer code must preserve constructor default values
 * verbatim so that optional constructor parameters can be populated with the
 * original defaults when the corresponding key is missing from the input data.
 *
 * ### Supported value types
 *
 *   - `null`
 *   - `bool`
 *   - `int`
 *   - `float`
 *   - `string`
 *   - `array` (nested arrays are supported; values are recursively converted)
 *   - `\UnitEnum` / `\BackedEnum` instances → rendered as `\Fqcn\Enum::CASE`
 *
 * ### Unsupported value types
 *
 * The following default-value types are deliberately NOT supported and will
 * cause a {@see \LogicException} to be thrown at generation time:
 *
 *   - `\Closure`
 *   - `\DateTimeInterface` and other non-enum objects
 *   - Resources
 *   - Any value whose runtime type is not listed as supported above
 *
 * Callers that receive a \LogicException should treat the associated
 * constructor parameter as "cannot be generated" and either fail early with
 * an informative error or fall back to marking the parameter as required.
 */
final class DefaultValueBuilder
{
    /**
     * Convert the given PHP runtime value into an AST expression node.
     *
     * @throws \LogicException When $value has a type that cannot be represented
     *                         as a static PHP expression (e.g. a Closure, a
     *                         non-enum object, or a resource).
     */
    public function build(mixed $value): Expr
    {
        if ($value === null) {
            return new ConstFetch(new Name('null'));
        }

        if (is_bool($value)) {
            return new ConstFetch(new Name($value ? 'true' : 'false'));
        }

        if (is_int($value)) {
            return new Int_($value);
        }

        if (is_float($value)) {
            return new Float_($value);
        }

        if (is_string($value)) {
            return new String_($value);
        }

        if (is_array($value)) {
            return $this->buildArray($value);
        }

        if ($value instanceof \UnitEnum) {
            return new ClassConstFetch(new FullyQualified($value::class), $value->name);
        }

        throw new \LogicException(sprintf(
            'Cannot build AST node for default value of type "%s". '
            . 'Supported types: null, bool, int, float, string, array, \UnitEnum.',
            get_debug_type($value),
        ));
    }

    /**
     * Return true when the given value can be safely converted to an AST node.
     *
     * This is a non-throwing equivalent of {@see build()} intended for callers
     * that want to check supportability without catching exceptions.
     */
    public function supports(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return true;
        }

        if ($value instanceof \UnitEnum) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->supports($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Recursively convert a PHP array into an AST Array_ node using short-array
     * syntax to match the style of the rest of the generated code.
     *
     * Numeric (list) keys are emitted as unkeyed items so the printer produces
     * a compact `[a, b, c]` representation; string keys are preserved verbatim.
     *
     * @param array<string|int, mixed> $array
     */
    private function buildArray(array $array): Array_
    {
        $isList = array_is_list($array);
        $items = [];

        foreach ($array as $key => $item) {
            $valueExpr = $this->build($item);

            if ($isList) {
                $items[] = new ArrayItem($valueExpr);
                continue;
            }

            $keyExpr = is_int($key) ? new Int_($key) : new String_($key);
            $items[] = new ArrayItem($valueExpr, $keyExpr);
        }

        return new Array_($items, ['kind' => Array_::KIND_SHORT]);
    }
}
