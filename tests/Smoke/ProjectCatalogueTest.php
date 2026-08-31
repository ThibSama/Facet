<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMElement;
use DOMXPath;
use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\Project;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * The project catalogue at /projects, asserted against the DOM the server
 * produced with scripts stripped.
 *
 * The expectations are read from {@see Corpus} at runtime rather than
 * transcribed here: the catalogue is a *view* of canonical data, so a name
 * pasted into the template would fail exactly as loudly as a missing one. And
 * the document is checked without JavaScript, because a portfolio index that
 * needs a script to list its projects is not an index.
 */
final class ProjectCatalogueTest extends TestCase
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

    /**
     * The catalogue as served in production configuration.
     */
    private static function html(): string
    {
        $application = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));

        $response = $application->handle(Request::create('GET', '/fr/projects'));

        self::assertSame(200, $response->status());

        return $response->body();
    }

    private static function page(): DOMXPath
    {
        return Dom::of(Dom::withoutScripts(self::html()));
    }

    private static function card(DOMXPath $xpath, Project $project): DOMElement
    {
        $href = self::href($project);

        return Dom::element(
            $xpath,
            sprintf('//main//article[.//a[@href="%s"]]', $href),
            $href . ' must render as exactly one card'
        );
    }

    private static function href(Project $project): string
    {
        return '/fr/projects/' . $project->slug()->value();
    }

    // ------------------------------------------------------- completeness

    /**
     * Criterion 1: every canonical public project, exactly once, in
     * `Corpus::projects()` order.
     */
    public function testEveryCanonicalProjectRendersOnceInCorpusOrder(): void
    {
        $xpath = self::page();
        $projects = self::corpus()->projects();

        self::assertNotSame([], $projects, 'The corpus must declare at least one project');

        $expected = array_map(self::href(...), $projects);
        $rendered = Dom::attributes($xpath, '//main//a[starts-with(@href, "/fr/projects/")]', 'href');

        self::assertSame($expected, $rendered, 'The catalogue is the corpus, whole and in its own order');
        self::assertSame($rendered, array_values(array_unique($rendered)), 'No project may be listed twice');

        $cards = Dom::query($xpath, '//main//article');
        self::assertCount(count($projects), $cards, 'One card per canonical project');
    }

    /**
     * Criterion 2: each item exposes its name, its summary and a real link to
     * its own detail page.
     */
    public function testEveryCardCarriesItsNameSummaryAndDetailLink(): void
    {
        $xpath = self::page();

        foreach (self::corpus()->projects() as $project) {
            $href = self::href($project);

            $link = Dom::element($xpath, sprintf('//main//a[@href="%s"]', $href));
            self::assertSame($project->name(), Dom::textOf($link), $href . ' must be linked by its name');

            $card = self::card($xpath, $project);
            $text = Dom::textOf($card);

            self::assertStringContainsString($project->name(), $text);
            self::assertStringContainsString($project->summary(), $text, $href . ' must show its summary');
        }
    }

    // -------------------------------------------------- canonical metadata

    /**
     * Criterion 3: technologies and concepts are rendered as two distinct
     * canonical fields, never merged and never invented.
     */
    public function testTechnologiesAndConceptsStayDistinctCanonicalData(): void
    {
        $xpath = self::page();

        foreach (self::corpus()->projects() as $project) {
            $href = self::href($project);
            $card = self::card($xpath, $project);
            $text = Dom::textOf($card);

            foreach ($project->technologies() as $technology) {
                self::assertStringContainsString($technology, $text, $href . ' must show ' . $technology);
            }

            foreach ($project->concepts() as $concept) {
                self::assertStringContainsString($concept, $text, $href . ' must show ' . $concept);
            }

            // The rendered values are exactly the canonical lists: a tag the
            // corpus does not carry would change these strings.
            $expected = [];

            if ($project->technologies() !== []) {
                $expected[] = implode(', ', $project->technologies());
            }

            if ($project->concepts() !== []) {
                $expected[] = implode(', ', $project->concepts());
            }

            $rendered = [];

            foreach (Dom::query($xpath, sprintf('//main//article[.//a[@href="%s"]]//dd', $href)) as $value) {
                $rendered[] = Dom::textOf($value);
            }

            self::assertSame($expected, $rendered, $href . ' renders its two fields separately and verbatim');

            if ($project->technologies() === []) {
                self::assertStringNotContainsString('Technologies:', $text, $href . ' documents no stack');
            }

            if ($project->concepts() === []) {
                self::assertStringNotContainsString('Concepts:', $text, $href . ' documents no concept');
            }
        }
    }

    /**
     * Criterion 4: status and period appear only when a canonical source
     * substantiates them — never as "unspecified", an empty label or a date
     * the corpus does not hold.
     */
    public function testOptionalStatusAndPeriodAppearOnlyWhenSubstantiated(): void
    {
        $xpath = self::page();
        $main = Dom::element($xpath, '//main');

        self::assertStringNotContainsStringIgnoringCase(
            'unspecified',
            Dom::textOf($main),
            'A declared absence of evidence is not a public claim'
        );

        $unsubstantiated = 0;

        foreach (self::corpus()->projects() as $project) {
            $href = self::href($project);
            $text = Dom::textOf(self::card($xpath, $project));
            $period = $project->period();

            if ($project->status()->isSubstantiated()) {
                self::assertStringContainsString(self::label('content.status.' . $project->status()->value), $text);
            } else {
                $unsubstantiated++;
            }

            $times = Dom::query($xpath, sprintf('//main//article[.//a[@href="%s"]]//time', $href));

            if ($period === null) {
                self::assertCount(0, $times, $href . ' has no documented period');

                continue;
            }

            self::assertCount(1, $times, $href . ' carries one machine-readable date');

            $time = $times->item(0);
            self::assertInstanceOf(DOMElement::class, $time);
            self::assertSame($period->start(), $time->getAttribute('datetime'));
            self::assertStringContainsString($period->start(), $text);

            $end = $period->end();

            if ($end !== null) {
                self::assertStringContainsString($end, $text);
            }
        }

        self::assertGreaterThan(
            0,
            $unsubstantiated,
            'This test guards the omission path; it is meaningless if every project states a status'
        );

        // No label is left standing with nothing behind it.
        foreach (Dom::query($xpath, '//main//dt') as $label) {
            self::assertNotSame('', Dom::textOf($label), 'A field label needs a field');
        }

        foreach (Dom::query($xpath, '//main//dd') as $value) {
            self::assertNotSame('', Dom::textOf($value), 'An empty value must be omitted, not rendered');
        }

        foreach (['Lorem ipsum', 'TODO', 'FIXME', 'Coming soon', 'placeholder'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, Dom::textOf($main));
        }
    }

    // ----------------------------------------------------------- no script

    /**
     * Criterion 5: the full catalogue is in the server-rendered HTML and needs
     * no filter, carousel or tab to be read.
     */
    public function testTheWholeCatalogueSurvivesWithoutJavaScript(): void
    {
        $withoutScripts = Dom::withoutScripts(self::html());
        self::assertStringNotContainsStringIgnoringCase('<script', $withoutScripts);

        $xpath = Dom::of($withoutScripts);

        foreach (self::corpus()->projects() as $project) {
            self::assertCount(
                1,
                Dom::query($xpath, sprintf('//main//a[@href="%s"]', self::href($project))),
                'Missing without JavaScript: ' . self::href($project)
            );
        }

        self::assertCount(0, Dom::query($xpath, '//main//*[@hidden]'), 'Nothing waits for a script');
        self::assertCount(0, Dom::query($xpath, '//main//input'), 'No filter control is required');
        self::assertCount(0, Dom::query($xpath, '//main//select'));
        self::assertCount(0, Dom::query($xpath, '//main//button'), 'No carousel or tab control is required');
        self::assertCount(0, Dom::query($xpath, '//main//noscript'));
        self::assertCount(0, Dom::query($xpath, '//main//a[not(@href)]'));
        self::assertCount(0, Dom::query($xpath, '//main//*[@onclick or @onload or @onmouseover]'));
        self::assertCount(0, Dom::query($xpath, '//main//a[starts-with(@href, "javascript:")]'));
    }

    // --------------------------------------------------------------- media

    /**
     * Criterion 6: no project has an image yet, and that removes no content
     * and collapses no card.
     */
    public function testMissingMediaCostsNoContentAndNoGeometry(): void
    {
        $xpath = self::page();

        self::assertCount(0, Dom::query($xpath, '//main//img'), 'No image element is emitted for a sourceless media');
        self::assertCount(0, Dom::query($xpath, '//main//picture'));
        self::assertCount(0, Dom::query($xpath, '//main//*[@src]'), 'Nothing in the catalogue loads a source');

        foreach (self::corpus()->projects() as $project) {
            $href = self::href($project);
            $media = $project->media();

            self::assertFalse(
                $media->hasSource(),
                'This test asserts the missing-media path; give ' . $href . ' a source and it must be revisited'
            );

            $slot = Dom::element(
                $xpath,
                sprintf('//main//article[.//a[@href="%s"]]//*[@data-facet-media]', $href),
                $href . ' keeps exactly one media slot'
            );

            self::assertSame('img', $slot->getAttribute('role'), 'The slot stands in for a picture');
            self::assertSame($media->description(), $slot->getAttribute('aria-label'), 'It announces the canonical description');
            self::assertSame($media->reference(), $slot->getAttribute('data-facet-media'));
            self::assertStringContainsString('aspect-ratio:', $slot->getAttribute('style'), 'The slot reserves its geometry');
            self::assertSame('', Dom::textOf($slot), 'The slot carries no text of its own');

            // Every card still reads without it.
            $text = Dom::textOf(self::card($xpath, $project));
            self::assertStringContainsString($project->name(), $text);
            self::assertStringContainsString($project->summary(), $text);
        }

        // One slot per card and no more.
        self::assertCount(
            count(self::corpus()->projects()),
            Dom::query($xpath, '//main//*[@data-facet-media]')
        );

        // The reserved ratio is identical everywhere, so the grid is stable.
        $styles = array_unique(Dom::attributes($xpath, '//main//*[@data-facet-media]', 'style'));
        self::assertCount(1, $styles, 'Every card reserves the same deterministic ratio');
    }

    // -------------------------------------------------------------- outline

    /**
     * Criterion 7: one coherent heading outline, and a list that reflows
     * instead of a fixed-width layout.
     */
    public function testTheOutlineIsCoherentAndTheLayoutIsNarrowSafe(): void
    {
        $xpath = self::page();

        $headings = Dom::query($xpath, '//main//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
        self::assertGreaterThan(0, $headings->length);

        $levels = [];

        foreach ($headings as $heading) {
            self::assertNotSame('', Dom::textOf($heading), 'Every heading needs text, not decoration');
            $levels[] = (int) substr($heading->nodeName, 1);
        }

        self::assertSame(1, $levels[0], 'The outline opens on the H1');
        self::assertSame(1, count(array_filter($levels, static fn (int $level): bool => $level === 1)));

        $previous = $levels[0];

        foreach ($levels as $level) {
            self::assertLessThanOrEqual($previous + 1, $level, 'No heading level may be skipped');
            $previous = $level;
        }

        // Each card is announced by its own heading, and the heading is what
        // labels the card's region.
        foreach (self::corpus()->projects() as $project) {
            $card = self::card($xpath, $project);
            $labelledBy = $card->getAttribute('aria-labelledby');

            self::assertNotSame('', $labelledBy, self::href($project) . ' must be a labelled region');

            $heading = Dom::element($xpath, sprintf('//main//h2[@id="%s"]', $labelledBy));
            self::assertSame($project->name(), Dom::textOf($heading));
        }

        // A plain list: no absolute positioning, no fixed track widths.
        $list = Dom::element($xpath, '//main/ul', 'The catalogue is one list');
        self::assertCount(
            count(self::corpus()->projects()),
            Dom::query($xpath, '//main/ul/li'),
            'One list item per project'
        );
        self::assertStringNotContainsString('absolute', $list->getAttribute('class'));
        self::assertCount(0, Dom::query($xpath, '//main//*[@width or @height]'), 'Nothing is pinned to a pixel size');
    }

    /**
     * A display name the shell writes for a canonical machine value.
     *
     * `in-progress`, `education` and `language` are stored vocabularies, not
     * words: since PORT-137 the shell prints the translated label for each in
     * the language of the page, so this is what a rendered page actually says.
     */
    private static function label(string $key): string
    {
        return (new \Facet\I18n\Translator(\Facet\I18n\Locale::Fr))->text($key);
    }

}
