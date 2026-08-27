<?php

declare(strict_types=1);

use Facet\Config\Config;
use Facet\Http\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$config = Config::fromEnvironment($basePath);

// Fail loudly rather than boot a production instance with missing secrets.
// This runs before display_errors is clamped so the failure is never silent.
if ($config->isProduction()) {
    $config->require('APP_KEY');
}

ini_set('display_errors', $config->isDebug() ? '1' : '0');
error_reporting(E_ALL);

// Everything else — skin selection, asset resolution, rendering — belongs to
// the application, which this file only adapts the request into.
echo Application::boot($basePath, $config)->handle($_GET, $_COOKIE);
