<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Content;

use Facet\Content\Corpus;
use Facet\Content\Exception\DuplicateSlugException;
use Facet\Support\Slug;
use Facet\Tests\Support\ContentFactory;
use PHPUnit\Framework\TestCase;

final class CorpusIntegrityTest extends TestCase
{
    public function testDuplicateProjectSlugFailsAtConstruction(): void
    {
        $this->expectException(DuplicateSlugException::class);
        $this->expectExceptionMessageMatches('/Duplicate slug "clash" in collection "projects"/');

        Corpus::create(
            ContentFactory::profile(),
            [ContentFactory::project('clash'), ContentFactory::project('clash')],
            [],
            []
        );
    }

    public function testDuplicateSkillSlugFailsAtConstruction(): void
    {
        $this->expectException(DuplicateSlugException::class);
        $this->expectExceptionMessageMatches('/collection "skills"/');

        Corpus::create(
            ContentFactory::profile(),
            [],
            [ContentFactory::skill('clash'), ContentFactory::skill('clash')],
            []
        );
    }

    public function testDuplicateExperienceSlugFailsAtConstruction(): void
    {
        $this->expectException(DuplicateSlugException::class);
        $this->expectExceptionMessageMatches('/collection "experiences"/');

        Corpus::create(
            ContentFactory::profile(),
            [],
            [],
            [ContentFactory::experience('clash'), ContentFactory::experience('clash')]
        );
    }

    public function testDuplicateFailureIsDeterministic(): void
    {
        $messages = [];

        for ($i = 0; $i < 3; $i++) {
            try {
                Corpus::create(
                    ContentFactory::profile(),
                    [ContentFactory::project('clash'), ContentFactory::project('clash')],
                    [],
                    []
                );
            } catch (DuplicateSlugException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(3, $messages);
        self::assertCount(1, array_unique($messages), 'The same collision must always report identically');
    }

    public function testTheSameSlugMayExistInDifferentCollections(): void
    {
        $corpus = Corpus::create(
            ContentFactory::profile(),
            [ContentFactory::project('shared')],
            [ContentFactory::skill('shared')],
            [ContentFactory::experience('shared')]
        );

        self::assertCount(1, $corpus->projects());
        self::assertCount(1, $corpus->skills());
        self::assertCount(1, $corpus->experiences());
    }

    public function testProjectLookupBySlug(): void
    {
        $corpus = Corpus::create(
            ContentFactory::profile(),
            [ContentFactory::project('alpha'), ContentFactory::project('beta')],
            [],
            []
        );

        self::assertTrue($corpus->hasProject(Slug::fromString('alpha')));
        self::assertNotNull($corpus->findProject(Slug::fromString('beta')));
        self::assertFalse($corpus->hasProject(Slug::fromString('gamma')));
        self::assertNull($corpus->findProject(Slug::fromString('gamma')), 'Unknown slug resolves to null, not an error');
    }

    public function testEntriesCoverEveryCollection(): void
    {
        $corpus = Corpus::create(
            ContentFactory::profile(),
            [ContentFactory::project('alpha')],
            [ContentFactory::skill('beta')],
            [ContentFactory::experience('gamma')]
        );

        // profile + 1 project + 1 skill + 1 experience
        self::assertCount(4, $corpus->entries());
        self::assertNotEmpty($corpus->textFragments());
    }
}
