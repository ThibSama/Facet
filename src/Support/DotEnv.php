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
 */
final class DotEnv
{
    /**
     * @return array<string, string> the variables sourced from the file
     */
    public static function load(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $loaded = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $value = self::unquote(trim($value));

            // Never let the file override a real environment variable.
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
