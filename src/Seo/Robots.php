<?php

declare(strict_types=1);

namespace Facet\Seo;

/** A permissive crawl policy; private/error indexing is controlled explicitly. */
final class Robots
{
    public static function render(SiteUrl $siteUrl): string
    {
        return "User-agent: *\n"
            . "Disallow:\n"
            . 'Sitemap: ' . $siteUrl->absolute('/sitemap.xml') . "\n";
    }
}
