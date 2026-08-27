<?php

/**
 * fixture-unselected — logical view "page.home".
 *
 * Test fixture, never reachable through the production registry. It exists so
 * a test can select this skin explicitly and observe that the swap is real:
 * different markup, different assets, same route and same content.
 *
 * @var \Facet\Asset\AssetBundle   $assets
 * @var \Facet\Skin\SkinDefinition $skin
 * @var string $appName
 * @var string $locale
 */

declare(strict_types=1);

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);

?>
<!doctype html>
<html lang="<?= $e($locale) ?>" data-skin="<?= $e($skin->id()) ?>">
<head>
    <meta charset="utf-8">
    <title><?= $e($appName) ?></title>
    <?php foreach ($assets->styles() as $style): ?>
    <link rel="stylesheet" href="<?= $e($style) ?>">
    <?php endforeach; ?>
</head>
<body>
    <main><h1><?= $e($appName) ?></h1></main>
    <?php foreach ($assets->scripts() as $script): ?>
    <script type="module" src="<?= $e($script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
