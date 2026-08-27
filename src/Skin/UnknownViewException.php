<?php

declare(strict_types=1);

namespace Facet\Skin;

use RuntimeException;

/**
 * Raised when a skin cannot answer a logical view identifier.
 *
 * Shared code names views logically ("page.home"); if the selected skin has no
 * template for one, that is a skin defect and must fail loudly instead of
 * rendering a blank page.
 */
final class UnknownViewException extends RuntimeException
{
    public static function forView(string $skinId, string $view, string $expectedPath): self
    {
        return new self(sprintf(
            'Skin "%s" declares no template for logical view "%s" (looked for "%s").',
            $skinId,
            $view,
            $expectedPath
        ));
    }

    public static function malformed(string $view): self
    {
        return new self(sprintf(
            'Logical view "%s" is malformed. Expected dot-separated lowercase segments, e.g. "page.home".',
            $view
        ));
    }
}
