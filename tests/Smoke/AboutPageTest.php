<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMElement;
use DOMXPath;
use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * The About page at /about, asserted against the DOM the server produced with
 * scripts stripped.
 *
 * Two questions drive every assertion. Is everything on the page traceable to
 * a Content object — so the expectations are read from {@see Corpus} at
 * runtime, and a fact typed into the template would fail as loudly as a
 * missing one? And does the page earn its place next to home — so the
 * canonical prose home already shows is required to be *absent* here, and the
 * canonical depth home omits is required to be present.
 */
final class AboutPageTest extends TestCase
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

    private static function html(string $path): string
    {
        $application = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));

        $response = $application->handle(Request::create('GET', $path));

        self::assertSame(200, $response->status(), $path);

        return $response->body();
    }

    /**
     * The page as a visitor without JavaScript receives it.
     */
    private static function page(): DOMXPath
    {
        return Dom::of(Dom::withoutScripts(self::html('/about')));
    }

    private static function main(DOMXPath $xpath): DOMElement
    {
        return Dom::element($xpath, '//main');
    }

    private static function mainText(): string
    {
        return Dom::textOf(self::main(self::page()));
    }

    /**
     * The text of home's own skills section — what a reader is told about a
     * skill on the home page, and nothing else on it.
     */
    private static function homeSkillsText(): string
    {
        return Dom::textOf(Dom::element(
            Dom::of(Dom::withoutScripts(self::html('/'))),
            '//main//section[@aria-labelledby="skills"]'
        ));
    }

    // ------------------------------------------------------------ depth

    /**
     * Criterion 2: the page carries canonical depth home does not — every
     * skill's own summary, which home shows as a bare name.
     */
    public function testEverySkillIsShownWithItsCanonicalSummary(): void
    {
        $xpath = self::page();
        $skills = self::corpus()->skills();

        self::assertNotEmpty($skills);

        foreach ($skills as $skill) {
            $term = Dom::element(
                $xpath,
                sprintf('//main//dl/div[dt[normalize-space()="%s"]]', $skill->name()),
                $skill->name() . ' must appear exactly once as a described term'
            );

            self::assertStringContainsString(
                $skill->summary(),
                Dom::textOf($term),
                $skill->name() . ' must be described by its canonical summary'
            );
        }

        // And the summaries really are the thing home leaves out, so this page
        // is not a second copy of it: home's skills section names skills and
        // says nothing more about them.
        $home = self::homeSkillsText();

        foreach ($skills as $skill) {
            self::assertStringNotContainsString($skill->summary(), $home);
        }
    }

    /**
     * Criterion 2: the profile's canonical outbound links, which home does not
     * render at all, with nothing added to them.
     */
    public function testProfileLinksAreRenderedFromTheCorpusAndNowhereElse(): void
    {
        $xpath = self::page();
        $links = self::corpus()->profile()->links();

        self::assertNotEmpty($links, 'The canonical profile documents no link to render');

        $rendered = Dom::attributes($xpath, '//main//section[@aria-labelledby="elsewhere"]//a', 'href');

        self::assertSame(
            array_map(static fn ($link): string => $link->url(), $links),
            $rendered,
            'Elsewhere must list exactly the canonical profile links, in corpus order'
        );

        foreach ($links as $link) {
            $anchor = Dom::element(
                $xpath,
                sprintf('//main//a[@href="%s"]', $link->url())
            );

            self::assertSame($link->label(), Dom::textOf($anchor));
            self::assertSame($link->type()->value, $anchor->getAttribute('data-link-type'));
            self::assertSame('noopener noreferrer', $anchor->getAttribute('rel'));
        }

        // No address the corpus does not document: no mailto:, no tel:.
        foreach (Dom::attributes($xpath, '//main//a', 'href') as $href) {
            self::assertStringStartsNotWith('mailto:', $href);
            self::assertStringStartsNotWith('tel:', $href);
        }
    }

    /**
     * Criterion 2: the background record is the canonical entries grouped by
     * their canonical kind, carrying what, where and when.
     */
    public function testBackgroundListsEveryExperienceUnderItsCanonicalKind(): void
    {
        $xpath = self::page();

        foreach (self::corpus()->experiences() as $experience) {
            $item = Dom::element(
                $xpath,
                sprintf(
                    '//main//section[@aria-labelledby="background-%s"]//li[p[normalize-space()="%s"]]',
                    $experience->kind()->value,
                    $experience->title()
                ),
                $experience->title() . ' must appear once under its canonical kind'
            );

            $text = Dom::textOf($item);

            self::assertStringContainsString($experience->organisation(), $text);
            self::assertStringContainsString($experience->location(), $text);
            self::assertStringContainsString($experience->period()->start(), $text);
        }
    }

    // ------------------------------------------------- no duplicated home

    /**
     * Criteria 1 and 6: the prose home already shows is not repeated here.
     */
    public function testTheHomeSummaryAndJourneyProseAreNotRepeated(): void
    {
        $text = self::mainText();
        $profile = self::corpus()->profile();

        $skillSummaries = array_map(
            static fn ($skill): string => $skill->summary(),
            self::corpus()->skills()
        );

        self::assertStringNotContainsString(
            $profile->summary(),
            $text,
            'The profile summary belongs to home and must not be repeated'
        );

        foreach (self::corpus()->experiences() as $experience) {
            self::assertStringNotContainsString(
                $experience->summary(),
                $text,
                $experience->title() . ': the journey summary must not be repeated'
            );

            foreach ($experience->highlights() as $highlight) {
                if (in_array($highlight, $skillSummaries, true)) {
                    // The corpus states one sentence twice — a certification is
                    // both a skill's summary and the highlight of the year it
                    // was earned. Requiring it absent here would forbid the
                    // skill record this page exists to show.
                    continue;
                }

                self::assertStringNotContainsString(
                    $highlight,
                    $text,
                    $experience->title() . ': journey highlights must not be repeated'
                );
            }
        }
    }

    // ------------------------------------------------------- structure

    /**
     * Criterion 3: one H1, and a heading outline that never skips a level.
     */
    public function testTheDocumentHasOneH1AndACoherentHeadingOutline(): void
    {
        $xpath = self::page();

        $h1 = Dom::element($xpath, '//main//h1', 'The page must have exactly one H1');
        self::assertStringContainsString(self::corpus()->profile()->name(), Dom::textOf($h1));

        $levels = [];

        foreach (Dom::query($xpath, '//main//*[self::h1 or self::h2 or self::h3 or self::h4]') as $heading) {
            $levels[] = (int) substr($heading->nodeName, 1);
            self::assertNotSame('', Dom::textOf($heading), 'No heading may be empty');
        }

        self::assertSame(1, $levels[0] ?? 0, 'The first heading is the H1');

        $previous = 1;

        foreach ($levels as $level) {
            self::assertLessThanOrEqual($previous + 1, $level, 'The outline must not skip a level');
            $previous = $level;
        }

        self::assertSame(1, count(array_filter($levels, static fn (int $l): bool => $l === 1)));
    }

    /**
     * Criterion 3: the two site links the page owes a reader are real routes
     * this application serves, not decoration.
     */
    public function testTheProjectsAndContactLinksAreRealServedRoutes(): void
    {
        $xpath = self::page();

        foreach (['/projects', '/contact'] as $target) {
            self::assertGreaterThan(
                0,
                Dom::query($xpath, sprintf('//main//a[@href="%s"]', $target))->length,
                $target . ' must be linked from About'
            );

            self::html($target);
        }
    }

    // ----------------------------------------------------------- media

    /**
     * Criterion 5: the corpus documents no portrait source, so the page emits
     * no image element at all — and loses no information for it.
     */
    public function testTheMissingPortraitNeitherBreaksNorHidesAnything(): void
    {
        $xpath = self::page();
        $portrait = self::corpus()->profile()->portrait();

        self::assertFalse($portrait->hasSource(), 'This expectation only holds while no portrait exists');

        self::assertSame(0, Dom::query($xpath, '//main//img')->length, 'No image element may be emitted');

        $slot = Dom::element($xpath, '//main//*[@data-facet-media]');

        self::assertSame('img', $slot->getAttribute('role'));
        self::assertSame($portrait->description(), $slot->getAttribute('aria-label'));
        self::assertSame($portrait->reference(), $slot->getAttribute('data-facet-media'));
        self::assertSame('', Dom::textOf($slot), 'The slot carries no text a reader would otherwise lose');

        // Everything the page says survives without the slot: its removal
        // leaves the identity line, the skills and the background intact.
        $text = self::mainText();

        self::assertStringContainsString(self::corpus()->profile()->headline(), $text);
        self::assertStringContainsString(self::corpus()->skills()[0]->summary(), $text);
    }

    // ------------------------------------------------------ no invention

    /**
     * Criterion 4: nothing on the page is written rather than sourced. Every
     * sentence-length string in the document is either canonical text or one
     * of the short chrome labels the skin is allowed to author.
     */
    public function testThePageStatesNoFactTheCorpusDoesNotCarry(): void
    {
        $text = self::mainText();

        foreach ([
            'passionate',
            'my approach',
            'years of experience',
            'lorem',
            'TODO',
            'FIXME',
            'coming soon',
            'placeholder',
            'available for',
            'hire me',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                mb_strtolower($text),
                'About must not claim: ' . $forbidden
            );
        }

        // The template's own prose is limited to headings and link labels: no
        // paragraph on the page is anything but canonical text.
        $xpath = self::page();
        $canonical = self::canonicalFragments();

        foreach (Dom::query($xpath, '//main//p') as $paragraph) {
            // Link labels are navigation chrome the skin is allowed to write;
            // what is checked here is the prose left when they are removed.
            $value = Dom::textOf($paragraph);

            foreach (iterator_to_array($paragraph->getElementsByTagName('a')) as $anchor) {
                $value = str_replace(Dom::textOf($anchor), '', $value);
            }

            $value = Dom::normalise($value);

            if ($value === '') {
                continue;
            }

            self::assertTrue(
                self::isCanonical($value, $canonical),
                'A paragraph states something no Content object carries: ' . $value
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function canonicalFragments(): array
    {
        $fragments = self::corpus()->textFragments();

        foreach (self::corpus()->experiences() as $experience) {
            $period = $experience->period();
            $fragments[] = $period->start();
            $fragments[] = (string) $period->end();
        }

        return array_values(array_filter($fragments, static fn (string $f): bool => trim($f) !== ''));
    }

    /**
     * A rendered paragraph is canonical when removing every canonical fragment
     * and the skin's joining punctuation leaves nothing behind.
     *
     * @param list<string> $canonical
     */
    private static function isCanonical(string $value, array $canonical): bool
    {
        $remainder = $value;

        foreach ($canonical as $fragment) {
            $remainder = str_replace($fragment, '', $remainder);
        }

        return trim(str_replace(['—', '·', '-', 'present', ',', '.'], '', $remainder)) === '';
    }
}
