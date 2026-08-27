<?php

declare(strict_types=1);

namespace Facet\Routing;

/**
 * HTTP methods a canonical route may accept.
 *
 * Deliberately narrow: Facet is a server-rendered site, so the contract only
 * needs safe reads and classic form submissions.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';

    public function isSafe(): bool
    {
        return $this === self::Get;
    }
}
