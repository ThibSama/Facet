<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Config;

use Facet\Config\Config;
use Facet\Support\DotEnv;
use PHPUnit\Framework\TestCase;

/**
 * The environment-file convention itself (PORT-123), asserted against the
 * repository rather than against a description of it.
 *
 * Two committed templates, three untracked machine-local files, one owner per
 * key. The tests below are the audit: if a new key appears in the application
 * without a line in `.env.example`, or a template ever carries a real value, or
 * an ignore rule stops holding, this file fails.
 */
final class EnvironmentConventionTest extends TestCase
{
    /**
     * Every application key, and the file that documents it. This list is the
     * contract; `testEveryDocumentedKeyIsConsumed` keeps it honest in the other
     * direction, so neither side can drift alone.
     *
     * @var list<string>
     */
    private const APPLICATION_KEYS = [
        'APP_NAME',
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'APP_LOCALE',
        'APP_KEY',
        'DB_DSN',
        'DB_USERNAME',
        'DB_PASSWORD',
        'VITE_DEV_SERVER_ORIGIN',
    ];

    /** @var list<string> */
    private const TEST_KEYS = [
        'FACET_TEST_DB_DSN',
        'FACET_TEST_DB_USER',
        'FACET_TEST_DB_PASSWORD',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function contents(string $name): string
    {
        $contents = file_get_contents(self::root() . DIRECTORY_SEPARATOR . $name);
        self::assertIsString($contents, $name . ' must be readable.');

        return $contents;
    }

    /**
     * @return list<string> every key a template mentions, commented-out
     *                     documentation lines included
     */
    private static function documentedKeys(string $name): array
    {
        $matches = [];
        preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', self::contents($name), $matches);

        /** @var list<string> $keys */
        $keys = array_values(array_unique($matches[1]));

        return $keys;
    }

    public function testBothTemplatesAreCommitted(): void
    {
        self::assertFileExists(self::root() . '/.env.example');
        self::assertFileExists(self::root() . '/.env.testing.example');
    }

    public function testApplicationTemplateDocumentsEveryApplicationKey(): void
    {
        $documented = self::documentedKeys('.env.example');

        foreach (self::APPLICATION_KEYS as $key) {
            self::assertContains($key, $documented, $key . ' is undocumented in .env.example.');
        }
    }

    public function testEveryDocumentedKeyIsConsumed(): void
    {
        foreach (self::documentedKeys('.env.example') as $key) {
            self::assertContains(
                $key,
                self::APPLICATION_KEYS,
                $key . ' is documented in .env.example but is not a known application key.'
            );
        }
    }

    public function testTestTemplateDocumentsExactlyTheTestKeys(): void
    {
        self::assertSame(self::TEST_KEYS, self::documentedKeys('.env.testing.example'));
    }

    public function testTheTwoTemplatesShareNoKey(): void
    {
        self::assertSame(
            [],
            array_intersect(self::documentedKeys('.env.example'), self::documentedKeys('.env.testing.example')),
            'Development and test configuration must not share a key.'
        );
    }

    public function testApplicationTemplateNeverMentionsATestCredential(): void
    {
        foreach (self::documentedKeys('.env.example') as $key) {
            self::assertFalse(Config::isTestOnly($key), $key . ' belongs to .env.testing, not .env.');
        }
    }

    /**
     * A template is safe when every value in it is empty or an obvious
     * placeholder — a filled one would be a committed secret.
     */
    public function testTemplatesCarryNoValueForAnySecret(): void
    {
        foreach (['.env.example', '.env.testing.example'] as $template) {
            foreach (DotEnv::read(self::root() . DIRECTORY_SEPARATOR . $template) as $key => $value) {
                if (Config::isTestOnly($key) || Config::fromArray([])->isSensitive($key)) {
                    self::assertSame('', $value, $template . ' must not carry a value for ' . $key . '.');
                }
            }
        }
    }

    public function testTemplatesAreTracked(): void
    {
        foreach (['.env.example', '.env.testing.example'] as $template) {
            self::assertFalse(self::isIgnored($template), $template . ' must be committed.');
        }
    }

    public function testMachineLocalFilesAreIgnored(): void
    {
        foreach (['.env', '.env.local', '.env.testing'] as $local) {
            self::assertTrue(self::isIgnored($local), $local . ' must never be trackable.');
        }
    }

    /**
     * Asks git itself, so the assertion is about the effective ignore rules and
     * not about the text of `.gitignore`.
     */
    private static function isIgnored(string $path): bool
    {
        if (!is_dir(self::root() . '/.git')) {
            self::markTestSkipped('Not a git checkout.');
        }

        $command = sprintf(
            'git -C %s check-ignore -q -- %s',
            escapeshellarg(self::root()),
            escapeshellarg($path)
        );

        exec($command . ' 2>/dev/null', $output, $status);

        self::assertNotSame(128, $status, 'git check-ignore failed for ' . $path . '.');

        return $status === 0;
    }
}
