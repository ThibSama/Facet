<?php

declare(strict_types=1);

namespace Facet\Skin;

use InvalidArgumentException;

/**
 * Raised when a skin id is asked for that the registry does not declare.
 *
 * Unknown ids are always an explicit failure here; callers that want a
 * fallback ask the registry for one rather than relying on a null slipping
 * through.
 */
final class UnknownSkinException extends InvalidArgumentException
{
    /**
     * @param list<string> $known
     */
    public static function forId(string $id, array $known): self
    {
        return new self(sprintf(
            'Unknown skin "%s". Registered skins: %s.',
            $id,
            $known === [] ? '(none)' : implode(', ', $known)
        ));
    }
}
