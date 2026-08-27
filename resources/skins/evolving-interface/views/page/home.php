<?php

/**
 * evolving-interface — logical view "page.home".
 *
 * Scaffolding that proves the boundary, not the final design: it renders only
 * values the shared runtime handed it and reads nothing from the content
 * corpus directly. The document references exactly the asset URLs in the
 * bundle it was given, which is what keeps skin isolation observable.
 *
 * @var \Facet\Asset\AssetBundle  $assets
 * @var \Facet\Skin\SkinDefinition $skin
 * @var string $appName
 * @var string $locale
 * @var string $environment
 */

declare(strict_types=1);

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);

?>
<!doctype html>
<html lang="<?= $e($locale) ?>" data-skin="<?= $e($skin->id()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($appName) ?></title>
    <?php foreach ($assets->styles() as $style): ?>
    <link rel="stylesheet" href="<?= $e($style) ?>">
    <?php endforeach; ?>
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    <main class="mx-auto max-w-2xl px-6 py-24">
        <h1 class="text-3xl font-semibold tracking-tight"><?= $e($appName) ?></h1>
        <p class="mt-4 text-slate-600">
            Server-rendered PHP foundation. Environment:
            <code><?= $e($environment) ?></code>.
        </p>
    </main>
    <?php foreach ($assets->scripts() as $script): ?>
    <script type="module" src="<?= $e($script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
