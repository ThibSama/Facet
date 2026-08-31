<?php

/**
 * fixture-unselected — logical view "page.error".
 *
 * Present so the fixture answers the same logical views the real skin does:
 * a test that swaps skins must exercise the same code path, error pages
 * included.
 *
 * @var \Facet\Html\ViewContext    $view
 * @var \Facet\Skin\SkinDefinition $skin
 * @var int                        $status
 * @var string                     $title
 * @var string                     $message
 * @var \Facet\I18n\Locale        $locale
 */

declare(strict_types=1);

?>
<!doctype html>
<html lang="<?= $view->attr($locale->htmlLang()) ?>" data-skin="<?= $view->attr($skin->id()) ?>">
<head>
    <meta charset="utf-8">
    <title><?= $view->text($title) ?></title>
</head>
<body>
    <main>
        <h1><?= $view->text($title) ?></h1>
        <p><?= $view->text($message) ?></p>
        <p><a href="<?= $view->url('/') ?>">Home</a></p>
    </main>
</body>
</html>
