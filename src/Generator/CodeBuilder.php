<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Generator;

/**
 * Utility for building indented PHP code strings with a shared, append-only line buffer.
 *
 * Each {@see CodeBuilder} instance operates at a fixed base-indentation level.
 * Calling {@see indent()} returns a *new* instance that shares the same underlying
 * {@see \ArrayObject} buffer, so lines appended through either the parent or child
 * builder are interleaved in insertion order — exactly what you need when generating
 * nested code blocks:
 *
 * ```php
 * $method = new CodeBuilder(1); // class-body level (1 × 4 spaces)
 * $method->addLine('public function normalize(): array');
 * $method->addLine('{');
 *
 * $body = $method->indent();      // method-body level (2 × 4 spaces)
 * $body->addLine('$data = [];');
 *
 * $ifBlock = $body->indent();     // if-body level (3 × 4 spaces)
 * $ifBlock->addLine('$data["x"] = 1;');
 *
 * $body->addLine('return $data;');
 * $method->addLine('}');
 *
 * echo $method->build();
 * // =>
 * //     public function normalize(): array
 * //     {
 * //         $data = [];
 * //             $data["x"] = 1;
 * //         return $data;
 * //     }
 * ```
 *
 * @see PhpFile  which uses CodeBuilder to produce complete PHP file source.
 */
final class CodeBuilder
{
    /**
     * Number of spaces per indentation level (PSR-12: 4 spaces).
     */
    private const INDENT_SIZE = 4;

    /**
     * Pre-computed single-level indent string.
     */
    private const INDENT_UNIT = '    ';

    /**
     * Shared, append-only line buffer.
     *
     * Using {@see \ArrayObject} allows multiple {@see CodeBuilder} instances
     * created via {@see indent()} to write into the same backing store via
     * regular PHP object-reference semantics — no manual reference juggling.
     *
     * @var \ArrayObject<int, string>
     */
    private \ArrayObject $buffer;

    /**
     * @param int                             $baseIndent The number of indentation levels already applied
     *                                                    to every line appended by this instance.
     * @param \ArrayObject<int,string>|null   $buffer     Shared buffer; when null a fresh one is created.
     *                                                    Only root builders should omit this argument.
     */
    public function __construct(
        private readonly int $baseIndent = 0,
        ?\ArrayObject $buffer = null,
    ) {
        /** @var \ArrayObject<int, string> $resolved */
        $resolved     = $buffer ?? new \ArrayObject();
        $this->buffer = $resolved;
    }

    // -------------------------------------------------------------------------
    // Line appenders
    // -------------------------------------------------------------------------

    /**
     * Append a single line of code at (baseIndent + indent) indentation levels.
     *
     * Blank / empty lines are stored without any leading whitespace so that
     * the rendered output does not contain invisible trailing spaces — editors
     * and linters commonly flag those.
     *
     * @param string $line   The line content (no leading whitespace expected).
     * @param int    $indent Extra indentation levels on top of {@see $baseIndent}.
     */
    public function addLine(string $line, int $indent = 0): void
    {
        $totalLevels = $this->baseIndent + $indent;

        if ($line === '') {
            $this->buffer->append('');

            return;
        }

        $prefix = $totalLevels > 0
            ? str_repeat(self::INDENT_UNIT, $totalLevels)
            : '';

        $this->buffer->append($prefix . $line);
    }

    /**
     * Append an empty line to produce a visual separator between code blocks.
     *
     * Equivalent to `addLine('')` but more expressive at the call-site.
     */
    public function addBlankLine(): void
    {
        $this->buffer->append('');
    }

    // -------------------------------------------------------------------------
    // Indentation
    // -------------------------------------------------------------------------

