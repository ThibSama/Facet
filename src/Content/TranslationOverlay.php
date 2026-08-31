<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Content\Exception\InvalidContentException;
use Facet\I18n\Locale;

/**
 * The translated text of the canonical corpus, and nothing else.
 *
 * The corpus in `content/*.json` stays the single source of every *fact*: a
 * project's slug, its name, its technologies, its dates, its links, its status;
 * a skill's identity and category; an experience's title, organisation,
 * location and period. None of those appear in a translation file, so they
 * cannot drift, cannot be edited in one language only, and cannot become two
 * answers to the same question.
 *
 * What a translation file carries is exactly the prose: summaries, contexts,
 * role descriptions, concept and outcome phrasings, highlights, media
 * descriptions and link labels. Each entry is addressed by the canonical slug
 * it translates, so a translation is an overlay on a record rather than a
 * second copy of one.
 *
 * The overlay is **total**. Every entry the corpus declares must be present,
 * every localizable field of it must be present, and every list must have
 * exactly as many items as the canonical list it translates. A partial file is
 * a load-time failure with the missing path named, which is what keeps "the
 * English site is complete" a property the build checks rather than a claim
 * somebody made once. The canonical locale has no file at all: French *is* the
 * corpus.
 */
final class TranslationOverlay
{
    public const DIRECTORY = 'translations';

    /** @var array<string, mixed> */
    private array $document;

    private bool $canonical;

    private string $source;

    /**
     * @param array<string, mixed> $document
     */
    private function __construct(array $document, bool $canonical, string $source)
    {
        $this->document = $document;
        $this->canonical = $canonical;
        $this->source = $source;
    }

    /**
     * The identity overlay: the corpus as written. Used for French, which is
     * the language the corpus is authored in.
     */
    public static function canonical(): self
    {
        return new self([], true, 'content');
    }

    /**
     * Reads the overlay for a locale, or the identity overlay when that locale
     * is the one the corpus is written in.
     *
     * @throws InvalidContentException when the file is missing or malformed
     */
    public static function load(string $directory, Locale $locale): self
    {
        if ($locale === Locale::default()) {
            return self::canonical();
        }

        $filename = self::DIRECTORY . DIRECTORY_SEPARATOR . $locale->value . '.json';
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

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

        if (($decoded['locale'] ?? null) !== $locale->value) {
            throw InvalidContentException::because(
                $filename,
                sprintf('the document declares no "locale" of "%s"', $locale->value)
            );
        }

        return new self($decoded, false, $filename);
    }

    public function isCanonical(): bool
    {
        return $this->canonical;
    }

    /**
     * A translated string for one path in the overlay, or the canonical text
     * when this overlay is the canonical one.
     *
     * @param list<string|int> $path
     */
    public function text(array $path, string $canonicalText): string
    {
        if ($this->canonical) {
            return $canonicalText;
        }

        $value = $this->at($path);

        if (!is_string($value) || trim($value) === '') {
            throw InvalidContentException::wrongType($this->source, self::describe($path), 'a non-empty string');
        }

        return $value;
    }

    /**
     * A translated list, which must have exactly the canonical list's length.
     *
     * The length check is what stops a translation from adding a concept, an
     * outcome or a highlight the corpus does not state — the one failure mode
     * that would turn a translation into a second source of truth.
     *
     * @param list<string|int> $path
     * @param list<string>     $canonicalItems
     *
     * @return list<string>
     */
    public function list(array $path, array $canonicalItems): array
    {
        if ($this->canonical) {
            return $canonicalItems;
        }

        $value = $this->at($path);

        if (!is_array($value)) {
            throw InvalidContentException::wrongType($this->source, self::describe($path), 'an array');
        }

        $items = array_values($value);

        if (count($items) !== count($canonicalItems)) {
            throw InvalidContentException::because($this->source, sprintf(
                '%s translates %d item(s) but the corpus declares %d',
                self::describe($path),
                count($items),
                count($canonicalItems)
            ));
        }

        $translated = [];

        foreach ($items as $index => $item) {
            if (!is_string($item) || trim($item) === '') {
                throw InvalidContentException::wrongType(
                    $this->source,
                    self::describe([...$path, $index]),
                    'a non-empty string'
                );
            }

            $translated[] = $item;
        }

        return $translated;
    }

    /**
     * @param list<string|int> $path
     */
    private function at(array $path): mixed
    {
        $cursor = $this->document;

        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                throw InvalidContentException::missingKey($this->source, self::describe($path));
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param list<string|int> $path
     */
    private static function describe(array $path): string
    {
        return implode('.', array_map(static fn (string|int $s): string => (string) $s, $path));
    }
}
