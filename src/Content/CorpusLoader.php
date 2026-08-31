<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Content\Exception\InvalidContentException;
use Facet\I18n\Locale;
use Facet\Support\InvalidSlugException;
use Facet\Support\Slug;

/**
 * Reads the canonical corpus from versioned JSON files.
 *
 * Content lives in `content/` as plain, git-tracked JSON — outside any
 * database and outside any skin — so copy and media edits are a content commit,
 * never a backend change. Every file declares the schema version it was written
 * against, and every value is validated on the way in: an invalid corpus fails
 * loudly at load rather than producing a half-rendered page.
 */
final class CorpusLoader
{
    public const DEFAULT_DIRECTORY = 'content';

    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    public static function default(?string $basePath = null): self
    {
        $basePath ??= dirname(__DIR__, 2);

        return new self($basePath . DIRECTORY_SEPARATOR . self::DEFAULT_DIRECTORY);
    }

    /**
     * The corpus in one language.
     *
     * Facts are read from `content/*.json` whatever the locale; prose is read
     * through a {@see TranslationOverlay}, which is the identity overlay for
     * the language the corpus is authored in. There is therefore exactly one
     * loading path and one set of validations, and a locale cannot acquire a
     * different set of projects, dates or technologies than another.
     *
     * @throws InvalidContentException when any source is missing or malformed
     */
    public function load(?Locale $locale = null): Corpus
    {
        $overlay = TranslationOverlay::load($this->directory, $locale ?? Locale::default());

        return Corpus::create(
            $this->loadProfile($overlay),
            $this->loadProjects($overlay),
            $this->loadSkills($overlay),
            $this->loadExperiences($overlay)
        );
    }

    private function loadProfile(TranslationOverlay $overlay): Profile
    {
        $source = 'profile.json';
        $data = $this->readDocument($source);
        $profile = self::requireArray($data, 'profile', $source);

        return Profile::create(
            self::requireString($profile, 'name', $source),
            $overlay->text(['profile', 'headline'], self::requireString($profile, 'headline', $source)),
            self::requireString($profile, 'location', $source),
            $overlay->text(['profile', 'summary'], self::requireString($profile, 'summary', $source)),
            $overlay->list(['profile', 'focusAreas'], self::requireStringList($profile, 'focusAreas', $source)),
            self::readLinks($profile, $source, $overlay, ['profile', 'links']),
            self::readMedia($profile, 'portrait', $source, $overlay, ['profile', 'portrait', 'description'])
        );
    }

    /**
     * @return list<Project>
     */
    private function loadProjects(TranslationOverlay $overlay): array
    {
        $source = 'projects.json';
        $data = $this->readDocument($source);
        $rows = self::requireArray($data, 'projects', $source);

        $projects = [];

        foreach (array_values($rows) as $index => $row) {
            $label = $source . ' #' . $index;

            if (!is_array($row)) {
                throw InvalidContentException::wrongType($label, 'projects', 'an object');
            }

            /** @var array<string, mixed> $row */
            $slug = self::readSlug($row, $label);
            $entry = ['projects', $slug->value()];

            $projects[] = Project::create(
                $slug,
                self::requireString($row, 'name', $label),
                $overlay->text([...$entry, 'summary'], self::requireString($row, 'summary', $label)),
                $overlay->text([...$entry, 'context'], self::requireString($row, 'context', $label)),
                $overlay->text([...$entry, 'role'], self::requireString($row, 'role', $label)),
                self::requireStringList($row, 'technologies', $label),
                $overlay->list([...$entry, 'concepts'], self::requireStringList($row, 'concepts', $label)),
                self::readEnum($row, 'status', $label, ProjectStatus::class),
                $overlay->list([...$entry, 'outcomes'], self::requireStringList($row, 'outcomes', $label)),
                self::readOptionalPeriod($row, $label),
                self::readLinks($row, $label, $overlay, [...$entry, 'links']),
                self::readMedia($row, 'media', $label, $overlay, [...$entry, 'media', 'description']),
                self::requireBool($row, 'featured', $label)
            );
        }

        return $projects;
    }

    /**
     * @return list<Skill>
     */
    private function loadSkills(TranslationOverlay $overlay): array
    {
        $source = 'skills.json';
        $data = $this->readDocument($source);
        $rows = self::requireArray($data, 'skills', $source);

        $skills = [];

        foreach (array_values($rows) as $index => $row) {
            $label = $source . ' #' . $index;

            if (!is_array($row)) {
                throw InvalidContentException::wrongType($label, 'skills', 'an object');
            }

            /** @var array<string, mixed> $row */
            $slug = self::readSlug($row, $label);
            $entry = ['skills', $slug->value()];

            $skills[] = Skill::create(
                $slug,
                self::requireString($row, 'name', $label),
                self::readEnum($row, 'category', $label, SkillCategory::class),
                $overlay->text([...$entry, 'summary'], self::requireString($row, 'summary', $label)),
                self::readLinks($row, $label, $overlay, [...$entry, 'links'])
            );
        }

        return $skills;
    }

    /**
     * @return list<Experience>
     */
    private function loadExperiences(TranslationOverlay $overlay): array
    {
        $source = 'experiences.json';
        $data = $this->readDocument($source);
        $rows = self::requireArray($data, 'experiences', $source);

        $experiences = [];

        foreach (array_values($rows) as $index => $row) {
            $label = $source . ' #' . $index;

            if (!is_array($row)) {
                throw InvalidContentException::wrongType($label, 'experiences', 'an object');
            }

            /** @var array<string, mixed> $row */
            $slug = self::readSlug($row, $label);
            $entry = ['experiences', $slug->value()];

            $experiences[] = Experience::create(
                $slug,
                self::readEnum($row, 'kind', $label, ExperienceKind::class),
                self::requireString($row, 'title', $label),
                self::requireString($row, 'organisation', $label),
                self::requireString($row, 'location', $label),
                self::readPeriod($row, $label),
                $overlay->text([...$entry, 'summary'], self::requireString($row, 'summary', $label)),
                $overlay->list([...$entry, 'highlights'], self::requireStringList($row, 'highlights', $label)),
                self::readLinks($row, $label, $overlay, [...$entry, 'links'])
            );
        }

        return $experiences;
    }

