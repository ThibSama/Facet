<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use Facet\Content\Experience;
use Facet\Content\ExperienceKind;
use Facet\Content\Link;
use Facet\Content\LinkType;
use Facet\Content\Media;
use Facet\Content\Period;
use Facet\Content\Profile;
use Facet\Content\Project;
use Facet\Content\ProjectStatus;
use Facet\Content\Skill;
use Facet\Content\SkillCategory;
use Facet\Support\Slug;

/**
 * Synthetic fixtures for contract tests.
 *
 * Deliberately obvious placeholders: nothing here may be mistaken for, or leak
 * into, the real corpus.
 */
final class ContentFactory
{
    public static function profile(): Profile
    {
        return Profile::create(
            'Fixture Person',
            'Fixture headline',
            'Fixture location',
            'Fixture summary.',
            ['fixture area'],
            [Link::create('Fixture link', 'https://example.com/', LinkType::Website)],
            Media::pending('Fixture portrait description')
        );
    }

    public static function project(string $slug = 'fixture-project'): Project
    {
        return Project::create(
            Slug::fromString($slug),
            'Fixture Project',
            'Fixture summary.',
            'Fixture context.',
            'Fixture role.',
            ['Fixture Technology'],
            ['fixture concept'],
            ProjectStatus::Completed,
            ['Fixture outcome.'],
            Period::create('2024', '2024'),
            [],
            Media::pending('Fixture media description'),
            false
        );
    }

    public static function skill(string $slug = 'fixture-skill'): Skill
    {
        return Skill::create(
            Slug::fromString($slug),
            'Fixture Skill',
            SkillCategory::Tooling,
            'Fixture skill summary.'
        );
    }

    public static function experience(string $slug = 'fixture-experience'): Experience
    {
        return Experience::create(
            Slug::fromString($slug),
            ExperienceKind::Education,
            'Fixture Title',
            'Fixture Organisation',
            'Fixture Location',
            Period::create('2020', '2021'),
            'Fixture experience summary.'
        );
    }
}
