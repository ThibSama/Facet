<?php

declare(strict_types=1);

namespace Facet\Tests\Unit;

use Facet\Support\DotEnv;
use PHPUnit\Framework\TestCase;

final class DotEnvTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'facet-env-');
        self::assertIsString($path);
        $this->path = $path;
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }

        unset($_ENV['FACET_TEST_PLAIN'], $_ENV['FACET_TEST_QUOTED'], $_ENV['FACET_TEST_COMMENTED']);
    }

    public function testParsesValues(): void
    {
        file_put_contents($this->path, <<<'ENV'
            # a comment
            FACET_TEST_PLAIN=plain
            FACET_TEST_QUOTED="quoted value"
            FACET_TEST_COMMENTED=value # trailing
            ENV);

        $loaded = DotEnv::load($this->path);

        self::assertSame('plain', $loaded['FACET_TEST_PLAIN']);
        self::assertSame('quoted value', $loaded['FACET_TEST_QUOTED']);
        self::assertSame('value', $loaded['FACET_TEST_COMMENTED']);
    }

    public function testMissingFileIsNotAnError(): void
    {
        self::assertSame([], DotEnv::load('/nonexistent/facet/.env'));
    }

    public function testReadDoesNotTouchTheEnvironment(): void
    {
        file_put_contents($this->path, "FACET_TEST_PLAIN=from-file\n");

        self::assertSame(['FACET_TEST_PLAIN' => 'from-file'], DotEnv::read($this->path));
        self::assertArrayNotHasKey('FACET_TEST_PLAIN', $_ENV);
    }

    public function testIgnoredNamesAreNeverApplied(): void
    {
        file_put_contents($this->path, "FACET_TEST_PLAIN=from-file\nFACET_TEST_QUOTED=kept\n");

        $loaded = DotEnv::load($this->path, ['FACET_TEST_PLAIN']);

        self::assertArrayNotHasKey('FACET_TEST_PLAIN', $loaded);
        self::assertArrayNotHasKey('FACET_TEST_PLAIN', $_ENV);
        self::assertSame('kept', $_ENV['FACET_TEST_QUOTED']);
    }

    public function testDoesNotOverrideExistingEnvironment(): void
    {
        $_ENV['FACET_TEST_PLAIN'] = 'from-environment';
        file_put_contents($this->path, "FACET_TEST_PLAIN=from-file\n");

        $loaded = DotEnv::load($this->path);

        self::assertArrayNotHasKey('FACET_TEST_PLAIN', $loaded);
        self::assertSame('from-environment', $_ENV['FACET_TEST_PLAIN']);
    }
}
