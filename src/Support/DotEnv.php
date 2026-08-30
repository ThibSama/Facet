<?php

declare(strict_types=1);

namespace Facet\Support;

/**
 * Dependency-free `.env` reader.
 *
 * Facet keeps its production runtime free of third-party packages for
 * configuration: real deployments are expected to export real environment
 * variables, and the `.env` file is a local-development convenience only.
 * Values already present in the environment always win over the file.
 *
 * That last rule is also what makes layered files deterministic without any
 * merge step: loading the higher-precedence file first leaves its values in
 * `$_ENV`, and the lower-precedence file then skips every name it already
 * defined. See {@see \Facet\Config\Config::fromEnvironment()}.
 */
final class DotEnv
{
    /**
     * Parse a file without touching the environment.
     *
     * Used to answer a question *about* a file — which environment `.env`
     * names, say — at a point where applying its values would be premature.
     *
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $values[$name] = self::unquote(trim($value));
        }

        return $values;
    }

    /**
     * @param list<string> $ignoredNames names this file may not define,
     *                                   whatever it happens to contain
     *
     * @return array<string, string> the variables sourced from the file
     */
    public static function load(string $path, array $ignoredNames = []): array
    {
        $loaded = [];

        foreach (self::read($path) as $name => $value) {
            if (in_array($name, $ignoredNames, true)) {
                continue;
            }

            // Never let the file override a real environment variable, nor a
            // value a higher-precedence file has already placed in $_ENV.
            if (getenv($name) !== false || array_key_exists($name, $_ENV)) {
                continue;
            }

            $_ENV[$name] = $value;
            $loaded[$name] = $value;
        }

        return $loaded;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        // Strip a trailing inline comment on unquoted values.
        $commentPosition = strpos($value, ' #');

        return $commentPosition === false ? $value : rtrim(substr($value, 0, $commentPosition));
    }
}
