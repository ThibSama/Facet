<?php

declare(strict_types=1);

namespace Facet\Html;

use Stringable;

/**
 * Context-aware escaping primitives.
 *
 * Three contexts, because one escape function is not correct in all of them:
 * element text, attribute values and URL attributes have different unsafe
 * shapes. Everything is UTF-8 and substitutes invalid sequences rather than
 * returning an empty string, so malformed input degrades to visible mojibake
 * instead of silently erasing a page section.
 */
final class Escaper
{
    private const FLAGS = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5;

    /** Schemes a link may use. Everything else — javascript:, data: — is dropped. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Emitted in place of a rejected URL so a bad link is inert, not broken markup. */
    public const BLOCKED_URL = 'about:blank';

    /**
     * Escapes for element text content.
     */
    public static function text(string|int|float|Stringable|null $value): string
    {
        return htmlspecialchars(self::stringify($value), self::FLAGS, 'UTF-8');
    }

    /**
     * Escapes for a quoted attribute value.
     */
    public static function attribute(string|int|float|Stringable|null $value): string
    {
        return htmlspecialchars(self::stringify($value), self::FLAGS, 'UTF-8');
    }

    /**
     * Escapes a URL for an href/src attribute, rejecting any scheme that can
     * execute. A relative path or fragment is always allowed.
     */
    public static function url(string|int|float|Stringable|null $value): string
    {
        $url = trim(self::stringify($value));

        if ($url === '') {
            return self::BLOCKED_URL;
        }

        // Control characters are how "java\tscript:" sneaks past a naive check.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return self::BLOCKED_URL;
        }

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*):#', $url, $matches) === 1) {
            if (!in_array(strtolower($matches[1]), self::SAFE_SCHEMES, true)) {
                return self::BLOCKED_URL;
            }
        } elseif (str_starts_with($url, '//')) {
            // Protocol-relative: inherits the page scheme, so it is fine, but
            // it must not be confused with a path.
            $url = 'https:' . $url;
        }

        return htmlspecialchars($url, self::FLAGS, 'UTF-8');
    }

    private static function stringify(string|int|float|Stringable|null $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
