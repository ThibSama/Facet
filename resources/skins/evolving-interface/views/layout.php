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
 * @var \Facet\Html\ViewContext    $view
 * @var \Facet\Asset\AssetBundle   $assets
 * @var \Facet\Skin\SkinDefinition $skin
 * @var \Facet\Html\Html           $content
 * @var string                     $appName
 * @var string                     $locale
 * @var string                     $path
 * @var string|null                $title
 */

declare(strict_types=1);

$documentTitle = isset($title) && is_string($title) && $title !== ''
    ? $title . ' — ' . $appName
    : $appName;

$navigation = [
    '/' => 'Home',
    '/projects' => 'Projects',
    '/about' => 'About',
    '/contact' => 'Contact',
];

?>
<!doctype html>
<html lang="<?= $view->attr($locale) ?>" data-skin="<?= $view->attr($skin->id()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $view->text($documentTitle) ?></title>
    <?php foreach ($assets->styles() as $style): ?>
    <link rel="stylesheet" href="<?= $view->url($style) ?>">
    <?php endforeach; ?>
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    <a class="sr-only focus:not-sr-only" href="#main">Skip to content</a>
    <header class="border-b border-slate-200">
        <nav class="mx-auto flex max-w-3xl flex-wrap gap-4 px-6 py-4 text-sm" aria-label="Primary">
            <?php foreach ($navigation as $href => $label): ?>
            <a
                class="hover:underline"
                href="<?= $view->url($href) ?>"
                <?= $view->attributes(['aria-current' => $path === $href ? 'page' : null]) ?>
            ><?= $view->text($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <main id="main" class="mx-auto max-w-3xl px-6 py-16">
        <?= $view->raw($content) ?>
    </main>
    <footer class="mx-auto max-w-3xl px-6 py-12 text-sm text-slate-500">
        <p><?= $view->text($appName) ?></p>
    </footer>
    <?php foreach ($assets->scripts() as $script): ?>
    <script type="module" src="<?= $view->url($script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
