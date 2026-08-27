<?php

/**
 * evolving-interface — pre-paint theme bootstrap.
 *
 * The only inline script in the document, and deliberately the smallest thing
 * that can do its job: read the one stored preference and stamp it on <html>
 * before the first paint, so a visitor who chose dark does not see a white
 * flash while the module bundle loads.
 *
 * Everything about it is defensive. It reads a single non-sensitive key from
 * localStorage — never a cookie, never anything sent to the server — accepts
 * only the two values it understands, and swallows any storage failure
 * (private mode, disabled site data, a sandboxed frame) because a preference
 * that cannot be read is not a reason to fail rendering. With JavaScript off
 * nothing here runs, and the stylesheet's `prefers-color-scheme` rules remain
 * in charge.
 *
 * @var \Facet\Html\ViewContext $view
 */

declare(strict_types=1);

?>
    <script>
        (function () {
            try {
                var stored = window.localStorage.getItem('facet.theme');

                if (stored === 'light' || stored === 'dark') {
                    document.documentElement.setAttribute('data-theme', stored);
                }
            } catch (error) {
                /* No stored preference is readable: system preference stands. */
            }
        })();
    </script>
