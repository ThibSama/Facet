<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Content\Exception\InvalidContentException;

/**
 * An optional illustration for a content entry.
 *
 * Media is optional by design: the corpus is complete and every page is
 * renderable before a single final image exists. When no source is set, the
 * entry still carries a mandatory textual description and resolves to a stable
 * fallback reference, so a skin never has to invent one and never renders a
 * broken image.
 */
final class Media
{
    /**
     * Logical reference a skin maps to whatever placeholder it ships.
     * It is intentionally not a file path: the corpus must not depend on any
     * skin's asset layout.
     */
    public const FALLBACK_REFERENCE = 'placeholder';

    private ?string $source;

    private string $description;

    private function __construct(?string $source, string $description)
    {
        $this->source = $source;
        $this->description = $description;
    }

    /**
     * @param string|null $source logical, skin-independent media reference
     *
     * @throws InvalidContentException when the textual description is empty
     */
    public static function create(?string $source, string $description): self
    {
        if (trim($description) === '') {
            throw InvalidContentException::because(
                'media',
                'a textual description is required even when no image exists'
            );
        }

        if ($source !== null && trim($source) === '') {
            throw InvalidContentException::because('media', 'a media source must be null or non-empty');
        }

        return new self($source, $description);
    }

    /**
     * Media that is known to have no image yet.
     */
    public static function pending(string $description): self
    {
        return self::create(null, $description);
    }

    public function hasSource(): bool
    {
        return $this->source !== null;
    }

    public function isFallback(): bool
    {
        return $this->source === null;
    }

    /**
     * Always safe to render: the real source when present, the fallback
     * reference otherwise.
     */
    public function reference(): string
    {
        return $this->source ?? self::FALLBACK_REFERENCE;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * The accessible textual equivalent. Never empty.
     */
    public function description(): string
    {
        return $this->description;
    }
}