    /**
     * Return a new {@see CodeBuilder} with a higher base-indentation level
     * that still appends into the *same* underlying line buffer.
     *
     * Lines added through the returned instance appear in the buffer at the
     * correct position relative to lines added before and after through the
     * parent instance, because all instances share one {@see \ArrayObject}.
     *
     * @param  int  $levels Number of extra indentation levels to add.
     * @return self         A new builder sharing this builder's buffer.
     */
    public function indent(int $levels = 1): self
    {
        return new self($this->baseIndent + $levels, $this->buffer);
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Join all buffered lines into a single string separated by newline characters.
     *
     * The returned string does **not** end with a trailing newline; callers that
     * embed the result into a larger file should handle that themselves.
     *
     * @return string The complete, indented code block.
     */
    public function build(): string
    {
        return implode("\n", (array) $this->buffer);
    }

    // -------------------------------------------------------------------------
    // Static export helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a compact, single-line PHP array literal for the given array.
     *
     * Both list-style and associative arrays are handled. Nested arrays are
     * rendered inline recursively. Use {@see PhpFile::renderConstantValue()}
     * (or the equivalent) when multi-line output is preferred for readability.
     *
     * Examples:
     * ```
     * arrayExport([])                        // '[]'
     * arrayExport(['a', 'b'])                // "['a', 'b']"
     * arrayExport(['k' => 1, 'j' => 2])     // "['k' => 1, 'j' => 2]"
     * arrayExport(['x' => ['y', 'z']])       // "['x' => ['y', 'z']]"
     * ```
     *
     * @param  array<mixed> $data The array to export.
     * @return string             A valid PHP array literal expression.
     */
    public static function arrayExport(array $data): string
    {
        if ($data === []) {
            return '[]';
        }

        $isList = array_is_list($data);
        $parts  = [];

        foreach ($data as $key => $value) {
            $exportedValue = is_array($value)
                ? self::arrayExport($value)
                : self::valueExport($value);

            $parts[] = $isList
                ? $exportedValue
                : self::valueExport($key) . ' => ' . $exportedValue;
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Export a scalar PHP value as a valid PHP literal string.
     *
     * Produces human-readable output:
     * - `null`  → `'null'`
     * - `true`  → `'true'`
     * - `false` → `'false'`
     * - integers → plain decimal representation
     * - floats   → decimal with at least one digit after the point; special
     *              IEEE 754 values (`INF`, `-INF`, `NAN`) are exported as the
     *              corresponding PHP constants.
     * - strings  → single-quoted with `\` and `'` escaped.
     * - arrays   → delegated to {@see arrayExport()}.
     *
     * @param  mixed  $value Any exportable PHP value (scalar, null, or array).
     *
     * @throws \InvalidArgumentException When the value type cannot be exported
     *                                   as a PHP literal (e.g. objects, resources).
     */
    public static function valueExport(mixed $value): string
    {
        return match (true) {
            $value === null   => 'null',
            is_bool($value)   => $value ? 'true' : 'false',
            is_int($value)    => (string) $value,
            is_float($value)  => self::exportFloat($value),
            is_string($value) => self::exportString($value),
            is_array($value)  => self::arrayExport($value),
            default           => throw new \InvalidArgumentException(
                sprintf(
                    'Cannot export a value of type "%s" as a PHP literal.',
                    get_debug_type($value),
                ),
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Export a float value as a PHP literal, handling IEEE 754 special cases.
     *
     * Ensures the rendered literal is unambiguously a float (contains `.` or `E`)
     * so that PHP does not accidentally parse it as an integer.
     */
    private static function exportFloat(float $value): string
    {
        if (is_infinite($value)) {
            return $value > 0.0 ? 'INF' : '-INF';
        }

        if (is_nan($value)) {
            return 'NAN';
        }

        $str = (string) $value;

        // PHP's (string) cast may produce "1" for 1.0; ensure float syntax.
        return (str_contains($str, '.') || str_contains($str, 'E'))
            ? $str
            : $str . '.0';
    }

    /**
     * Export a string value as a single-quoted PHP literal.
     *
     * Only `\` (backslash) and `'` (single quote) need escaping inside
     * single-quoted strings.
     */
    private static function exportString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
