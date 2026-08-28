<?php

declare(strict_types=1);

namespace Facet\Database\Migration;

/**
 * Splits a migration file into the statements it contains.
 *
 * A naive `explode(';')` breaks the moment a semicolon appears inside a string
 * literal or a comment — which it does, in CHECK constraints and in the
 * explanatory headers these migrations carry. This walks the text instead,
 * tracking whether it is inside a quoted literal, a quoted identifier, or a
 * comment, and only treats a semicolon as a terminator when it is in none of
 * them.
 *
 * Comments are dropped rather than forwarded: they are documentation for the
 * reader, and keeping them out of the statement keeps the error text that
 * {@see \Facet\Database\DatabaseException::queryFailed()} produces readable.
 */
final class SqlStatements
{
    /**
     * @return list<string> non-empty statements, in file order
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // -- line comment, and MySQL's `#` variant.
            if (($char === '-' && $next === '-') || $char === '#') {
                $i = self::skipToEndOfLine($sql, $i, $length);
                continue;
            }

            // /* block comment */
            if ($char === '/' && $next === '*') {
                $i = self::skipBlockComment($sql, $i, $length);
                continue;
            }

            // Quoted literal or quoted identifier: copied through verbatim.
            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::endOfQuoted($sql, $i, $length, $char);
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === ';') {
                $statements = self::append($statements, $current);
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        // A trailing statement without its terminating semicolon still counts.
        return self::append($statements, $current);
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private static function append(array $statements, string $candidate): array
    {
        $trimmed = trim($candidate);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private static function skipToEndOfLine(string $sql, int $i, int $length): int
    {
        $newline = strpos($sql, "\n", $i);

        return $newline === false ? $length : $newline + 1;
    }

    private static function skipBlockComment(string $sql, int $i, int $length): int
    {
        $end = strpos($sql, '*/', $i + 2);

        return $end === false ? $length : $end + 2;
    }

    /**
     * @return int the index just past the closing quote
     */
    private static function endOfQuoted(string $sql, int $i, int $length, string $quote): int
    {
        $j = $i + 1;

        while ($j < $length) {
            $char = $sql[$j];

            // Backslash escape (MySQL's default) skips the next character.
            if ($char === '\\' && $quote !== '`') {
                $j += 2;
                continue;
            }

            if ($char === $quote) {
                // A doubled quote is an escaped quote, not a terminator.
                if ($j + 1 < $length && $sql[$j + 1] === $quote) {
                    $j += 2;
                    continue;
                }

                return $j + 1;
            }

            $j++;
        }

        return $length;
    }
}
