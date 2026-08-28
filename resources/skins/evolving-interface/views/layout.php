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
 * @var string                     $locale
 * @var string                     $path
 * @var string|null                $title
 * @var \Facet\Navigation\Navigation|null $navigation
 */

declare(strict_types=1);

use Facet\Navigation\Navigation;

$documentTitle = isset($title) && is_string($title) && $title !== ''
    ? $title . ' — ' . $appName
    : $appName;

$currentPath = isset($path) && is_string($path) ? $path : '/';

// Shared data supplies the navigation for every dispatched request. The
// fallback exists so rendering a view directly — a test, a preview — still
// produces a complete shell instead of a header with a hole in it.
$navigation = ($navigation ?? null) instanceof Navigation
    ? $navigation
    : Navigation::primary($currentPath);

$navigationId = 'facet-primary-nav';

?>
<!doctype html>
<html lang="<?= $view->attr($locale) ?>" data-skin="<?= $view->attr($skin->id()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <?php if (($noIndex ?? false) === true): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= $view->text($documentTitle) ?></title>
    <?php require __DIR__ . '/partials/theme-bootstrap.php'; ?>
    <?php foreach ($assets->styles() as $style): ?>
    <link rel="stylesheet" href="<?= $view->url($style) ?>">
    <?php endforeach; ?>
</head>
<body class="facet-body" data-route="<?= $view->attr($currentPath) ?>">
    <a class="facet-skip-link" href="#main">Skip to content</a>
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
