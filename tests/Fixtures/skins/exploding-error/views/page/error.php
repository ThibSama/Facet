<?php

/**
 * Failure-injection fixture: an error view that is itself broken.
 *
 * Rendering an error page is a moving part like any other. This fixture makes
 * that part fail on purpose so a test can prove the presenter still answers
 * with a valid document instead of recursing into a second failure.
 */

declare(strict_types=1);

throw new RuntimeException('The error template is broken too.');
