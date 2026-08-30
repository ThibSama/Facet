<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Config;

use Facet\Config\Config;
use Facet\Config\MissingConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * The layered environment convention (PORT-123).
 *
 * Each case builds a throwaway "project root" holding only the env files under
 * test and boots Config against it, so what is asserted is the real resolution
 * order rather than a re-implementation of it. `$_ENV` and the process
 * environment are saved and restored around every test: Config writes into both
 * the way a real boot does, and a leaked key would make a later test lie.
 */
final class EnvironmentPrecedenceTest extends TestCase
{
    private string $root = '';

    /** @var array<array-key, mixed> */
    private array $originalEnv = [];

    /** @var list<string> */
    private const TOUCHED = [
        'APP_ENV',
        'APP_NAME',
        'APP_KEY',
        'APP_URL',
        'DB_DSN',
        'VITE_DEV_SERVER_ORIGIN',
        'FACET_TEST_DB_DSN',
        'FACET_TEST_DB_USER',
        'FACET_TEST_DB_PASSWORD',
    ];

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;

        foreach (self::TOUCHED as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }

        $root = tempnam(sys_get_temp_dir(), 'facet-root-');
        self::assertIsString($root);
        unlink($root);
        mkdir($root, 0o700);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        foreach (self::TOUCHED as $key) {
            putenv($key);
        }

        $_ENV = $this->originalEnv;

        foreach (glob($this->root . '/.env*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . $name, $contents);
    }

    public function testDotEnvSuppliesValuesWhenNothingOverridesIt(): void
    {
        $this->write('.env', "APP_ENV=local\nAPP_NAME=from-dot-env\n");

        self::assertSame('from-dot-env', Config::fromEnvironment($this->root)->get('APP_NAME'));
    }

    public function testDotEnvLocalOverridesDotEnv(): void
    {
        $this->write('.env', "APP_ENV=local\nAPP_NAME=from-dot-env\n");
        $this->write('.env.local', "APP_NAME=from-dot-env-local\n");

        self::assertSame('from-dot-env-local', Config::fromEnvironment($this->root)->get('APP_NAME'));
    }

    public function testProcessEnvironmentOverridesBothFiles(): void
    {
        $this->write('.env', "APP_ENV=local\nAPP_NAME=from-dot-env\n");
        $this->write('.env.local', "APP_NAME=from-dot-env-local\n");
        putenv('APP_NAME=from-process');

        self::assertSame('from-process', Config::fromEnvironment($this->root)->get('APP_NAME'));
    }

    public function testAbsentLocalOverrideIsNotRequired(): void
    {
        $this->write('.env', "APP_ENV=local\nAPP_NAME=from-dot-env\n");

        self::assertFileDoesNotExist($this->root . '/.env.local');
        self::assertSame('from-dot-env', Config::fromEnvironment($this->root)->get('APP_NAME'));
    }

    public function testProductionNeverReadsTheLocalOverride(): void
    {
        $this->write('.env', "APP_ENV=production\nAPP_NAME=from-dot-env\n");
        $this->write('.env.local', "APP_NAME=from-dot-env-local\nAPP_KEY=local-override-key\n");

        $config = Config::fromEnvironment($this->root);

        self::assertTrue($config->isProduction());
        self::assertSame('from-dot-env', $config->get('APP_NAME'));
        self::assertFalse($config->has('APP_KEY'));
    }

    public function testProductionFromTheProcessEnvironmentAlsoExcludesTheLocalOverride(): void
    {
        $this->write('.env', "APP_ENV=local\nAPP_NAME=from-dot-env\n");
        $this->write('.env.local', "APP_NAME=from-dot-env-local\n");
        putenv('APP_ENV=production');

        self::assertSame('from-dot-env', Config::fromEnvironment($this->root)->get('APP_NAME'));
    }

    public function testMissingDotEnvDefaultsToProductionAndIgnoresTheLocalOverride(): void
    {
        $this->write('.env.local', "APP_NAME=from-dot-env-local\n");

        $config = Config::fromEnvironment($this->root);

        self::assertTrue($config->isProduction());
        self::assertNull($config->get('APP_NAME'));
    }

    public function testTheLocalOverrideCannotDeclareTheEnvironment(): void
    {
        $this->write('.env', "APP_ENV=local\n");
        $this->write('.env.local', "APP_ENV=production\nAPP_NAME=from-dot-env-local\n");

        $config = Config::fromEnvironment($this->root);

        self::assertSame('local', $config->environment());
        // The rest of the override still applies; only APP_ENV is refused.
        self::assertSame('from-dot-env-local', $config->get('APP_NAME'));
    }

    public function testTestOnlyCredentialsAreNotReadableFromApplicationConfiguration(): void
    {
        $this->write('.env', "APP_ENV=local\n");
        // Exactly what tests/bootstrap.php does before the application boots.
        $_ENV['FACET_TEST_DB_DSN'] = 'mysql:host=127.0.0.1;dbname=facet_test;charset=utf8mb4';
        $_ENV['FACET_TEST_DB_USER'] = 'facet_test';
        $_ENV['FACET_TEST_DB_PASSWORD'] = 'facet_test';

        $config = Config::fromEnvironment($this->root);

        self::assertFalse($config->has('FACET_TEST_DB_DSN'));
        self::assertNull($config->get('FACET_TEST_DB_DSN'));
        // And the suite's schema never becomes the application's database.
        self::assertFalse($config->has('DB_DSN'));
    }

    public function testRequiringATestOnlyCredentialIsARefusalNotAMissingValue(): void
    {
        $config = Config::fromArray(['FACET_TEST_DB_DSN' => 'mysql:host=127.0.0.1;dbname=facet_test']);

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessageMatches('/owned by the test suite/');
        $config->require('FACET_TEST_DB_DSN');
    }

    public function testTheMissingValueDiagnosticIsActionableAndDisclosesNothing(): void
    {
        $this->write('.env', "APP_ENV=local\nDB_PASSWORD=a-real-local-secret\n");

        $config = Config::fromEnvironment($this->root);

        try {
            $config->require('APP_KEY');
            self::fail('Expected a missing-configuration failure.');
        } catch (MissingConfigurationException $exception) {
            $message = $exception->getMessage();

            self::assertStringContainsString('APP_KEY', $message);
            self::assertStringContainsString('.env.example', $message);
            self::assertStringContainsString('.env.local', $message);
            self::assertStringNotContainsString('a-real-local-secret', $message);
        }
    }
}
