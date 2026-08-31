<?php

declare(strict_types=1);

namespace Facet\I18n;

/**
 * Reads an `Accept-Language` header well enough to choose between two languages.
 *
 * The header is the only signal this site is allowed to negotiate on — there is
 * no geolocation, no country lookup and no network call anywhere in the locale
 * path — and it is consulted for exactly one decision: which localized URL an
 * *unprefixed* entry route redirects to. An explicit `/fr/...` or `/en/...` URL
 * never reaches this class.
 *
 * The parser is deliberately small. RFC 9110 allows more than is written here —
 * extension parameters, `*`, arbitrary whitespace — and the parts that matter
 * for a two-language site are the language tags and their q-values. Anything
 * else is skipped rather than repaired, and a header that yields nothing
 * supported yields null, which the resolver reads as "the header did not
 * decide" rather than as an error.
 */
final class AcceptLanguage
{
    /** Longer than any header a real client sends; longer ones are not parsed. */
    private const MAX_LENGTH = 512;

    /** More entries than any real client sends; the tail is ignored. */
    private const MAX_ENTRIES = 32;

    /**
     * The most preferred supported locale in the header, or null when the
     * header names none — including when it is absent, empty or malformed.
     */
    public static function preferred(?string $header): ?Locale
    {
        if ($header === null || $header === '' || strlen($header) > self::MAX_LENGTH) {
            return null;
        }

        $best = null;
        $bestQuality = 0.0;
        $bestPosition = PHP_INT_MAX;
        $position = 0;

        foreach (explode(',', $header) as $entry) {
            if ($position >= self::MAX_ENTRIES) {
                break;
            }

            $parsed = self::parseEntry($entry);
            $position++;

            if ($parsed === null) {
                continue;
            }

            [$locale, $quality] = $parsed;

            // A q of 0 is an explicit refusal, not a weak preference.
            if ($quality <= 0.0) {
                continue;
            }

            // Ties are broken by header order, which is what makes
            // "fr,en" deterministic without inventing a preference.
            if ($quality > $bestQuality || ($quality === $bestQuality && $position < $bestPosition)) {
                $best = $locale;
                $bestQuality = $quality;
                $bestPosition = $position;
            }
        }

        return $best;
    }

    /**
     * @return array{0: Locale, 1: float}|null
     */
    private static function parseEntry(string $entry): ?array
    {
        $parts = explode(';', $entry);
        $tag = trim($parts[0]);

        if ($tag === '' || preg_match('/^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*$/', $tag) !== 1) {
            return null;
        }

        $locale = Locale::fromLanguageTag($tag);

        if ($locale === null) {
            return null;
        }

        return [$locale, self::qualityOf(array_slice($parts, 1))];
    }

    /**
     * The q-value of one entry. Absent means 1.0, which is the specification's
     * default; anything that is not a number in [0, 1] is treated as absent
     * rather than as a reason to discard an otherwise valid tag.
     *
     * @param list<string> $parameters
     */
    private static function qualityOf(array $parameters): float
    {
        foreach ($parameters as $parameter) {
            $pair = explode('=', $parameter, 2);

            if (count($pair) !== 2 || strtolower(trim($pair[0])) !== 'q') {
                continue;
            }

            $raw = trim($pair[1]);

            if (preg_match('/^(0(\.\d{1,3})?|1(\.0{1,3})?)$/', $raw) !== 1) {
                return 1.0;
            }

            return (float) $raw;
        }

        return 1.0;
    }
}
