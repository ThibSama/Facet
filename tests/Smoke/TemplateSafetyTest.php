<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Content\Link;
use Facet\Content\LinkType;
use Facet\Content\Media;
use Facet\Content\Period;
use Facet\Content\Project;
use Facet\Content\ProjectStatus;
use Facet\Html\Html;
use Facet\Html\ViewContext;
use Facet\Skin\SkinRegistry;
use Facet\Skin\SkinRenderer;
use Facet\Support\Slug;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

/**
 * Guards the escaping contract from the outside.
 *
 * A helper that escapes is worth nothing if a template can print around it, so
 * this scans every skin template and requires each echo to go through the
 * injected {@see ViewContext}. The rule is mechanical on purpose: it is the
 * difference between templates that happen to be safe today and templates that
 * cannot become unsafe without failing a test.
 */
final class TemplateSafetyTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string> project-relative paths
     */
    private static function templates(): array
    {
        $files = [];
        $prefix = strlen(self::root()) + 1;

        foreach (['resources/skins', 'tests/Fixtures/skins'] as $relative) {
            $path = self::root() . '/' . $relative;
            self::assertDirectoryExists($path);

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), $prefix);
                }
            }
        }

        sort($files);

        return $files;
    }

    public function testEveryTemplateEchoGoesThroughTheEscapingHelper(): void
    {
        $templates = self::templates();
        self::assertNotEmpty($templates, 'No template was actually scanned');

        $offenders = [];
        $echoes = 0;

        foreach ($templates as $relative) {
            $raw = file_get_contents(self::root() . '/' . $relative);
            self::assertIsString($raw);

            if (preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $raw, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $expression) {
                $echoes++;

                if (!str_starts_with($expression, '$view->')) {
                    $offenders[] = $relative . ': <?= ' . $expression . ' ?>';
                }
            }
        }

        self::assertGreaterThan(0, $echoes, 'No echo was actually inspected');
        self::assertSame([], $offenders, 'Templates must print through $view so escaping is the default');
    }

    public function testNoTemplateCallsAnUnescapedOutputFunctionDirectly(): void
    {
        $offenders = [];

        foreach (self::templates() as $relative) {
            $raw = file_get_contents(self::root() . '/' . $relative);
            self::assertIsString($raw);

            foreach (['print_r(', 'var_dump(', 'eval(', '$_GET', '$_POST', '$_SERVER', '$_COOKIE'] as $forbidden) {
                if (str_contains($raw, $forbidden)) {
                    $offenders[] = $relative . ' uses ' . $forbidden;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * The behavioural half of the rule: hostile content really does come back
     * inert when rendered through a real skin template.
     */
    public function testHostileContentIsNeutralisedByTheRealTemplate(): void
    {
        $payload = '<script>alert("xss")</script>';

        $html = SkinRenderer::forBasePath(self::root())->render(
            SkinRegistry::default()->defaultSkin(),
            'page.projects.show',
            [
                'assets' => \Facet\Asset\AssetBundle::empty(),
                'skin' => SkinRegistry::default()->defaultSkin(),
                'appName' => 'Facet',
                'locale' => 'en',
                'path' => '/fr/projects/hostile',
                'project' => self::hostileProject($payload),
            ]
        );

        self::assertStringNotContainsString($payload, $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('javascript:', $html);
        // The link label is corpus text like any other and is escaped too, even
        // though the corpus already refuses a non-HTTP link URL.
        self::assertStringContainsString('href="https://example.test/hostile"', $html);
    }

    public function testTemplatesReceiveTheEscapingHelperFromTheRenderer(): void
    {
        // The helper is supplied by the renderer, not defined per skin, so no
        // skin can ship a weaker one.
        foreach (self::templates() as $relative) {
            $raw = file_get_contents(self::root() . '/' . $relative);
            self::assertIsString($raw);

            self::assertStringNotContainsString(
                '$view =',
                $raw,
                $relative . ' must use the injected helper rather than defining its own'
            );
        }

        // And the escape hatch stays typed: raw output takes an Html value,
        // never a string, so no template can reach it by interpolation.
        $parameters = (new ReflectionMethod(ViewContext::class, 'raw'))->getParameters();

        self::assertCount(1, $parameters);
        self::assertSame(Html::class, (string) $parameters[0]->getType());
    }

    private static function hostileProject(string $payload): Project
    {
        return Project::create(
            Slug::fromString('hostile'),
            $payload,
            $payload,
            $payload,
            $payload,
            [$payload],
            [$payload],
            ProjectStatus::Completed,
            [$payload],
            Period::create('2024', '2024'),
            [Link::create($payload, 'https://example.test/hostile', LinkType::Website)],
            Media::pending('Hostile media description'),
            false
        );
    }
}
