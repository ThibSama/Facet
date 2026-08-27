<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Ensures .env.example stays complete and free of real secrets.
 */
final class EnvExampleTest extends TestCase
{
    private const PLACEHOLDERS = ['', 'changeme', 'null'];

    private static function example(): string
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/.env.example');
        self::assertIsString($raw, '.env.example exists');

        return $raw;
    }

    /**
     * @return array<string, string>
     */
    private static function parsed(): array
    {
        $values = [];

        foreach (preg_split('/\R/', self::example()) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim($value);
        }

        return $values;
    }

    public function testDocumentsEveryKnownKey(): void
    {
        $keys = array_keys(self::parsed());

        foreach (['APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_LOCALE', 'APP_KEY'] as $expected) {
            self::assertContains($expected, $keys);
        }
    }

    public function testSensitiveKeysAreDocumentedButEmpty(): void
    {
        $values = self::parsed();

        foreach (Config::sensitiveKeys() as $key) {
            self::assertArrayHasKey($key, $values, $key . ' must be documented');
            self::assertContains(
                strtolower($values[$key]),
                self::PLACEHOLDERS,
                $key . ' must not carry a real or usable value'
            );
        }
    }

    public function testDefaultsToProductionSafeValues(): void
    {
        $values = self::parsed();

        self::assertSame('local', $values['APP_ENV']);
        self::assertSame('true', $values['APP_DEBUG']);
    }

    public function testEnvFileIsNeverTracked(): void
    {
        $root = dirname(__DIR__, 2);

        $ignore = file_get_contents($root . '/.gitignore');
        self::assertIsString($ignore);
        self::assertStringContainsString('.env', $ignore, '.gitignore must exclude .env');

        // A local .env is expected; what must never happen is Git tracking it.
        $output = [];
        $status = 0;
        exec(
            sprintf('git -C %s ls-files --error-unmatch .env 2>/dev/null', escapeshellarg($root)),
            $output,
            $status
        );

        self::assertNotSame(0, $status, '.env must not be a tracked file');
    }

    public function testExampleCarriesNoSecretShapedLiteral(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/(secret|password|token|api[_-]?key)\s*=\s*\S{12,}/i',
            self::example(),
            '.env.example must contain placeholders only'
        );
    }

}
