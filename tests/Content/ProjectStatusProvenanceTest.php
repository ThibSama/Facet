<?php

declare(strict_types=1);

namespace Facet\Tests\Content;

use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\Project;
use Facet\Content\ProjectStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the provenance rule for project lifecycle claims.
 *
 * `completed` and `archived` are editorial assertions about intent. Repository
 * metadata — an old last-push date, an inactive repository, a superseding
 * project — cannot substantiate them. Entries with no canonical source must
 * carry {@see ProjectStatus::Unspecified} and stay in the corpus, so the
 * absence of a claim is visible instead of being guessed away.
 */
final class ProjectStatusProvenanceTest extends TestCase
{
    /**
     * Projects whose lifecycle no canonical source establishes.
     *
     * @var list<string>
     */
    private const UNSUBSTANTIATED = ['biogazen', 'eszter-gyori', 'math-l-home'];

    private static ?Corpus $corpus = null;

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default()->load();
    }

    private static function project(string $slug): Project
    {
        foreach (self::corpus()->projects() as $project) {
            if ($project->slug()->value() === $slug) {
                return $project;
            }
        }

        self::fail($slug . ' must still be present in the corpus');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsubstantiatedProjects(): iterable
    {
        foreach (self::UNSUBSTANTIATED as $slug) {
            yield $slug => [$slug];
        }
    }

    #[DataProvider('unsubstantiatedProjects')]
    public function testUnsubstantiatedProjectsLoadAsUnspecified(string $slug): void
    {
        $status = self::project($slug)->status();

        self::assertSame(
            ProjectStatus::Unspecified,
            $status,
            $slug . ' has no canonical lifecycle source and must not claim one'
        );
        self::assertFalse($status->isSubstantiated());
        self::assertFalse($status->isActive(), 'Unknown must not be read as active');
    }

    #[DataProvider('unsubstantiatedProjects')]
    public function testUnsubstantiatedProjectsKeepTheirFullPayload(string $slug): void
    {
        $project = self::project($slug);

        self::assertNotSame('', $project->name());
        self::assertNotSame('', $project->summary());
        self::assertNotSame('', $project->context());
        self::assertNotSame('', $project->role());
        self::assertNotEmpty($project->concepts());
        self::assertNotSame('', $project->media()->description());

        // An absent lifecycle claim never licences filling the gap elsewhere.
        foreach ($project->links() as $link) {
            self::assertNotSame('', trim($link->url()), $slug . ' must keep its substantiated links intact');
        }
    }

    public function testTheNeutralStateIsDistinctFromEveryLifecycleClaim(): void
    {
        foreach ([ProjectStatus::InProgress, ProjectStatus::Completed, ProjectStatus::Archived] as $claim) {
            self::assertNotSame(ProjectStatus::Unspecified, $claim);
            self::assertTrue($claim->isSubstantiated());
        }

        self::assertSame('unspecified', ProjectStatus::Unspecified->value);
        self::assertNull(ProjectStatus::tryFrom('unknown'), 'The neutral state has exactly one spelling');
    }

    public function testEveryProjectStatusIsAKnownCase(): void
    {
        $projects = self::corpus()->projects();

        self::assertCount(5, $projects, 'The corpus must stay complete');

        foreach ($projects as $project) {
            self::assertContains($project->status(), ProjectStatus::cases());
        }
    }
}
