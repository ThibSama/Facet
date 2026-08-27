<?php

declare(strict_types=1);

namespace Facet\Html;

use Stringable;

/**
 * The escaping surface every template renders through.
 *
 * {@see \Facet\Skin\SkinRenderer} injects one of these as `$view` into every
 * template, and the skin-safety test asserts that no template echoes anything
 * except through it. That combination is what makes "escaped by default" a
 * property of the codebase rather than a convention: the short, obvious way to
 * print a value escapes it, and printing raw markup requires constructing an
 * {@see Html} on purpose.
 */
final class ViewContext
{
    /**
     * Escaped element text — the default way to print anything.
     */
    public function text(string|int|float|Stringable|null $value): string
    {
        return Escaper::text($value);
    }

    /**
     * Escaped attribute value.
     */
    public function attr(string|int|float|Stringable|null $value): string
    {
        return Escaper::attribute($value);
    }

    /**
     * Escaped href/src value with an executable-scheme guard.
     */
    public function url(string|int|float|Stringable|null $value): string
    {
        return Escaper::url($value);
    }

    /**
     * Emits pre-composed markup verbatim.
     *
     * The parameter type is the whole point: a string will not type-check here,
     * so raw output is always a deliberate {@see Html::trusted()} call.
     */
    public function raw(Html $html): string
    {
        return $html->markup();
    }

    /**
     * Escapes and joins a list for display, e.g. a tag row.
     *
     * @param list<string|int|float|Stringable> $values
     */
    public function join(array $values, string $separator = ', '): string
    {
        return implode(
            Escaper::text($separator),
            array_map(static fn (string|int|float|Stringable $v): string => Escaper::text($v), $values)
        );
    }

    /**
     * Renders an attribute list, skipping null values.
     *
     * @param array<string, string|int|float|Stringable|null> $attributes
     */
    public function attributes(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $name => $value) {
            if ($value === null || preg_match('/^[a-zA-Z][a-zA-Z0-9:_.-]*$/', $name) !== 1) {
                continue;
            }

            $parts[] = $name . '="' . Escaper::attribute($value) . '"';
        }

        return implode(' ', $parts);
    }
}
