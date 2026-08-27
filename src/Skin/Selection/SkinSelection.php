<?php

declare(strict_types=1);

namespace Facet\Skin\Selection;

use Facet\Skin\SkinDefinition;

/**
 * The outcome of a selection policy: which skin, and why.
 */
final class SkinSelection
{
    private SkinDefinition $skin;

    private SkinSelectionSource $source;

    private function __construct(SkinDefinition $skin, SkinSelectionSource $source)
    {
        $this->skin = $skin;
        $this->source = $source;
    }

    public static function of(SkinDefinition $skin, SkinSelectionSource $source): self
    {
        return new self($skin, $source);
    }

    public static function default(SkinDefinition $skin): self
    {
        return new self($skin, SkinSelectionSource::Default);
    }

    public function skin(): SkinDefinition
    {
        return $this->skin;
    }

    public function source(): SkinSelectionSource
    {
        return $this->source;
    }

    public function isExplicit(): bool
    {
        return $this->source->isExplicit();
    }

    /**
     * Whether a caller should carry this choice into subsequent requests.
     *
     * The transport (a cookie today, a session tomorrow) is deliberately not
     * decided here: this is the seam that lets a skin survive navigation
     * without any route ever being rewritten to carry it.
     */
    public function shouldPersist(): bool
    {
        return $this->source === SkinSelectionSource::Requested
            || $this->source === SkinSelectionSource::Policy;
    }
}
