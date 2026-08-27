<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Guards the foundation contract itself: the manifests, ignores and
 * entrypoints a fresh checkout depends on.
 */
final class RepositoryContractTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $relative): array
    {
        $raw = file_get_contents(self::root() . '/' . $relative);
        self::assertIsString($raw, $relative . ' is readable');

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, $relative . ' is valid JSON');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testRequiredPathsExist(): void
    {
        foreach (['public/index.php', 'src', 'config', 'tests', 'resources/js/app.ts', 'resources/css/app.css'] as $path) {
            self::assertFileExists(self::root() . '/' . $path);
        }
    }

    public function testComposerDeclaresPhp8(): void
    {
        $composer = self::json('composer.json');
        $require = $composer['require'] ?? [];
        self::assertIsArray($require);

        self::assertArrayHasKey('php', $require);
        self::assertMatchesRegularExpression('/8\.\d/', (string) $require['php']);
    }

    public function testComposerExposesAggregateQualityScript(): void
    {
        $composer = self::json('composer.json');
        $scripts = $composer['scripts'] ?? [];
        self::assertIsArray($scripts);

        self::assertArrayHasKey('quality', $scripts);
        self::assertIsArray($scripts['quality']);
        self::assertNotEmpty($scripts['quality']);
    }

    public function testFrontendFoundationIsViteTailwindTypescriptOnly(): void
    {
        $package = self::json('package.json');
        $dev = $package['devDependencies'] ?? [];
        self::assertIsArray($dev);

        foreach (['vite', 'tailwindcss', 'typescript'] as $required) {
            self::assertArrayHasKey($required, $dev, $required . ' is a mandatory foundation technology');
        }

        $forbidden = ['react', 'react-dom', 'vue', 'svelte', '@angular/core', 'preact', 'solid-js', 'alpinejs', 'htmx.org'];
        $all = array_merge(array_keys($dev), array_keys((array) ($package['dependencies'] ?? [])));

        foreach ($forbidden as $framework) {
            self::assertNotContains($framework, $all, 'Facet must not depend on an SPA/frontend framework');
        }
    }

    public function testProductionRuntimeDoesNotRequireNode(): void
    {
        $package = self::json('package.json');
        self::assertSame([], (array) ($package['dependencies'] ?? []), 'Node packages must be dev-only');
        self::assertTrue((bool) ($package['private'] ?? false));
    }

    public function testGitignoreCoversGeneratedArtefacts(): void
    {
        $ignore = file_get_contents(self::root() . '/.gitignore');
        self::assertIsString($ignore);

        foreach (['/vendor/', '/node_modules/', '/public/build/', '.env'] as $pattern) {
            self::assertStringContainsString($pattern, $ignore);
        }
    }
}
