<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The public site is file-backed, and must stay that way.
 *
 * Adding MariaDB to the project introduces a failure mode that did not exist
 * before: a portfolio that returns 500 because a database it never needed is
 * unreachable. These tests hold the line — every public page renders with no
 * database configured at all, and the schema tooling stays off the network.
 */
final class DatabaseIndependenceTest extends TestCase
{
    private const PUBLIC_PAGES = ['/fr', '/fr/projects', '/fr/about', '/fr/contact'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * A configuration with an app key and deliberately no DB_* values.
     */
    private static function configWithoutDatabase(): Config
    {
        return Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'database-independence-test-key',
            'APP_LOCALE' => 'en',
            'APP_DEBUG' => 'false',
        ]);
    }

    /**
     * @param array<string, string> $server
     */
    private static function get(string $path, array $server = []): Request
    {
        return Request::fromGlobals(
            array_merge(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path], $server),
            [],
            [],
            []
        );
    }

    public function testEveryPublicPageRendersWithNoDatabaseConfigured(): void
    {
        $config = self::configWithoutDatabase();

        foreach (self::PUBLIC_PAGES as $path) {
            $response = Application::boot(self::root(), $config)->handle(self::get($path));

            self::assertSame(200, $response->status(), $path . ' must render without a database');
            self::assertNotSame('', $response->body(), $path . ' must have a body');
        }
    }

    public function testNoPublicPageLeaksDatabaseConfigurationErrors(): void
    {
        $response = Application::boot(self::root(), self::configWithoutDatabase())->handle(self::get('/'));

        foreach (['DB_DSN', 'DB_PASSWORD', 'SQLSTATE', 'PDOException', 'MissingConfiguration'] as $needle) {
            self::assertStringNotContainsString($needle, $response->body());
        }
    }

    public function testTheDocumentRootHoldsNothingButTheFrontController(): void
    {
        // An installer, a migration runner or an admin-creation script reachable
        // over HTTP is a standing liability. None may exist under public/.
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/public', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);

            if ($file->getExtension() === 'php') {
                $files[] = $file->getFilename();
            }
        }

        self::assertSame(['index.php'], $files, 'public/ must expose exactly one PHP entrypoint');
    }

    public function testSchemaToolsRefuseToRunOverHttp(): void
    {
        foreach (['migrate.php', 'create-admin.php'] as $tool) {
            $source = (string) file_get_contents(self::root() . '/tools/' . $tool);

            self::assertStringContainsString(
                "PHP_SAPI !== 'cli'",
                $source,
                $tool . ' must refuse to run outside the CLI'
            );
        }
    }

    public function testPublicRequestPathDoesNotDependOnTheDatabase(): void
    {
        // A structural guarantee rather than a runtime one: if no class on the
        // request path can even name the database layer, no request can reach it.
        $directories = ['src/Http', 'src/Html', 'src/Content', 'src/Skin', 'src/Asset'];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                self::assertInstanceOf(\SplFileInfo::class, $file);

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                self::assertStringNotContainsString(
                    'Facet\\Database',
                    (string) file_get_contents($file->getPathname()),
                    $file->getFilename() . ' must not reach the database layer'
                );
            }
        }
    }

    public function testRepositoryContainsNoDefaultCredential(): void
    {
        // PORT-89: a fresh checkout must carry no usable password or hash.
        $output = [];
        $status = 0;

        exec(
            sprintf(
                'git -C %s grep -lI -E %s -- . ":!vendor" 2>/dev/null',
                escapeshellarg(self::root()),
                escapeshellarg('\$2[aby]\$[0-9]{2}\$')
            ),
            $output,
            $status
        );

        self::assertSame([], $output, 'no password hash may be committed');
    }
}
