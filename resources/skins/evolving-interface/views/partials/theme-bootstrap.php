<?php

/**
 * evolving-interface — pre-paint theme bootstrap.
 *
 * The only inline script in the document, and deliberately the smallest thing
 * that can do its job: decide which theme this visit opens in and stamp it on
 * <html> before the first paint, so nothing is ever shown in the wrong one
 * while the module bundle loads.
 *
 * It answers the question in the same two steps `resolvedTheme` in
 * resources/js/theme.ts does, because the two run milliseconds apart on the
 * same page and any disagreement between them would be a visible flash:
 *
 * 1. the stored choice, if the visitor has ever made one;
 * 2. otherwise the visitor's own clock — 07:00 to 19:59 is day, 20:00 to
 *    06:59 is night.
 *
 * The clock is read with `new Date().getHours()` and nothing else. No
 * geolocation, no address lookup, no sunrise table, no request of any kind:
 * the browser already knows what time it is where the reader is, and that is
 * the whole of the input. An hour that is somehow not an hour falls back to
 * light rather than guessing.
 *
 * Everything about it is defensive. It reads a single non-sensitive key from
 * localStorage — never a cookie, never anything sent to the server — accepts
 * only the two values it understands, and swallows any storage failure
 * (private mode, disabled site data, a sandboxed frame) because a preference
 * that cannot be read is not a reason to fail rendering. With JavaScript off
 * nothing here runs, and the stylesheet's `prefers-color-scheme` rules remain
 * in charge — the clock rule needs a clock reader, and the operating system's
 * own preference is the best answer available without one.
 *
 * @var \Facet\Html\ViewContext $view
 */

declare(strict_types=1);

?>
    <script>
        (function () {
            var theme = null;

            try {
                var stored = window.localStorage.getItem('facet.theme');

                if (stored === 'light' || stored === 'dark') {
                    theme = stored;
                }
            } catch (error) {
                /* No stored preference is readable: the clock decides. */
            }

            if (theme === null) {
                var hour = new Date().getHours();

                theme = hour >= 0 && hour <= 23
                    ? (hour >= 7 && hour < 20 ? 'light' : 'dark')
                    : 'light';
            }

            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
