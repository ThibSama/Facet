<?php

/**
 * Failure-injection fixture: a template that throws mid-render.
 *
 * The message deliberately carries a fake secret and a fake absolute path, so a
 * test can assert the production error page contains neither. Nothing here is
 * reachable from the production registry.
 */

declare(strict_types=1);

use Facet\Tests\Fixtures\ExplodingSkin;

throw new RuntimeException(sprintf(
    'Database connection failed using %s while reading %s',
    ExplodingSkin::FAKE_SECRET,
    ExplodingSkin::FAKE_PATH
));
