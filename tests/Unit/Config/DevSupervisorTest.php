<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The one-command development startup (PORT-124), asserted at the level a test
 * can own: what the supervisor is wired to do, and that its Node-side view of
 * the environment is the same one PHP will boot with.
 *
 * The running behaviour it cannot assert here — two children, real ports, real
 * signals — is what the manual start/stop cycles cover. What this file prevents
 * is the quiet kind of drift: `npm run dev` losing the supervisor, the PHP
 * router losing its script, a port becoming negotiable, or the two dotenv
 * readers disagreeing about which file wins.
 */
final class DevSupervisorTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function supervisor(): string
    {
        $source = file_get_contents(self::root() . '/scripts/dev.mjs');
        self::assertIsString($source, 'scripts/dev.mjs must be readable.');

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private static function packageJson(): array
    {
        $raw = file_get_contents(self::root() . '/package.json');
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testNpmRunDevStartsTheSupervisor(): void
    {
        $scripts = self::packageJson()['scripts'] ?? null;
        self::assertIsArray($scripts);

        self::assertSame(
            'node scripts/dev.mjs',
            $scripts['dev'] ?? null,
            'Daily startup must be exactly `npm run dev`.'
        );
    }

    public function testTheSupervisorAddsNoDependency(): void
    {
        $package = self::packageJson();

        /** @var array<string, string> $dependencies */
        $dependencies = array_merge(
            is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [],
            is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : []
        );

        foreach (['concurrently', 'npm-run-all', 'npm-run-all2', 'dotenv', 'dotenv-cli', 'foreman', 'pm2'] as $forbidden) {
            self::assertArrayNotHasKey(
                $forbidden,
                $dependencies,
                'The development runtime must stay on Node built-ins.'
            );
        }
    }

    public function testPortsAreFixedAndNeverNegotiated(): void
    {
        $source = self::supervisor();

        self::assertMatchesRegularExpression('/const PHP_PORT = 8000;/', $source);
        self::assertMatchesRegularExpression('/const VITE_PORT = 5173;/', $source);
        self::assertStringContainsString('--strictPort', $source, 'Vite must never pick another port.');
        self::assertStringContainsString('is already in use', $source, 'A busy port must be a loud failure.');
    }

    public function testPhpIsStartedThroughTheApplicationRouter(): void
    {
        $source = self::supervisor();

        // Without the router script the built-in server answers static 404s for
        // /projects, /contact and /login instead of reaching the application.
        self::assertStringContainsString("'public/index.php'", $source);
        self::assertStringContainsString("'-t',\n    'public',", $source);
    }

    public function testTheViteOriginIsInjectedRatherThanConfigured(): void
    {
        $source = self::supervisor();

        self::assertStringContainsString('VITE_DEV_SERVER_ORIGIN: VITE_ORIGIN', $source);
        self::assertStringContainsString("const VITE_ORIGIN = `http://\${HOST}:\${VITE_PORT}`", $source);
    }

    public function testBothChildrenAreTornDownTogether(): void
    {
        $source = self::supervisor();

        foreach (['SIGINT', 'SIGTERM', 'SIGHUP', 'SIGKILL'] as $signal) {
            self::assertStringContainsString($signal, $source, $signal . ' must be handled.');
        }

        self::assertStringContainsString('detached: true', $source, 'Children need their own process group.');
        self::assertStringContainsString('exited unexpectedly', $source, 'A dead child must stop the other.');
    }

    /**
     * The dev server serves sources at the repository-relative paths PHP emits.
     * If the build base ever applied to `serve`, every module URL in a rendered
     * document would 404 against a Vite server that was plainly listening.
     */
    public function testTheDevServerAndPhpAgreeOnTheAssetUrlSpace(): void
    {
        $config = file_get_contents(self::root() . '/vite.config.ts');
        self::assertIsString($config);

        self::assertStringContainsString("base: command === 'build' ? '/build/' : '/'", $config);
    }

    /**
     * The agreement, executed rather than described: the same two files, read
     * by the real Node module, must produce the values the PHP loader produces.
     */
    public function testTheNodeReaderResolvesTheSameValuesAsPhp(): void
    {
        $root = tempnam(sys_get_temp_dir(), 'facet-agree-');
        self::assertIsString($root);
        unlink($root);
        mkdir($root, 0o700);

        try {
            file_put_contents($root . '/.env', "APP_ENV=local\nAPP_NAME=from-dot-env\nAPP_URL=http://base\n");
            file_put_contents($root . '/.env.local', "APP_ENV=production\nAPP_NAME=from-dot-env-local\n");

            $script = sprintf(
                'import("%s/scripts/dev-environment.mjs").then((m) => {'
                . ' const r = m.resolveEnvironment(%s, {});'
                . ' process.stdout.write(JSON.stringify([r.environment, r.values.APP_NAME, r.values.APP_URL]));'
                . '})',
                addslashes(self::root()),
                json_encode($root, JSON_THROW_ON_ERROR)
            );

            $output = shell_exec(sprintf('node --input-type=module -e %s 2>/dev/null', escapeshellarg($script)));

            if (!is_string($output) || $output === '') {
                self::markTestSkipped('Node is not available.');
            }

            self::assertSame(
                // .env.local wins on APP_NAME, cannot touch APP_ENV, and leaves
                // APP_URL to .env — the same three answers PHP gives.
                ['local', 'from-dot-env-local', 'http://base'],
                json_decode($output, true, 512, JSON_THROW_ON_ERROR)
            );
        } finally {
            foreach (glob($root . '/.env*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($root);
        }
    }

    /**
     * Node resolves the environment for the supervisor's preflight; PHP
     * resolves it for the application. A disagreement would mean the supervisor
     * vouching for a configuration the application does not have.
     */
    public function testTheNodeEnvironmentReaderMatchesTheDocumentedPrecedence(): void
    {
        $source = file_get_contents(self::root() . '/scripts/dev-environment.mjs');
        self::assertIsString($source);

        self::assertStringContainsString("readEnvFile(resolve(root, '.env.local'))", $source);
        self::assertStringContainsString("name !== 'APP_ENV'", $source, '.env.local may not set APP_ENV.');
        self::assertStringContainsString(
            "environment !== 'production'",
            $source,
            'Production must not read .env.local.'
        );
        self::assertStringContainsString('TEST_ONLY_PREFIX', $source, 'FACET_TEST_* must not be propagated.');
    }
}
