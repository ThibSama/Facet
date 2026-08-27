<?php

declare(strict_types=1);

use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\ResponseEmitter;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$config = Config::fromEnvironment($basePath);

// Fail loudly rather than boot a production instance with missing secrets.
// This runs before display_errors is clamped so the failure is never silent.
if ($config->isProduction()) {
    $config->require('APP_KEY');
}

// PHP must never print a diagnostic itself: everything the visitor sees is
// composed by the application, which decides disclosure in one place.
ini_set('display_errors', $config->isDebug() ? '1' : '0');
error_reporting(E_ALL);

// The entrypoint's whole job: turn PHP's request arrays into a Request, hand it
// to the application, and put the Response it returns on the wire. Routing,
// skin selection, rendering and error disclosure all live behind that call.
(new ResponseEmitter())->emit(
    Application::boot($basePath, $config)->handle(
        Request::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE)
    )
);
