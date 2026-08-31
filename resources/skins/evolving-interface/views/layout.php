<?php

/**
 * evolving-interface — document shell.
 *
 * A skin-private partial: nothing outside this directory knows it exists, and
 * shared code addresses only logical views. It emits exactly the asset URLs it
 * was handed, prints every value through `$view`, and produces a complete,
 * navigable document with no JavaScript involved — scripts are loaded last and
 * only decorate what the server already rendered.
 *
 * The shell itself is split into partials (header, nav, footer) so every
 * rendered route shares one structure rather than each page growing its own.
 * They are included, not rendered: the renderer resolves logical views, and a
 * partial is an implementation detail of this file.
 *
 * @var \Facet\Html\ViewContext    $view
 * @var \Facet\Asset\AssetBundle   $assets
 * @var \Facet\Skin\SkinDefinition $skin
 * @var \Facet\Html\Html           $content
 * @var string                     $appName
 * @var \Facet\I18n\Locale         $locale
 * @var \Facet\I18n\Translator     $t
 * @var string                     $path
 * @var string|null                $title
 * @var \Facet\Seo\SeoMetadata|null $seo
 * @var \Facet\Navigation\Navigation|null $navigation
 * @var \Facet\Navigation\LanguageSwitch|null $languageSwitch
 * @var string|null                $routeName
 */

declare(strict_types=1);

use Facet\Navigation\LanguageSwitch;
use Facet\Navigation\Navigation;
use Facet\Seo\SeoMetadata;

/*
 * The document's language, and the vocabulary it is written in.
 *
 * Both are handed down by the runtime for every dispatched request. The
 * fallbacks exist for the same reason the navigation's does — rendering a view
 * directly, in a test or a preview, still has to produce a complete document
 * rather than one with a hole in its `lang` attribute.
 */
require __DIR__ . '/partials/locale-context.php';

$seo = ($seo ?? null) instanceof SeoMetadata ? $seo : null;
$documentTitle = $seo?->title() ?? (isset($title) && is_string($title) && $title !== ''
    ? $title . ' — ' . $appName
    : $appName);

$currentPath = isset($path) && is_string($path) ? $path : '/';

// Shared data supplies the navigation for every dispatched request. The
// fallback exists so rendering a view directly — a test, a preview — still
// produces a complete shell instead of a header with a hole in it.
$navigation = ($navigation ?? null) instanceof Navigation
    ? $navigation
    : Navigation::primary($locale, $t, $currentPath);

$languageSwitch = ($languageSwitch ?? null) instanceof LanguageSwitch
    ? $languageSwitch
    : LanguageSwitch::create($locale, $t, null);

$navigationId = 'facet-primary-nav';

?>
<!doctype html>
<html lang="<?= $view->attr($locale->htmlLang()) ?>" data-skin="<?= $view->attr($skin->id()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <?php if (($noIndex ?? false) === true || ($seo !== null && !$seo->isIndexable())): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= $view->text($documentTitle) ?></title>
    <?php if ($seo?->description() !== null): ?>
    <meta name="description" content="<?= $view->attr($seo->description()) ?>">
    <?php endif; ?>
    <?php if ($seo?->canonicalUrl() !== null): ?>
    <link rel="canonical" href="<?= $view->url($seo->canonicalUrl()) ?>">
    <?php endif; ?>
    <?php
    /*
     * The same page in every language it exists in, plus `x-default`.
     *
     * The set comes from the metadata rather than from this file, so the
     * alternates a page advertises and the URLs its language switch links to
     * are one decision made once. A page with no counterpart — an error
     * document, a private surface — carries none, because claiming an
     * alternate that does not exist is worse than claiming nothing.
     */
    ?>
    <?php foreach ($seo?->alternates() ?? [] as $hreflang => $alternate): ?>
    <link rel="alternate" hreflang="<?= $view->attr($hreflang) ?>" href="<?= $view->url($alternate) ?>">
    <?php endforeach; ?>
    <?php if ($seo?->hasSocialGraph() === true): ?>
    <meta property="og:title" content="<?= $view->attr($seo->title()) ?>">
    <meta property="og:description" content="<?= $view->attr($seo->description()) ?>">
    <meta property="og:url" content="<?= $view->url($seo->canonicalUrl()) ?>">
    <meta property="og:type" content="<?= $view->attr($seo->openGraphType()) ?>">
    <meta property="og:locale" content="<?= $view->attr($seo->locale()->openGraphLocale()) ?>">
    <meta property="og:locale:alternate" content="<?= $view->attr($seo->locale()->counterpart()->openGraphLocale()) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= $view->attr($seo->title()) ?>">
    <meta name="twitter:description" content="<?= $view->attr($seo->description()) ?>">
    <?php endif; ?>
    <?php foreach ($seo?->structuredData() ?? [] as $structuredData): ?>
    <script type="application/ld+json"><?= $view->raw($view->json($structuredData)) ?></script>
    <?php endforeach; ?>
    <?php require __DIR__ . '/partials/theme-bootstrap.php'; ?>
    <?php foreach ($assets->styles() as $style): ?>
    <link rel="stylesheet" href="<?= $view->url($style) ?>">
    <?php endforeach; ?>
</head>
<?php
/*
 * Two hooks, and they say different things.
 *
 * `data-route` is the URL this document was served at — a debugging aid, and
 * exactly as specific as the address bar. `data-facet-route` is the *route* it
 * came from, named by the canonical catalog.
 *
 * The skin styles against the second. A URL is a spelling, and since PORT-137
 * every public page has two of them, so a selector keyed to a path would stop
 * applying the moment the path gained a language segment — which is precisely
 * what happened to the home composition when `/` became `/fr` and `/en`.
 * A route name never moves.
 */
?>
<body
    class="facet-body"
    data-route="<?= $view->attr($currentPath) ?>"
    <?= $view->attributes(['data-facet-route' => is_string($routeName ?? null) ? $routeName : null]) ?>
>
    <a class="facet-skip-link" href="#main"><?= $view->text($t->text('shell.skipToContent')) ?></a>
    <?php require __DIR__ . '/partials/header.php'; ?>
    <main id="main" class="facet-main facet-shell" tabindex="-1">
        <?= $view->raw($content) ?>
    </main>
    <?php require __DIR__ . '/partials/footer.php'; ?>
    <?php foreach ($assets->scripts() as $script): ?>
    <script type="module" src="<?= $view->url($script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
