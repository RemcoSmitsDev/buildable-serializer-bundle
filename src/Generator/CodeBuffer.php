<?php

declare(strict_types=1);

namespace Buildable\SerializerBundle\Generator;

/**
 * Lightweight indentation-aware line buffer for PHP source generation.
 *
 * Accumulates lines of PHP source code at a tracked indentation depth and
 * renders them as a single newline-separated string via {@see __toString()}.
 *
 * Example usage:
 *
 *     $buf = new CodeBuffer();
 *     $buf->line('public function normalize(): array');
 *     $buf->line('{');
 *     $buf->indent();
 *     $buf->line('return [];');
 *     $buf->outdent();
 *     $buf->line('}');
 *
 *     echo $buf; // or (string) $buf
 */
final class CodeBuffer implements \Stringable
{
    private const INDENT = '    ';

    /** @var list<string> */
    private array $lines = [];

    private int $depth = 0;

    /**
     * Append a single line at the current indentation depth.
     *
     * An empty $text value produces a blank line without any leading
     * whitespace (avoids invisible trailing spaces on empty lines).
     */
    public function line(string $text): self
    {
        $this->lines[] = $text !== '' ? str_repeat(self::INDENT, $this->depth) . $text : '';

        return $this;
    }

    /**
     * Append a blank / empty line (no leading whitespace).
     *
     * Equivalent to `$buf->line('')` but communicates intent more clearly
     * at the call site.
     */
    public function blank(): self
    {
        $this->lines[] = '';

        return $this;
    }

    /**
     * Increase the indentation level by one step.
     */
    public function indent(): self
    {
        ++$this->depth;

        return $this;
    }

    /**
     * Decrease the indentation level by one step (clamped to zero).
     */
    public function outdent(): self
    {
        $this->depth = max(0, $this->depth - 1);

        return $this;
    }

    /**
     * Return all accumulated lines joined with newlines, terminated by a
     * trailing newline character.
     */
    public function __toString(): string
    {
        return implode("\n", $this->lines) . "\n";
    }
}
