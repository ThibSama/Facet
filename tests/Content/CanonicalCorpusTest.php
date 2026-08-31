<?php

declare(strict_types=1);

namespace Facet\Tests\Content;

use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\ExperienceKind;
use Facet\Content\Link;
use Facet\Content\LinkType;
use Facet\Content\Project;
use Facet\Content\SkillCategory;
use Facet\Routing\RouteCatalog;
use Facet\Support\Slug;
use PHPUnit\Framework\TestCase;

/**
 * Loads the real, shipped corpus and asserts it is complete, coherent and
 * consumable by a renderer that knows nothing about it.
 */
final class CanonicalCorpusTest extends TestCase
{
    /**
     * The exact public project corpus of this MVP.
     *
     * This is an explicit editorial shortlist, not an exhaustive career
     * archive: study work, the previous portfolio and Facet itself are
     * deliberately absent, and only a new editorial decision may reintroduce
     * one. Pinning the set here means a legacy or sixth entry fails loudly
     * instead of silently reappearing on the public site.
     *
     * @var list<string>
     */
    private const CANONICAL_PROJECTS = [
        'kushim',
        'scora',
        'biogazen',
        'eszter-gyori',
        'math-l-home',
    ];

    /**
     * Projects whose technical stack a canonical source actually documents.
     *
     * Everywhere else `technologies` stays empty: an absent stack is a fact
     * about the sources, and filling it in from a plausible guess would be
     * inventing one.
     *
     * @var list<string>
     */
    private const DOCUMENTED_STACK = ['kushim', 'eszter-gyori'];

    /**
     * Projects that have reached a substantiated, quotable result.
     *
     * @var list<string>
     */
    private const DOCUMENTED_OUTCOMES = ['kushim'];

