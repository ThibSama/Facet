<?php

/**
 * evolving-interface — shell header.
 *
 * Rendered identically for every route, including error pages: the header is
 * part of the shell, not of any page. It emits three things — the brand link,
 * the two enhanced controls, and the navigation landmark.
 *
 * The brand carries one bare data attribute and nothing else. It is a hook,
 * not a behaviour: the link is a complete, working home link with JavaScript
 * off, and what the skin runtime attaches to it — a discovery gesture nobody
 * has to find — is additive by construction. See `enhanceRun` in skin.ts.
 *
 * Both controls ship `hidden`. That is the no-JavaScript contract in one
 * attribute: the collapse behaviour and the theme switch only exist once the
 * shared runtime is running, so until it removes `hidden` the document shows a
 * plain, complete, always-visible navigation and follows the operating system
 * colour scheme. A control that cannot do anything is never presented.
 *
 * The theme control names itself once, and never in ink. Its four spans are
 * a scene — sky, stars, clouds, and the body that crosses between them — and
 * `aria-hidden` is stated once, on the wrapper, which takes the whole subtree
 * out of the accessibility tree with it. A sun is a picture of a state and not
 * a name for one, and repeating the attribute on each child would only be four
 * more places for it to be forgotten. The name is the word the button still carries: the
 * text stays in the markup and is taken out of view by the stylesheet, so the
 * accessible name is unchanged, `aria-pressed` still says which theme is in
 * effect, and the control reads to assistive technology exactly as the pill
 * with the word in it did. What changed is what it looks like, and nothing
 * else. See `enhanceThemeControls` in resources/js/theme.ts.
 *
 * The collapse control names itself twice on purpose. It carries the word as
 * text *and* as `aria-label`, which is what lets the skin drop the visible
 * word at the width where the control is only ever an icon without the button
 * losing its accessible name. The two say the same thing, so the name a
 * screen reader announces is the name a sighted reader would have read.
 *
 * The language switch sits between the brand and the two enhanced controls,
 * which is where it belongs on both axes: it is navigation, like the nav
 * landmark, and it is a shell-level choice, like the theme. It is a pair of
 * plain links and is never hidden, so the one control in the header that works
 * with no JavaScript at all is the one that changes the language of the page.
 *
 * @var \Facet\Html\ViewContext          $view
 * @var \Facet\Navigation\Navigation     $navigation
 * @var \Facet\Navigation\LanguageSwitch $languageSwitch
 * @var \Facet\I18n\Translator           $t
 * @var \Facet\I18n\Locale               $locale
 * @var string                           $appName
 * @var string                           $navigationId
 */

declare(strict_types=1);

use Facet\I18n\LocalizedRoutes;
use Facet\Routing\RouteCatalog;

/*
 * The brand is the home link of the language being read, never the unprefixed
 * entry route. A visitor on `/en/about` who clicks the mark asks for the
 * English home page, and sending them through a redirect that re-negotiates a
 * language they have already chosen would be the shell second-guessing them.
 *
 * It is also what keeps the five-click gesture working: the gesture only counts
 * a click whose destination is the page already on screen, so on `/en` the mark
 * points at `/en` and the sequence can begin, while on every other route the
 * first click simply leaves.
 */
$brandHref = LocalizedRoutes::path(RouteCatalog::HOME, $locale);

/*
 * The run's own chrome, handed to the client as data.
 *
 * The game is deferred and mounts from a chunk that is not loaded until the
 * gesture completes, so it cannot ask the server for anything. Its labels
 * therefore travel with the document, on the element the gesture is attached
 * to — one attribute, read once by `enhanceRun`, passed straight into the run's
 * options. Nothing about gameplay, physics or the world knows a language.
 */
$runLabels = [
    'jump' => $t->text('run.jump'),
    'duck' => $t->text('run.duck'),
    'restart' => $t->text('run.restart'),
    'close' => $t->text('run.close'),
    'score' => $t->text('run.score'),
    'best' => $t->text('run.best'),
    'ready' => $t->text('run.ready'),
    'over' => $t->text('run.over'),
    'record' => $t->text('run.record'),
];

?>
    <header class="facet-header" data-facet-header>
        <div class="facet-header__inner facet-shell">
            <a
                class="facet-brand"
                href="<?= $view->url($brandHref) ?>"
                data-facet-brand
                data-facet-run-labels="<?= $view->attr((string) $view->json($runLabels)) ?>"
            ><?= $view->text($appName) ?></a>

            <?php require __DIR__ . '/language-switch.php'; ?>

            <button
                class="facet-nav-toggle"
                type="button"
                hidden
                data-facet-nav-toggle
                aria-controls="<?= $view->attr($navigationId) ?>"
                aria-expanded="true"
                aria-label="<?= $view->attr($t->text('shell.menu')) ?>"
            >
                <span class="facet-nav-toggle__glyph" aria-hidden="true"></span>
                <span class="facet-nav-toggle__text"><?= $view->text($t->text('shell.menu')) ?></span>
            </button>

            <button
                class="facet-theme-toggle"
                type="button"
                hidden
                data-facet-theme-toggle
                aria-pressed="false"
            >
                <span class="facet-theme-toggle__scene" aria-hidden="true">
                    <span class="facet-theme-toggle__stars"></span>
                    <span class="facet-theme-toggle__clouds"></span>
                    <span class="facet-theme-toggle__orb"></span>
                </span>
                <span class="facet-theme-toggle__text"><?= $view->text($t->text('shell.themeToggle')) ?></span>
            </button>

            <?php require __DIR__ . '/nav.php'; ?>
        </div>
    </header>
