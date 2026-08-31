<?php

/**
 * fixture-unselected — logical view "page.home".
 *
 * Test fixture, never reachable through the production registry. It exists so
 * a test can select this skin explicitly and observe that the swap is real:
 * different markup, different assets, same route and same content.
 *
 * @var \Facet\Html\ViewContext    $view
 * @var \Facet\Asset\AssetBundle   $assets
 * @var \Facet\Skin\SkinDefinition $skin
 * @var string                     $appName
 * @var \Facet\I18n\Locale        $locale
 */

declare(strict_types=1);

?>
<!doctype html>
<html lang="<?= $view->attr($locale->htmlLang()) ?>" data-skin="<?= $view->attr($skin->id()) ?>">
<head>
    <meta charset="utf-8">
    <title><?= $view->text($appName) ?></title>
    <?php foreach ($assets->styles() as $style): ?>
    <link rel="stylesheet" href="<?= $view->url($style) ?>">
    <?php endforeach; ?>
</head>
<body>
    <main><h1><?= $view->text($appName) ?></h1></main>
    <?php foreach ($assets->scripts() as $script): ?>
    <script type="module" src="<?= $view->url($script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
