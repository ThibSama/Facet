<?php

declare(strict_types=1);

use Facet\Config\Config;
use Facet\Support\ViteManifest;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$config = Config::fromEnvironment($basePath);

// Fail loudly rather than boot a production instance with missing secrets.
if ($config->isProduction()) {
    $config->require('APP_KEY');
}

ini_set('display_errors', $config->isDebug() ? '1' : '0');
error_reporting(E_ALL);

$manifestPath = $basePath . '/public/build/manifest.json';
$scripts = [];
$styles = [];

if (is_readable($manifestPath)) {
    $manifest = ViteManifest::fromFile($manifestPath);

    if ($manifest->has('resources/js/app.ts')) {
        $scripts[] = $manifest->script('resources/js/app.ts');
        $styles = $manifest->styles('resources/js/app.ts');
    }
}

$appName = $config->get('APP_NAME', 'Facet');

?>
<!doctype html>
<html lang="<?= htmlspecialchars($config->get('APP_LOCALE', 'en') ?? 'en', ENT_QUOTES) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName ?? 'Facet', ENT_QUOTES) ?></title>
    <?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($style, ENT_QUOTES) ?>">
    <?php endforeach; ?>
</head>
<body class="min-h-screen bg-white text-slate-900 antialiased">
    <main class="mx-auto max-w-2xl px-6 py-24">
        <h1 class="text-3xl font-semibold tracking-tight"><?= htmlspecialchars($appName ?? 'Facet', ENT_QUOTES) ?></h1>
        <p class="mt-4 text-slate-600">
            Server-rendered PHP foundation. Environment:
            <code><?= htmlspecialchars($config->environment(), ENT_QUOTES) ?></code>.
        </p>
    </main>
    <?php foreach ($scripts as $script): ?>
    <script type="module" src="<?= htmlspecialchars($script, ENT_QUOTES) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
