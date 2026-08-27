<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Http;

use Facet\Http\StaticFile;
use PHPUnit\Framework\TestCase;

/**
 * The built-in server's static passthrough, decided without a server.
 *
 * The gate at {@see \Facet\Tests\Smoke\Phase1GateTest} proves the same rules
 * over real HTTP; these cases pin the reasoning itself, including the inputs
 * that used to reach a filesystem call that refuses them.
 */
final class StaticFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/facet-static-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($root . '/build/assets', 0o777, true));
        self::assertNotFalse(file_put_contents($root . '/build/assets/app-abc12345.js', 'export {};'));
        self::assertNotFalse(file_put_contents($root . '/index.php', '<?php'));
        self::assertNotFalse(file_put_contents(dirname($root) . '/' . basename($root) . '-outside.txt', 'secret'));

        $this->root = $root;
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/build/assets/app-abc12345.js');
        @unlink($this->root . '/index.php');
        @rmdir($this->root . '/build/assets');
        @rmdir($this->root . '/build');
        @rmdir($this->root);
        @unlink($this->root . '-outside.txt');
    }

    private function resolve(string $requestUri): ?string
    {
        return StaticFile::resolve($this->root, $requestUri, $this->root . '/index.php');
    }

    public function testItServesABuiltAsset(): void
    {
        self::assertSame(
            $this->root . '/build/assets/app-abc12345.js',
            $this->resolve('/build/assets/app-abc12345.js')
        );
    }

    public function testItServesABuiltAssetCarryingAQueryString(): void
    {
        self::assertSame(
            $this->root . '/build/assets/app-abc12345.js',
            $this->resolve('/build/assets/app-abc12345.js?v=1')
        );
    }

    /**
     * The regression: a decoded NUL must never reach realpath(), which raises
     * a ValueError for it, nor be trimmed away and the request answered as if
     * some shorter path had been asked for.
     */
    public function testItRefusesAnEncodedNullByteWithoutTouchingTheFilesystem(): void
    {
        self::assertNull($this->resolve('/projects/kushim%00'));
        self::assertNull($this->resolve('/%00'));
        self::assertNull($this->resolve('/build/assets/app-abc12345.js%00'));
        self::assertNull($this->resolve("/build/assets/app-abc12345.js\0"));

        // Not truncated to a real file: the NUL disqualifies the request.
        self::assertNull($this->resolve('/build/assets/app-abc12345.js%00.png'));
    }

    public function testItRefusesEncodedSlashesThatAreNotAFile(): void
    {
        self::assertNull($this->resolve('/projects/a%2Fb'));
    }

    public function testItRefusesTraversalOutOfTheDocumentRoot(): void
    {
        $outside = basename($this->root) . '-outside.txt';

        self::assertNull($this->resolve('/../' . $outside));
        self::assertNull($this->resolve('/%2e%2e/' . $outside));
        self::assertNull($this->resolve('/build/assets/../../../' . $outside));
        self::assertNull($this->resolve('/build/assets/..%2f..%2f..%2f' . $outside));
    }

    public function testItRefusesTheRouterScriptItself(): void
    {
        self::assertNull($this->resolve('/index.php'));
    }

    public function testItRefusesDirectoriesTheRootAndUnknownPaths(): void
    {
        self::assertNull($this->resolve('/'));
        self::assertNull($this->resolve('/build'));
        self::assertNull($this->resolve('/build/assets'));
        self::assertNull($this->resolve('/projects/kushim'));
        self::assertNull($this->resolve('/build/assets/missing-00000000.js'));
    }

    public function testItRefusesAPathologicallyLongTarget(): void
    {
        self::assertNull($this->resolve('/' . str_repeat('a', 4096)));
    }

    public function testItRefusesAnUnresolvableDocumentRoot(): void
    {
        self::assertNull(StaticFile::resolve(
            $this->root . '/no-such-root',
            '/build/assets/app-abc12345.js',
            $this->root . '/index.php'
        ));
    }
}
