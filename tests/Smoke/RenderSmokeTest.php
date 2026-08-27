<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Boots public/index.php through the PHP CLI server-less entrypoint and
 * asserts it renders valid server-side HTML without Node present.
 */
final class RenderSmokeTest extends TestCase
{
    public function testEntrypointRendersHtml(): void
    {
        $root = dirname(__DIR__, 2);

        $command = sprintf(
            'APP_NAME=Facet APP_ENV=local APP_KEY=test-key %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/public/index.php')
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);
        $html = implode("\n", $output);

        self::assertSame(0, $status, 'Entrypoint exited non-zero: ' . $html);
        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('Facet', $html);
        self::assertStringNotContainsString('Fatal error', $html);
    }

    public function testProductionBootRefusesToStartWithoutSensitiveValue(): void
    {
        $root = dirname(__DIR__, 2);

        $command = sprintf(
            'APP_ENV=production APP_KEY= %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/public/index.php')
        );

        $output = [];
        $status = 0;
        exec($command, $output, $status);
        $result = implode("\n", $output);

        self::assertNotSame(0, $status, 'Production boot must fail when APP_KEY is unset');
        self::assertStringContainsString('APP_KEY', $result);
        self::assertStringNotContainsString('<!doctype html>', $result);
    }
}
