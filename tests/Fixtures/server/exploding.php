<?php

declare(strict_types=1);

use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\ResponseEmitter;
use Facet\Tests\Fixtures\ExplodingSkin;

/**
 * A front controller that is public/index.php in every respect except one: the
 * skin it boots with renders by throwing.
 *
 * It exists so disclosure safety can be smoke-tested over real HTTP, with a
 * real SAPI and real headers, without putting a failure switch into the
 * production entrypoint.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$basePath = dirname(__DIR__, 3);
$config = Config::fromEnvironment($basePath);

if ($config->isProduction()) {
    $config->require('APP_KEY');
}

ini_set('display_errors', $config->isDebug() ? '1' : '0');
error_reporting(E_ALL);

(new ResponseEmitter())->emit(
    Application::boot($basePath, $config, ExplodingSkin::registry())->handle(
        Request::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE)
    )
);
