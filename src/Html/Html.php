<?php

declare(strict_types=1);

namespace Facet\Html;

use Stringable;

/**
 * Markup that has been explicitly declared trusted.
 *
 * This type is the *only* way raw HTML reaches a rendered document: templates
 * echo through {@see ViewContext::raw()}, which accepts this class and nothing
 * else. A plain string — the shape every piece of user input, config value and
 * corpus field actually has — is a TypeError there, so "raw by accident" is not
 * an available mistake, and every `Html::trusted()` call site is greppable.
 */
final class Html implements Stringable
{
    private string $markup;

    private function __construct(string $markup)
    {
        $this->markup = $markup;
    }

    /**
     * Declares markup safe to emit verbatim.
     *
     * Call this only with markup the application itself composed. Passing
     * request input, corpus text or configuration through it defeats the entire
     * escaping contract.
     */
    public static function trusted(string $markup): self
    {
        return new self($markup);
    }

    /**
     * Escapes a value and wraps the result, for building trusted markup out of
     * untrusted parts without a second escaping pass.
     */
    public static function escaping(string|int|float|Stringable|null $value): self
    {
        return new self(Escaper::text($value));
    }

    public function markup(): string
    {
        return $this->markup;
    }

    public function __toString(): string
    {
        return $this->markup;
    }
}
