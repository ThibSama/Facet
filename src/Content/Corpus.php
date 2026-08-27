<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Content\Exception\DuplicateSlugException;
use Facet\Support\Slug;

/**
 * The complete canonical content set.
 *
 * Uniqueness of slugs is enforced here, at construction, so it is impossible to
 * hold a Corpus whose slugs collide — however the content was obtained.
 */
final class Corpus
{
    private Profile $profile;

    /** @var array<string, Project> */
    private array $projects;

    /** @var array<string, Skill> */
    private array $skills;

    /** @var array<string, Experience> */
    private array $experiences;

    /**
     * @param array<string, Project>    $projects
     * @param array<string, Skill>      $skills
     * @param array<string, Experience> $experiences
     */
    private function __construct(
        Profile $profile,
        array $projects,
        array $skills,
        array $experiences
    ) {
        $this->profile = $profile;
        $this->projects = $projects;
        $this->skills = $skills;
        $this->experiences = $experiences;
    }

    /**
     * @param list<Project>    $projects
     * @param list<Skill>      $skills
     * @param list<Experience> $experiences
     *
     * @throws DuplicateSlugException when any collection repeats a slug
     */
    public static function create(
        Profile $profile,
        array $projects,
        array $skills,
        array $experiences
    ): self {
        $indexedProjects = [];
        foreach ($projects as $project) {
            $key = $project->slug()->value();

            if (isset($indexedProjects[$key])) {
                throw DuplicateSlugException::inCollection('projects', $key);
            }

            $indexedProjects[$key] = $project;
        }

        $indexedSkills = [];
        foreach ($skills as $skill) {
            $key = $skill->slug()->value();

            if (isset($indexedSkills[$key])) {
                throw DuplicateSlugException::inCollection('skills', $key);
            }

            $indexedSkills[$key] = $skill;
        }

        $indexedExperiences = [];
        foreach ($experiences as $experience) {
            $key = $experience->slug()->value();

            if (isset($indexedExperiences[$key])) {
                throw DuplicateSlugException::inCollection('experiences', $key);
            }

            $indexedExperiences[$key] = $experience;
        }

        return new self($profile, $indexedProjects, $indexedSkills, $indexedExperiences);
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    /**
     * @return list<Project>
     */
    public function projects(): array
    {
        return array_values($this->projects);
    }

    /**
     * Editorial priority selection, in declaration order.
     *
     * @return list<Project>
     */
    public function featuredProjects(): array
    {
        return array_values(array_filter(
            $this->projects,
            static fn (Project $project): bool => $project->isFeatured()
        ));
    }

    public function hasProject(Slug $slug): bool
    {
        return isset($this->projects[$slug->value()]);
    }

    /**
     * Resolves the `/projects/{slug}` route parameter. Returns null rather than
     * throwing: an unknown slug is a 404 for the caller to decide about.
     */
    public function findProject(Slug $slug): ?Project
    {
        return $this->projects[$slug->value()] ?? null;
    }

    /**
     * @return list<Skill>
     */
    public function skills(): array
    {
        return array_values($this->skills);
    }

    /**
     * @return list<Skill>
     */
    public function skillsIn(SkillCategory $category): array
    {
        return array_values(array_filter(
            $this->skills,
            static fn (Skill $skill): bool => $skill->category() === $category
        ));
    }

    /**
     * @return list<Experience>
     */
    public function experiences(): array
    {
        return array_values($this->experiences);
    }

    /**
     * @return list<Experience>
     */
    public function experiencesOfKind(ExperienceKind $kind): array
    {
        return array_values(array_filter(
            $this->experiences,
            static fn (Experience $experience): bool => $experience->kind() === $kind
        ));
    }

    /**
     * Every entry in the corpus, for exhaustive traversal.
     *
     * @return list<TextualEntry>
     */
    public function entries(): array
    {
        return array_merge(
            [$this->profile],
            $this->projects(),
            $this->skills(),
            $this->experiences()
        );
    }

    /**
     * The whole corpus walked as plain text, in a stable order.
     *
     * @return list<string>
     */
    public function textFragments(): array
    {
        $fragments = [];

        foreach ($this->entries() as $entry) {
            foreach ($entry->textFragments() as $fragment) {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    /**
     * Every link the corpus declares, across all entry kinds.
     *
     * @return list<Link>
     */
    public function links(): array
    {
        $links = $this->profile->links();

        foreach ($this->projects() as $project) {
            foreach ($project->links() as $link) {
                $links[] = $link;
            }
        }

        foreach ($this->skills() as $skill) {
            foreach ($skill->links() as $link) {
                $links[] = $link;
            }
        }

        foreach ($this->experiences() as $experience) {
            foreach ($experience->links() as $link) {
                $links[] = $link;
            }
        }

        return $links;
    }
}
