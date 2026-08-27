<?php

/**
 * evolving-interface — shell header.
 *
 * Rendered identically for every route, including error pages: the header is
 * part of the shell, not of any page. It emits three things — the brand link,
 * the two enhanced controls, and the navigation landmark.
 *
 * Both controls ship `hidden`. That is the no-JavaScript contract in one
 * attribute: the collapse behaviour and the theme switch only exist once the
 * shared runtime is running, so until it removes `hidden` the document shows a
 * plain, complete, always-visible navigation and follows the operating system
 * colour scheme. A control that cannot do anything is never presented.
 *
 * @var \Facet\Html\ViewContext          $view
 * @var \Facet\Navigation\Navigation     $navigation
 * @var string                           $appName
 * @var string                           $navigationId
 */

declare(strict_types=1);

?>
    <header class="facet-header" data-facet-header>
        <div class="facet-header__inner facet-shell">
            <a class="facet-brand" href="<?= $view->url('/') ?>"><?= $view->text($appName) ?></a>

            <button
                class="facet-nav-toggle"
                type="button"
                hidden
                data-facet-nav-toggle
                aria-controls="<?= $view->attr($navigationId) ?>"
                aria-expanded="true"
            >
                <span class="facet-nav-toggle__glyph" aria-hidden="true"></span>
                <span class="facet-nav-toggle__text">Menu</span>
            </button>

            <button
                class="facet-theme-toggle"
                type="button"
                hidden
                data-facet-theme-toggle
                aria-pressed="false"
            >
                <span class="facet-theme-toggle__glyph" aria-hidden="true"></span>
                <span class="facet-theme-toggle__text">Dark theme</span>
            </button>

            <?php require __DIR__ . '/nav.php'; ?>
        </div>
    </header>