    private static ?Corpus $corpus = null;

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default()->load();
    }

    public function testTheCorpusShipsExactlyTheCanonicalProjects(): void
    {
        $slugs = array_map(
            static fn (Project $project): string => $project->slug()->value(),
            self::corpus()->projects()
        );

        self::assertSame(
            self::CANONICAL_PROJECTS,
            $slugs,
            'The public project list is an editorial shortlist: adding or removing an entry is a decision, not a side effect'
        );
    }

    public function testTheFullCorpusLoads(): void
    {
        $corpus = self::corpus();

        self::assertNotSame('', $corpus->profile()->name());
        self::assertNotEmpty($corpus->projects(), 'The corpus must ship projects');
        self::assertNotEmpty($corpus->skills(), 'The corpus must ship skills');
        self::assertNotEmpty($corpus->experiences(), 'The corpus must ship experiences');
    }

    public function testEveryProjectSlugIsCanonicalAndUnique(): void
    {
        $seen = [];

        foreach (self::corpus()->projects() as $project) {
            $slug = $project->slug()->value();

            self::assertTrue(Slug::isValid($slug), $slug . ' must satisfy the canonical grammar');
            self::assertNotContains($slug, $seen, 'Slug ' . $slug . ' is duplicated');

            $seen[] = $slug;
        }
    }

    public function testEverySkillAndExperienceSlugIsCanonical(): void
    {
        foreach (self::corpus()->skills() as $skill) {
            self::assertTrue(Slug::isValid($skill->slug()->value()), $skill->slug()->value());
        }

        foreach (self::corpus()->experiences() as $experience) {
            self::assertTrue(Slug::isValid($experience->slug()->value()), $experience->slug()->value());
        }
    }

    public function testEveryProjectIsReachableThroughItsCanonicalRoute(): void
    {
        $route = RouteCatalog::get(RouteCatalog::PROJECTS_SHOW);

        foreach (self::corpus()->projects() as $project) {
            $slug = $project->slug()->value();

            // The same project in both languages, at two canonical URLs that
            // differ only by the language segment. The slug is a fact and does
            // not translate, which is what keeps the two pages the same page.
            foreach (\Facet\I18n\Locale::supported() as $locale) {
                self::assertSame(
                    '/' . $locale->value . '/projects/' . $slug,
                    $route->toPath(['locale' => $locale->value, 'slug' => $slug])
                );
            }

            self::assertNotNull(
                self::corpus()->findProject(Slug::fromString($slug)),
                'The route parameter must resolve back to the project'
            );
        }
    }

    public function testEveryProjectCarriesTheFullEditorialPayload(): void
    {
        foreach (self::corpus()->projects() as $project) {
            $where = $project->slug()->value();

            self::assertNotSame('', trim($project->name()), $where . ': name');
            self::assertNotSame('', trim($project->summary()), $where . ': summary');
            self::assertNotSame('', trim($project->context()), $where . ': context');
            self::assertNotSame('', trim($project->role()), $where . ': role');
            self::assertNotEmpty($project->concepts(), $where . ': concepts');
            self::assertNotSame('', trim($project->media()->description()), $where . ': media description');
        }
    }

    /**
     * A stack and a result are claims. Only the projects whose sources
     * substantiate them may carry one; the rest must stay empty rather than
     * plausible.
     */
    public function testOnlyDocumentedProjectsClaimAStackOrAResult(): void
    {
        foreach (self::corpus()->projects() as $project) {
            $slug = $project->slug()->value();

            if (in_array($slug, self::DOCUMENTED_STACK, true)) {
                self::assertNotEmpty($project->technologies(), $slug . ': documented stack');
            } else {
                self::assertSame([], $project->technologies(), $slug . ': stack must not be guessed');
            }

            if (in_array($slug, self::DOCUMENTED_OUTCOMES, true)) {
                self::assertNotEmpty($project->outcomes(), $slug . ': documented outcomes');
            } else {
                self::assertSame([], $project->outcomes(), $slug . ': outcomes must not be guessed');
            }
        }
    }

    public function testTechnologiesAndConceptsNeverOverlap(): void
    {
        foreach (self::corpus()->projects() as $project) {
            $technologies = array_map('mb_strtolower', $project->technologies());
            $concepts = array_map('mb_strtolower', $project->concepts());

            self::assertSame(
                [],
                array_values(array_intersect($technologies, $concepts)),
                $project->slug()->value() . ': a tool must not also be listed as a concept'
            );
        }
    }

    public function testFeaturedProjectsAreACoherentSubset(): void
    {
        $featured = self::corpus()->featuredProjects();

        self::assertNotEmpty($featured, 'At least one project must be featured');
        self::assertLessThan(
            count(self::corpus()->projects()),
            count($featured),
            'Featuring everything is featuring nothing'
        );

        foreach ($featured as $project) {
            self::assertNotSame(
                'archived',
                $project->status()->value,
                $project->slug()->value() . ' is archived and must not be a priority project'
            );
        }
    }

    public function testEveryLinkIsAnAbsoluteHttpUrl(): void
    {
        $links = self::corpus()->links();

        self::assertNotEmpty($links);

        foreach ($links as $link) {
            self::assertTrue(
                Link::isAbsoluteHttpUrl($link->url()),
                $link->url() . ' must be an absolute http(s) URL'
            );
            self::assertNotSame('', trim($link->label()));
        }
    }

    public function testRepositoryLinksPointAtGitHub(): void
    {
        foreach (self::corpus()->links() as $link) {
            if ($link->type() !== LinkType::Repository) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#',
                $link->url(),
                'A repository link must be a concrete GitHub repository URL'
            );
        }
    }

    public function testMediaIsOptionalAndAlwaysHasAFallback(): void
    {
        foreach (self::corpus()->entries() as $entry) {
            // Whatever the entry, its textual fragments must all be usable.
            foreach ($entry->textFragments() as $fragment) {
                self::assertNotSame('', trim($fragment));
            }
        }

        foreach (self::corpus()->projects() as $project) {
            $media = $project->media();

            self::assertNotSame('', trim($media->reference()), 'Media always resolves to something renderable');
            self::assertNotSame('', trim($media->description()), 'Media always carries a text equivalent');
        }

        $portrait = self::corpus()->profile()->portrait();
        self::assertNotSame('', trim($portrait->reference()));
        self::assertNotSame('', trim($portrait->description()));
    }

    public function testCorpusIsUsableWithNoFinalImages(): void
    {
        // The corpus must be complete before a single asset exists: nothing may
        // depend on a media source actually being set.
        $withoutSource = array_filter(
            self::corpus()->projects(),
            static fn (Project $p): bool => !$p->media()->hasSource()
        );

        self::assertNotEmpty($withoutSource, 'This guard is meaningless if every project already has an image');

        foreach ($withoutSource as $project) {
            self::assertSame(
                \Facet\Content\Media::FALLBACK_REFERENCE,
                $project->media()->reference(),
                $project->slug()->value() . ' must resolve to the shared fallback'
            );
        }
    }

    public function testSkillsCoverEveryDeclaredCategory(): void
    {
        foreach (SkillCategory::cases() as $category) {
            self::assertNotEmpty(
                self::corpus()->skillsIn($category),
                'Category ' . $category->value . ' must have at least one skill'
            );
        }
    }

    public function testExperiencesRecordEducationAndClaimNoEmployment(): void
    {
        self::assertNotEmpty(self::corpus()->experiencesOfKind(ExperienceKind::Education));

        // Employment is a fact that has to be substantiated. Nothing in the
        // sources supports a professional entry, so there must not be one.
        self::assertSame(
            [],
            self::corpus()->experiencesOfKind(ExperienceKind::Professional),
            'A professional experience must never appear without a verified source'
        );
    }

    public function testExperiencePeriodsAreOrderedAndPlausible(): void
    {
        foreach (self::corpus()->experiences() as $experience) {
            $period = $experience->period();
            $year = $period->startYear();

            self::assertGreaterThanOrEqual(1990, $year, $experience->slug()->value());
            self::assertLessThanOrEqual((int) date('Y') + 1, $year, $experience->slug()->value());
        }
    }
}
