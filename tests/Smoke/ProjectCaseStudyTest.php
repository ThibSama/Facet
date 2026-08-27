<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMElement;
use DOMXPath;
use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\Link;
use Facet\Content\Project;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * One project's case study at /projects/{slug}.
 *
 * The rule the whole file enforces is that a detail page states the corpus and
 * only the corpus: everything present must be traceable to the {@see Project}
 * object, and everything the corpus leaves undocumented must be *absent*
 * rather than filled with plausible copy. Absence of evidence is not a fact,
 * and a portfolio that rounds it up to one is lying quietly.
 */
final class ProjectCaseStudyTest extends TestCase
{
    private static ?Corpus $corpus = null;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default(self::root())->load();
    }

    private static function get(string $target): Response
    {
        $application = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));

        return $application->handle(Request::create('GET', $target));
    }

    private static function html(string $target): string
    {
        $response = self::get($target);

        self::assertSame(200, $response->status(), $target . ' must be served');

        return $response->body();
    }

    private static function page(Project $project): DOMXPath
    {
        return Dom::of(Dom::withoutScripts(self::html(self::href($project))));
    }

    private static function href(Project $project): string
    {
        return '/projects/' . $project->slug()->value();
    }

    private static function mainText(DOMXPath $xpath): string
    {
        return Dom::textOf(Dom::element($xpath, '//main'));
    }

    // ------------------------------------------------------------ routing

    /**
     * Criterion 8: every known slug resolves to its own project, and anything
     * else is a deterministic 404.
     */
    public function testKnownSlugsResolveAndEverythingElseIsADeterministicNotFound(): void
    {
        $projects = self::corpus()->projects();
        self::assertNotSame([], $projects);

        foreach ($projects as $project) {
            $xpath = Dom::of(Dom::withoutScripts(self::html(self::href($project))));

            $h1 = Dom::element($xpath, '//h1', self::href($project) . ' must have exactly one H1');
            self::assertSame($project->name(), Dom::textOf($h1), 'The slug must resolve to its own project');
        }

        $rejected = [
            '/projects/does-not-exist',
            '/projects/Kushim',
            '/projects/-kushim',
            '/projects/kushim--x',
            '/projects/k',
            '/projects/' . str_repeat('a', 65),
            '/projects/kushim.php',
        ];

        foreach ($rejected as $target) {
            $first = self::get($target);
            $second = self::get($target);

            self::assertSame(404, $first->status(), $target . ' must not resolve');
            self::assertSame($first->status(), $second->status(), $target . ' must answer deterministically');
            self::assertSame($first->body(), $second->body(), $target . ' must answer deterministically');
        }

        // A non-canonical spelling is normalised by the accepted one-URL rule
        // before it ever reaches the corpus, so it redirects rather than 404s —
        // and the target of that redirect is itself not a project.
        $encoded = self::get('/projects/%2e%2e');
        self::assertSame(301, $encoded->status(), 'A percent-encoded path is redirected, not served');
        self::assertNotSame(200, self::get('/projects/..')->status(), 'And its canonical form resolves to nothing');
    }

    // ---------------------------------------------------------- narrative

    /**
     * Criterion 9: name, summary, context and role come from the canonical
     * object, each exactly once.
     */
    public function testTheNarrativeIsReadFromTheCanonicalObject(): void
    {
        foreach (self::corpus()->projects() as $project) {
            $xpath = self::page($project);
            $text = self::mainText($xpath);

            self::assertSame($project->name(), Dom::textOf(Dom::element($xpath, '//main//h1')));
            self::assertStringContainsString($project->summary(), $text, 'The summary must be visible');
            self::assertStringContainsString($project->context(), $text, 'The context must be visible');
            self::assertStringContainsString($project->role(), $text, 'The role must be visible');

            Dom::element($xpath, '//main//section[@aria-labelledby="context"]');
            Dom::element($xpath, '//main//section[@aria-labelledby="role"]');
        }
    }

    /**
     * Criterion 10: optional fields are rendered where the corpus provides
     * them and omitted — never guessed — where it does not.
     */
    public function testOptionalFieldsAreRenderedOnlyWhereDocumented(): void
    {
        $omissions = 0;

        foreach (self::corpus()->projects() as $project) {
            $xpath = self::page($project);
            $text = self::mainText($xpath);
            $href = self::href($project);

            self::assertStringNotContainsStringIgnoringCase(
                'unspecified',
                $text,
                $href . ': a declared absence of evidence is not a public claim'
            );

            foreach (['Lorem ipsum', 'TODO', 'FIXME', 'Coming soon', 'placeholder'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase($forbidden, $text, $href . ' shows no filler copy');
            }

            // Status.
            if ($project->status()->isSubstantiated()) {
                self::assertStringContainsString($project->status()->value, $text);
            } else {
                $omissions++;
            }

            // Period.
            $period = $project->period();
            $times = Dom::query($xpath, '//main//time');

            if ($period === null) {
                self::assertCount(0, $times, $href . ' has no documented period');
                $omissions++;
            } else {
                self::assertCount(1, $times);
                $time = $times->item(0);
                self::assertInstanceOf(DOMElement::class, $time);
                self::assertSame($period->start(), $time->getAttribute('datetime'));
            }

            // Technologies and concepts, as two distinct fields.
            $expected = [];

            if ($project->technologies() !== []) {
                $expected['Technologies'] = implode(', ', $project->technologies());
            }

            if ($project->concepts() !== []) {
                $expected['Concepts'] = implode(', ', $project->concepts());
            }

            $rendered = [];

            foreach (Dom::query($xpath, '//main//dt') as $label) {
                $value = $label->nextElementSibling;
                self::assertInstanceOf(DOMElement::class, $value, 'Every field label needs its value');
                $rendered[Dom::textOf($label)] = Dom::textOf($value);
            }

            self::assertSame($expected, $rendered, $href . ' states exactly the fields the corpus documents');

            if ($project->technologies() === []) {
                self::assertStringNotContainsString('Technologies', $text, $href . ' documents no stack');
                $omissions++;
            }

            if ($project->concepts() === []) {
                self::assertStringNotContainsString('Concepts', $text);
            }

            // Outcomes.
            $outcomeItems = Dom::query($xpath, '//main//section[@aria-labelledby="outcomes"]//li');

            if ($project->outcomes() === []) {
                self::assertCount(0, Dom::query($xpath, '//main//section[@aria-labelledby="outcomes"]'));
                $omissions++;
            } else {
                self::assertCount(count($project->outcomes()), $outcomeItems);

                foreach ($project->outcomes() as $index => $outcome) {
                    $item = $outcomeItems->item($index);
                    self::assertInstanceOf(DOMElement::class, $item);
                    self::assertSame($outcome, Dom::textOf($item), 'Outcomes are canonical, in order');
                }
            }
        }

        self::assertGreaterThan(
            0,
            $omissions,
            'This test guards the omission path; it is meaningless if every project documents everything'
        );
    }

    /**
     * Criterion 11: links are the corpus's own labels and URLs, with safe
     * outbound semantics and nothing added.
     */
    public function testLinksAreCanonicalAndSafe(): void
    {
        foreach (self::corpus()->projects() as $project) {
            $xpath = self::page($project);
            $href = self::href($project);

            $expected = array_map(static fn (Link $link): string => $link->url(), $project->links());

            $rendered = Dom::attributes($xpath, '//main//section[@aria-labelledby="links"]//a', 'href');
            self::assertSame($expected, $rendered, $href . ' links to exactly what the corpus holds');

            if ($project->links() === []) {
                self::assertCount(0, Dom::query($xpath, '//main//section[@aria-labelledby="links"]'));
            }

            // No outbound URL anywhere on the page that the corpus did not state.
            $external = Dom::attributes($xpath, '//main//a[starts-with(@href, "http")]', 'href');
            self::assertSame($expected, $external, $href . ' invents no URL');

            foreach ($project->links() as $index => $link) {
                $anchor = Dom::query($xpath, '//main//section[@aria-labelledby="links"]//a')->item($index);
                self::assertInstanceOf(DOMElement::class, $anchor);

                self::assertSame($link->label(), Dom::textOf($anchor), 'The label is canonical');
                self::assertSame($link->type()->value, $anchor->getAttribute('data-link-type'));
                self::assertStringContainsString('noopener', $anchor->getAttribute('rel'));
                self::assertStringContainsString('noreferrer', $anchor->getAttribute('rel'));
            }
        }
    }

    // --------------------------------------------------------------- media

    /**
     * Criteria 12 and 13: one reusable slot, a neutral placeholder while every
     * source is null, deterministic geometry, no broken image and no second
     * asset pipeline.
     */
    public function testTheMediaSlotIsANeutralPlaceholderWithReservedGeometry(): void
    {
        $ratios = [];

        foreach (self::corpus()->projects() as $project) {
            $xpath = self::page($project);
            $href = self::href($project);
            $media = $project->media();

            self::assertFalse(
                $media->hasSource(),
                'This test asserts the missing-media path; give ' . $href . ' a source and it must be revisited'
            );

            $slot = Dom::element($xpath, '//main//*[@data-facet-media]', $href . ' has exactly one media slot');

            self::assertSame('img', $slot->getAttribute('role'));
            self::assertSame($media->description(), $slot->getAttribute('aria-label'), 'The placeholder speaks the canonical description');
            self::assertSame($media->reference(), $slot->getAttribute('data-facet-media'));
            self::assertSame('', Dom::textOf($slot), 'The slot carries no text of its own');

            $style = $slot->getAttribute('style');
            self::assertMatchesRegularExpression('/aspect-ratio:\s*\d+\s*\/\s*\d+\s*;?/', $style, 'Geometry is reserved');
            $ratios[] = $style;

            // No broken image, and no image pipeline of any kind.
            self::assertCount(0, Dom::query($xpath, '//main//img'));
            self::assertCount(0, Dom::query($xpath, '//main//picture'));
            self::assertCount(0, Dom::query($xpath, '//main//source'));
            self::assertCount(0, Dom::query($xpath, '//main//*[@src]'), 'Nothing loads a source');
            self::assertCount(0, Dom::query($xpath, '//main//*[@srcset]'));
            self::assertCount(0, Dom::query($xpath, '//main//*[@style and contains(@style, "url(")]'), 'No CDN or remote fill');
        }

        self::assertCount(1, array_unique($ratios), 'The slot reserves one deterministic ratio everywhere');
    }

    /**
     * Criterion 14: removing the media slot removes no information.
     */
    public function testNoVitalInformationLivesOnlyInTheMediaSlot(): void
    {
        foreach (self::corpus()->projects() as $project) {
            $xpath = self::page($project);
            $slot = Dom::element($xpath, '//main//*[@data-facet-media]');

            $parent = $slot->parentNode;
            self::assertNotNull($parent);
            $parent->removeChild($slot);

            $text = self::mainText($xpath);

            self::assertStringContainsString($project->name(), $text);
            self::assertStringContainsString($project->summary(), $text);
            self::assertStringContainsString($project->context(), $text);
            self::assertStringContainsString($project->role(), $text);

            foreach ($project->outcomes() as $outcome) {
                self::assertStringContainsString($outcome, $text);
            }
        }
    }

    // ---------------------------------------------------------- navigation

    /**
     * Criterion 15: the index and the case studies crawl into each other with
     * no JavaScript involved.
     */
    public function testTheCatalogueAndItsDetailsCrawlWithoutJavaScript(): void
    {
        $index = Dom::withoutScripts(self::html('/projects'));
        self::assertStringNotContainsStringIgnoringCase('<script', $index);

        $indexXpath = Dom::of($index);
        $reached = [];

        foreach (Dom::attributes($indexXpath, '//main//a[starts-with(@href, "/projects/")]', 'href') as $target) {
            $detail = Dom::withoutScripts(self::html($target));
            self::assertStringNotContainsStringIgnoringCase('<script', $detail);

            $detailXpath = Dom::of($detail);

            // Every case study offers the way back, as a plain link.
            $back = Dom::query($detailXpath, '//main//a[@href="/projects"]');
            self::assertGreaterThan(0, $back->length, $target . ' must link back to the catalogue');

            $first = $back->item(0);
            self::assertInstanceOf(DOMElement::class, $first);
            self::assertNotSame('', Dom::textOf($first), 'The back link needs a label');

            self::assertCount(0, Dom::query($detailXpath, '//main//button'), $target . ' needs no control');
            self::assertCount(0, Dom::query($detailXpath, '//main//*[@hidden]'));
            self::assertCount(0, Dom::query($detailXpath, '//main//a[not(@href)]'));
            self::assertCount(0, Dom::query($detailXpath, '//main//a[starts-with(@href, "javascript:")]'));

            $reached[] = $target;
        }

        $expected = array_map(self::href(...), self::corpus()->projects());
        self::assertSame($expected, $reached, 'The crawl reaches every canonical project and nothing else');
    }
}
