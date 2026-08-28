<?php

declare(strict_types=1);

use Facet\Support\DotEnv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Load the local integration-test credentials, when they exist.
 *
 * `.env.testing` is gitignored and machine-local: it points the suite at a
 * disposable MariaDB instance. Without it the database tests have nothing to
 * connect to and report themselves as skipped — a skip is not a pass, and the
 * database gates are only meaningful on a machine where this file is present.
 */
DotEnv::load(dirname(__DIR__) . '/.env.testing');
