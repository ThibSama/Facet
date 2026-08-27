<?php

declare(strict_types=1);

namespace Facet\Support;

use RuntimeException;

/**
 * Resolves Vite's build manifest so PHP can emit hashed asset URLs.
 *
 * This is the reason the production runtime never needs Node: the build
 * artefacts under public/build are plain static files plus a JSON manifest.
 */
final class ViteManifest
{
    /** @var array<string, array<string, mixed>> */
    private array $entries;

    private string $basePath;

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function __construct(array $entries, string $basePath)
    {
        $this->entries = $entries;
        $this->basePath = $basePath;
    }

    public static function fromFile(string $path, string $basePath = '/build/'): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf(
                'Vite manifest not found at "%s". Run `npm run build` first.',
                $path
            ));
        }

        $raw = file_get_contents($path);
        $decoded = $raw === false ? null : json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Vite manifest at "%s" is not valid JSON.', $path));
        }

        $entries = [];

        foreach ($decoded as $entry => $metadata) {
            if (!is_string($entry) || !is_array($metadata)) {
                throw new RuntimeException(sprintf('Vite manifest at "%s" has an invalid entry.', $path));
            }

            $file = $metadata['file'] ?? null;
            $css = $metadata['css'] ?? [];

            if (!is_string($file) || $file === '' || !is_array($css)) {
                throw new RuntimeException(sprintf('Vite manifest entry "%s" is invalid.', $entry));
            }

            foreach ($css as $stylesheet) {
                if (!is_string($stylesheet) || $stylesheet === '') {
                    throw new RuntimeException(sprintf('Vite manifest entry "%s" has invalid CSS.', $entry));
                }
            }

            /** @var array<string, mixed> $metadata */
            $entries[$entry] = $metadata;
        }

        return new self($entries, $basePath);
    }

    public function has(string $entry): bool
    {
        return isset($this->entries[$entry]);
    }

    public function script(string $entry): string
    {
        return $this->asset($entry);
    }

    /** Resolves any manifest-addressable local asset to its fingerprinted URL. */
    public function asset(string $entry): string
    {
        $file = $this->entries[$entry]['file'] ?? null;

        if (!is_string($file)) {
            throw new RuntimeException(sprintf('No built file for Vite entry "%s".', $entry));
        }

        return $this->basePath . $file;
    }

    /**
     * @return list<string>
     */
    public function styles(string $entry): array
    {
        $css = $this->entries[$entry]['css'] ?? [];

        if (!is_array($css)) {
            return [];
        }

        $urls = [];

        foreach ($css as $file) {
            if (is_string($file)) {
                $urls[] = $this->basePath . $file;
            }
        }

        return $urls;
    }
}