    /**
     * Reads a content file and validates its schema declaration.
     *
     * @return array<string, mixed>
     */
    private function readDocument(string $filename): array
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;

        if (!is_readable($path)) {
            throw InvalidContentException::unreadable($path);
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw InvalidContentException::unreadable($path);
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw InvalidContentException::notJson($filename);
        }

        /** @var array<string, mixed> $decoded */
        ContentSchema::assertSupported($decoded['schemaVersion'] ?? null, $filename);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function requireArray(array $data, string $key, string $source): array
    {
        if (!array_key_exists($key, $data)) {
            throw InvalidContentException::missingKey($source, $key);
        }

        if (!is_array($data[$key])) {
            throw InvalidContentException::wrongType($source, $key, 'an array');
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $key, string $source): string
    {
        if (!array_key_exists($key, $data)) {
            throw InvalidContentException::missingKey($source, $key);
        }

        if (!is_string($data[$key]) || trim($data[$key]) === '') {
            throw InvalidContentException::wrongType($source, $key, 'a non-empty string');
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireBool(array $data, string $key, string $source): bool
    {
        if (!array_key_exists($key, $data)) {
            throw InvalidContentException::missingKey($source, $key);
        }

        if (!is_bool($data[$key])) {
            throw InvalidContentException::wrongType($source, $key, 'a boolean');
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function requireStringList(array $data, string $key, string $source): array
    {
        $raw = self::requireArray($data, $key, $source);
        $values = [];

        foreach ($raw as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw InvalidContentException::wrongType($source, $key, 'a list of non-empty strings');
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readSlug(array $data, string $source): Slug
    {
        $candidate = self::requireString($data, 'slug', $source);

        try {
            return Slug::fromString($candidate);
        } catch (InvalidSlugException $exception) {
            throw InvalidContentException::because($source, $exception->getMessage());
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $enum
     *
     * @return T
     */
    private static function readEnum(array $data, string $key, string $source, string $enum): \BackedEnum
    {
        $value = self::requireString($data, $key, $source);
        $case = $enum::tryFrom($value);

        if ($case === null) {
            $allowed = array_map(
                static fn (\BackedEnum $c): string => (string) $c->value,
                $enum::cases()
            );

            throw InvalidContentException::because(
                $source,
                sprintf('"%s" is not a valid %s (expected one of: %s)', $value, $key, implode(', ', $allowed))
            );
        }

        return $case;
    }

    /**
     * A project may legitimately have no substantiated period.
     *
     * @param array<string, mixed> $data
     */
    private static function readOptionalPeriod(array $data, string $source): ?Period
    {
        if (!array_key_exists('period', $data) || $data['period'] === null) {
            return null;
        }

        return self::readPeriod($data, $source);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readPeriod(array $data, string $source): Period
    {
        $period = self::requireArray($data, 'period', $source);

        /** @var array<string, mixed> $period */
        $end = $period['end'] ?? null;

        if ($end !== null && !is_string($end)) {
            throw InvalidContentException::wrongType($source, 'period.end', 'a string or null');
        }

        return Period::create(self::requireString($period, 'start', $source), $end);
    }

    /**
     * Media is optional everywhere. A missing block yields a fallback carrying
     * the entry's own textual description, so nothing renders empty.
     *
     * @param array<string, mixed> $data
     * @param list<string|int>     $descriptionPath
     */
    private static function readMedia(
        array $data,
        string $key,
        string $source,
        TranslationOverlay $overlay,
        array $descriptionPath
    ): Media {
        if (!array_key_exists($key, $data)) {
            throw InvalidContentException::missingKey($source, $key);
        }

        if (!is_array($data[$key])) {
            throw InvalidContentException::wrongType($source, $key, 'an object');
        }

        /** @var array<string, mixed> $media */
        $media = $data[$key];
        $sourceValue = $media['source'] ?? null;

        if ($sourceValue !== null && !is_string($sourceValue)) {
            throw InvalidContentException::wrongType($source, $key . '.source', 'a string or null');
        }

        return Media::create(
            $sourceValue,
            $overlay->text($descriptionPath, self::requireString($media, 'description', $source))
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string|int>     $labelsPath
     *
     * @return list<Link>
     */
    private static function readLinks(
        array $data,
        string $source,
        TranslationOverlay $overlay,
        array $labelsPath
    ): array {
        $raw = self::requireArray($data, 'links', $source);
        $links = [];
        $canonicalLabels = [];
        $rows = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw InvalidContentException::wrongType($source, 'links', 'a list of objects');
            }

            /** @var array<string, mixed> $row */
            $rows[] = $row;
            $canonicalLabels[] = self::requireString($row, 'label', $source);
        }

        // A link's URL and type are facts and are never translated; only the
        // label a reader sees is. The labels are matched positionally against
        // the canonical list, and a count mismatch is refused by the overlay —
        // so a translation can rename a link but can never add or drop one.
        $labels = $canonicalLabels === []
            ? []
            : $overlay->list($labelsPath, $canonicalLabels);

        foreach ($rows as $index => $row) {
            $links[] = Link::create(
                $labels[$index],
                self::requireString($row, 'url', $source),
                self::readEnum($row, 'type', $source, LinkType::class)
            );
        }

        return $links;
    }
}
