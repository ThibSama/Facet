<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\Project;
use Facet\Content\SkillCategory;
use Facet\Http\Application;
use Facet\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The composed home page, asserted against the DOM the server produced.
 *
 * Two rules drive every assertion below. First, the home page is a *view* of
 * the canonical corpus: whatever it shows must be traceable to a Content
 * object, so the expectations are read from {@see Corpus} at runtime rather
 * than transcribed here — a copy pasted into the template would fail exactly
 * as loudly as a missing one. Second, the page is a document before it is an
 * experience: the structure is checked with scripts stripped, because that is
 * what a visitor without JavaScript actually receives.
 */
final class HomeCompositionTest extends TestCase
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
     * The home document as served in production configuration.
     */
    private static function html(): string
    {
        $application = Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));

        $response = $application->handle(Request::create('GET', '/'));

        self::assertSame(200, $response->status());

        return $response->body();
    }

    /**
     * The same document with every script element removed — the no-JavaScript
     * view of the page.
     */
    private static function htmlWithoutScripts(): string
    {
        $stripped = preg_replace('#<script\b[^>]*>.*?</script>#si', '', self::html());
        self::assertIsString($stripped);

        $stripped = preg_replace('#<script\b[^>]*>#si', '', $stripped);
        self::assertIsString($stripped);

        return $stripped;
    }

    private static function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded, 'The rendered document must parse as HTML');

        return new DOMXPath($document);
    }

    /**
     * @return DOMNodeList<DOMElement>
     */
    private static function query(DOMXPath $xpath, string $expression): DOMNodeList
    {
        $result = $xpath->query($expression);
        self::assertNotFalse($result, 'Invalid XPath: ' . $expression);

        /** @var DOMNodeList<DOMElement> $result */
        return $result;
    }

    private static function normalise(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value);
        self::assertIsString($collapsed);

        return trim($collapsed);
    }

    /**
     * The main landmark's text, whitespace-collapsed, with scripts stripped.
     */
    private static function mainText(): string
    {
        $xpath = self::dom(self::htmlWithoutScripts());
        $main = self::query($xpath, '//main')->item(0);

        self::assertInstanceOf(DOMElement::class, $main);

        return self::normalise($main->textContent);
    }

    // ---------------------------------------------------------------- hero

    /**
     * Criterion 1: one H1, and the identity around it comes from the Profile.
     */
    public function testTheHeroIsBuiltFromTheCanonicalProfile(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());
        $profile = self::corpus()->profile();

        $headings = self::query($xpath, '//h1');
        self::assertCount(1, $headings, 'The home page must have exactly one H1');

        $h1 = $headings->item(0);
        self::assertInstanceOf(DOMElement::class, $h1);
        self::assertSame($profile->name(), self::normalise($h1->textContent));

        $text = self::mainText();
        self::assertStringContainsString($profile->headline(), $text, 'The headline must be visible');
        self::assertStringContainsString($profile->summary(), $text, 'The summary must be visible');
        self::assertStringContainsString($profile->location(), $text);

        foreach ($profile->focusAreas() as $focusArea) {
            self::assertStringContainsString($focusArea, $text, 'Canonical focus areas must be rendered');
        }
    }

    /**
     * Criterion 2: the hero's calls to action are real links to real routes.
     */
    public function testTheHeroLinksToProjectsAndContact(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        foreach (['/projects', '/contact'] as $target) {
            $links = self::query($xpath, sprintf('//main//a[@href="%s"]', $target));

            self::assertGreaterThan(0, $links->length, 'The hero must link to ' . $target);

            $link = $links->item(0);
            self::assertInstanceOf(DOMElement::class, $link);
            self::assertNotSame('', self::normalise($link->textContent), $target . ' link needs a label');
        }
    }

    /**
     * Criterion 2 again, from the other side: nothing on this page is a
     * scripted control dressed up as a link, and no canvas is involved.
     */
    public function testTheHomePageNeedsNoScriptedControl(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        self::assertSame(0, self::query($xpath, '//main//canvas')->length);
        self::assertSame(0, self::query($xpath, '//main//noscript')->length);
        self::assertSame(0, self::query($xpath, '//main//a[not(@href)]')->length);
        self::assertSame(0, self::query($xpath, '//main//*[@onclick or @onload or @onmouseover]')->length);
        self::assertSame(0, self::query($xpath, '//main//a[starts-with(@href, "javascript:")]')->length);
    }

    /**
     * Criterion 3: the environment readout and its kind are gone from the
     * public page.
     */
    public function testTheHomePageShowsNoDevelopmentOrPlaceholderCopy(): void
    {
        $text = self::mainText();

        foreach (['Environment:', 'Lorem ipsum', 'TODO', 'FIXME', 'Coming soon', 'placeholder'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $text);
        }

        $xpath = self::dom(self::htmlWithoutScripts());
        self::assertSame(0, self::query($xpath, '//main//code')->length, 'No debug readout belongs on the home page');
    }

    /**
     * Criteria 4 and 5: the signature-visual slot exists, carries nothing a
     * reader needs, and the portrait having no source hides no information.
     */
    public function testTheHeroSurvivesMissingMedia(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());
        $profile = self::corpus()->profile();

        $slots = self::query($xpath, '//main//*[@data-facet-hero-visual]');
        self::assertCount(1, $slots, 'The hero keeps one anchor for the later signature visual');

        $slot = $slots->item(0);
        self::assertInstanceOf(DOMElement::class, $slot);
        self::assertSame('true', $slot->getAttribute('aria-hidden'), 'The slot is decorative');
        self::assertSame('', self::normalise($slot->textContent), 'The slot must carry no information');

        self::assertFalse(
            $profile->portrait()->hasSource(),
            'This test asserts the missing-media path; give it a source and it must be revisited'
        );

        // Essential hero information is present even though the portrait is not.
        $text = self::mainText();
        self::assertStringContainsString($profile->name(), $text);
        self::assertStringContainsString($profile->headline(), $text);
        self::assertStringContainsString($profile->summary(), $text);

        // No image element is emitted for media that has no source.
        self::assertSame(0, self::query($xpath, '//main//img[not(@src) or @src=""]')->length);
    }

    // ------------------------------------------------- featured projects

    /**
     * Criteria 6 and 9: the featured list is `Corpus::featuredProjects()`,
     * whole and in order, and it is the only project list on the page.
     */
    public function testFeaturedProjectsMatchTheCorpusExactlyAndInOrder(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        $expected = array_map(
            static fn (Project $project): string => '/projects/' . $project->slug()->value(),
            self::corpus()->featuredProjects()
        );

        self::assertNotSame([], $expected, 'The corpus must feature at least one project');

        $rendered = [];

        foreach (self::query($xpath, '//main//a[starts-with(@href, "/projects/")]') as $link) {
            $rendered[] = $link->getAttribute('href');
        }

        self::assertSame($expected, $rendered, 'Rendered project links must be the featured set, in canonical order');

        // A second, non-canonical list would show up as a repeated slug.
        self::assertSame($rendered, array_values(array_unique($rendered)), 'No project may be listed twice');
    }

    /**
     * Criterion 7: every card is useful text plus its detail link, whether or
     * not the project has media or a documented stack.
     */
    public function testEveryFeaturedCardCarriesTextAndItsDetailLink(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        foreach (self::corpus()->featuredProjects() as $project) {
            $href = '/projects/' . $project->slug()->value();

            $links = self::query($xpath, sprintf('//main//a[@href="%s"]', $href));
            self::assertCount(1, $links, 'Exactly one card links to ' . $href);

            $link = $links->item(0);
            self::assertInstanceOf(DOMElement::class, $link);
            self::assertSame($project->name(), self::normalise($link->textContent));

            $cards = self::query($xpath, sprintf('//main//article[.//a[@href="%s"]]', $href));
            self::assertCount(1, $cards, 'Each featured project renders as one card');

            $card = $cards->item(0);
            self::assertInstanceOf(DOMElement::class, $card);

            $cardText = self::normalise($card->textContent);
            self::assertStringContainsString($project->summary(), $cardText, $href . ' must show its summary');

            // The card stands on text alone: no media is required to read it.
            self::assertSame(0, self::query($xpath, sprintf('//main//article[.//a[@href="%s"]]//img', $href))->length);
        }
    }

    /**
     * Criterion 10: technologies, concepts, status and period are shown only
     * where the corpus documents them, and never invented.
     */
    public function testCardsClaimOnlyWhatTheCorpusDocuments(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        foreach (self::corpus()->featuredProjects() as $project) {
            $href = '/projects/' . $project->slug()->value();
            $card = self::query($xpath, sprintf('//main//article[.//a[@href="%s"]]', $href))->item(0);
            self::assertInstanceOf(DOMElement::class, $card);

            $cardText = self::normalise($card->textContent);

            foreach ($project->technologies() as $technology) {
                self::assertStringContainsString($technology, $cardText, $href . ' must show ' . $technology);
            }

            foreach ($project->concepts() as $concept) {
                self::assertStringContainsString($concept, $cardText, $href . ' must show ' . $concept);
            }

            if ($project->technologies() === []) {
                self::assertStringNotContainsString('Technologies:', $cardText, $href . ' has no documented stack');
            }

            if ($project->status()->isSubstantiated()) {
                self::assertStringContainsString($project->status()->value, $cardText);
            } else {
                self::assertStringNotContainsString($project->status()->value, $cardText, 'An unspecified status is not a claim');
            }

            $period = $project->period();

            if ($period === null) {
                self::assertSame(0, self::query($xpath, sprintf('//main//article[.//a[@href="%s"]]//time', $href))->length);
            } else {
                self::assertStringContainsString($period->start(), $cardText);
            }
        }
    }

    // ------------------------------------------------------------- skills

    /**
     * Criteria 8 and 9: every canonical skill is in the SSR HTML, grouped by
     * its own category, in a deterministic order.
     */
    public function testSkillsRenderGroupedByTheirCanonicalCategory(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());
        $text = self::mainText();

        foreach (self::corpus()->skills() as $skill) {
            self::assertStringContainsString($skill->name(), $text, 'Missing skill: ' . $skill->name());
        }

        $categories = [];

        foreach (self::corpus()->skills() as $skill) {
            $categories[$skill->category()->value] = true;
        }

        self::assertNotSame([], $categories, 'The corpus must declare at least one skill category');

        foreach (array_keys($categories) as $category) {
            $group = self::query($xpath, sprintf('//main//section[@aria-labelledby="skills-%s"]', $category));
            self::assertCount(1, $group, 'One group per canonical category: ' . $category);

            $node = $group->item(0);
            self::assertInstanceOf(DOMElement::class, $node);

            $groupText = self::normalise($node->textContent);

            foreach (self::corpus()->skillsIn(SkillCategory::from($category)) as $skill) {
                self::assertStringContainsString($skill->name(), $groupText, $skill->name() . ' belongs to ' . $category);
            }
        }

        // Group order is the enum's declaration order, not the corpus's.
        $rendered = [];

        foreach (self::query($xpath, '//main//section[starts-with(@aria-labelledby, "skills-")]') as $section) {
            $rendered[] = substr($section->getAttribute('aria-labelledby'), strlen('skills-'));
        }

        $expected = array_values(array_filter(
            array_map(
                static fn (SkillCategory $case): string => $case->value,
                SkillCategory::cases()
            ),
            static fn (string $value): bool => isset($categories[$value])
        ));

        self::assertSame($expected, $rendered, 'Skill groups must render in a deterministic order');
    }

    /**
     * Criterion 9 again: the rendering needs no carousel, filter or tab.
     */
    public function testNothingOnThePageIsGatedBehindAScript(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        self::assertSame(0, self::query($xpath, '//main//*[@hidden]')->length, 'Nothing is hidden pending a script');
        self::assertSame(0, self::query($xpath, '//main//input')->length, 'No filter control is required');
        self::assertSame(0, self::query($xpath, '//main//select')->length);
        self::assertSame(0, self::query($xpath, '//main//button')->length, 'No carousel or tab control is required');
    }

    // ------------------------------------------------------------ journey

    /**
     * Criteria 11 and 12: every canonical Experience is rendered, in corpus
     * order, with its own dates and labels, as a plain ordered list.
     */
    public function testTheJourneyRendersEveryExperienceInCanonicalOrder(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());
        $experiences = self::corpus()->experiences();

        self::assertNotSame([], $experiences, 'The corpus must declare at least one experience');

        $sections = self::query($xpath, '//main//section[@aria-labelledby="journey"]');
        self::assertCount(1, $sections);

        $section = $sections->item(0);
        self::assertInstanceOf(DOMElement::class, $section);

        // A list, not a grid of absolutely positioned dots: it reflows.
        $lists = self::query($xpath, '//main//section[@aria-labelledby="journey"]/ol');
        self::assertCount(1, $lists, 'The chronology is an ordered list');

        $items = self::query($xpath, '//main//section[@aria-labelledby="journey"]/ol/li');
        self::assertCount(count($experiences), $items, 'One list item per canonical experience');

        foreach ($experiences as $index => $experience) {
            $item = $items->item($index);
            self::assertInstanceOf(DOMElement::class, $item);

            $itemText = self::normalise($item->textContent);

            self::assertStringContainsString($experience->title(), $itemText, 'Order must follow the corpus');
            self::assertStringContainsString($experience->organisation(), $itemText);
            self::assertStringContainsString($experience->location(), $itemText);
            self::assertStringContainsString($experience->summary(), $itemText);
            self::assertStringContainsString($experience->kind()->value, $itemText, 'The canonical kind is a label');
            self::assertStringContainsString($experience->period()->start(), $itemText, 'Dates are preserved');

            $end = $experience->period()->end();

            if ($end !== null) {
                self::assertStringContainsString($end, $itemText);
            }

            foreach ($experience->highlights() as $highlight) {
                self::assertStringContainsString($highlight, $itemText);
            }

            $time = self::query($xpath, sprintf('//main//section[@aria-labelledby="journey"]/ol/li[%d]//time', $index + 1));
            self::assertGreaterThan(0, $time->length, 'Each entry carries a machine-readable date');

            $first = $time->item(0);
            self::assertInstanceOf(DOMElement::class, $first);
            self::assertSame($experience->period()->start(), $first->getAttribute('datetime'));
        }
    }

    /**
     * Criterion 11's other half: the journey states the experience record, it
     * does not restate the biography that /about already carries.
     */
    public function testTheJourneyDoesNotRepeatTheBiography(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());
        $section = self::query($xpath, '//main//section[@aria-labelledby="journey"]')->item(0);

        self::assertInstanceOf(DOMElement::class, $section);
        self::assertStringNotContainsString(
            self::corpus()->profile()->summary(),
            self::normalise($section->textContent),
            'The journey must not duplicate the profile summary'
        );
    }

    // -------------------------------------------------------- outline, CTA

    /**
     * Criterion 13: the page ends on an explicit contact call to action.
     */
    public function testThePageEndsOnAContactCallToAction(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        $sections = self::query($xpath, '//main/section');
        self::assertGreaterThan(0, $sections->length);

        $last = $sections->item($sections->length - 1);
        self::assertInstanceOf(DOMElement::class, $last);
        self::assertSame('get-in-touch', $last->getAttribute('aria-labelledby'), 'The last section is the CTA');

        $links = self::query($xpath, '//main/section[@aria-labelledby="get-in-touch"]//a[@href="/contact"]');
        self::assertCount(1, $links, 'The closing CTA links to /contact');

        $link = $links->item(0);
        self::assertInstanceOf(DOMElement::class, $link);
        self::assertNotSame('', self::normalise($link->textContent));
    }

    /**
     * Criterion 14: the headings form one coherent outline — a single H1, an
     * H2 per top-level section, and no level skipped.
     */
    public function testTheHeadingOutlineIsCoherent(): void
    {
        $xpath = self::dom(self::htmlWithoutScripts());

        $levels = [];
        $labels = [];

        foreach (self::query($xpath, '//main//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $heading) {
            $levels[] = (int) substr($heading->nodeName, 1);
            $labels[] = self::normalise($heading->textContent);
        }

        self::assertNotSame([], $levels);
        self::assertSame(1, $levels[0], 'The outline opens on the H1');
        self::assertSame(1, count(array_filter($levels, static fn (int $level): bool => $level === 1)));

        $previous = $levels[0];

        foreach ($levels as $index => $level) {
            self::assertLessThanOrEqual($previous + 1, $level, 'Heading level skipped before: ' . $labels[$index]);
            self::assertNotSame('', $labels[$index], 'Every heading needs text, not decoration');
            $previous = $level;
        }

        // The four composed sections are each announced by an H2.
        $h2 = [];

        foreach (self::query($xpath, '//main//h2') as $heading) {
            $h2[] = $heading->getAttribute('id');
        }

        self::assertSame(['featured-projects', 'skills', 'journey', 'get-in-touch'], $h2);
    }

    /**
     * The whole composition survives the no-JavaScript view: hero, projects,
     * skills, journey and the closing CTA are all in the served HTML.
     */
    public function testEverySectionSurvivesWithScriptsStripped(): void
    {
        $withoutScripts = self::htmlWithoutScripts();
        $xpath = self::dom($withoutScripts);

        self::assertStringNotContainsStringIgnoringCase('<script', $withoutScripts);

        foreach (['hero-title', 'featured-projects', 'skills', 'journey', 'get-in-touch'] as $anchor) {
            self::assertGreaterThan(
                0,
                self::query($xpath, sprintf('//main//*[@id="%s"]', $anchor))->length,
                'Missing without JavaScript: ' . $anchor
            );
        }
    }
}
