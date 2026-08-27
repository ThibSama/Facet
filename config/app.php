<?php

declare(strict_types=1);

use Facet\Config\Config;

/**
 * Resolved application settings.
 *
 * Non-sensitive values may carry a safe default; sensitive ones are read via
 * Config::require() and therefore throw when absent instead of degrading.
 */
return static function (Config $config): array {
    return [
        'name' => $config->get('APP_NAME', 'Facet'),
        'env' => $config->environment(),
        'debug' => $config->isDebug(),
        'url' => $config->get('APP_URL', 'http://localhost:8000'),
        'locale' => $config->get('APP_LOCALE', 'en'),
    ];
};
