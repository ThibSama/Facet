<?php

declare(strict_types=1);

namespace Facet\Content;

/**
 * What a link points at, so a template can order or group links without
 * hard-coding URLs.
 */
enum LinkType: string
{
    case Repository = 'repository';
    case LiveSite = 'live-site';
    case Website = 'website';
    case Document = 'document';
    case Profile = 'profile';
}
