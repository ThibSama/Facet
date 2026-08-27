<?php

declare(strict_types=1);

namespace Facet\Asset;

use Facet\Support\ViteManifest;
use InvalidArgumentException;

/**
 * URLs and intrinsic metadata needed to render one responsive local image.
 *
 * The value contains no markup, so each skin remains responsible for its own
 * picture/img structure and escaping while sharing one deterministic contract.
 */
final class ResponsiveImage
{
    private string $source;
    private int $width;
    private int $height;
    private string $description;

    /** @var list<array{type: string, source: string}> */
    private array $modernSources;

    /** @param list<array{type: string, source: string}> $modernSources */
    private function __construct(
        string $source,
        int $width,
        int $height,
        string $description,
        array $modernSources
    ) {
        $this->source = $source;
        $this->width = $width;
        $this->height = $height;
        $this->description = $description;
        $this->modernSources = $modernSources;
    }

    /** @param array<string, string> $modernEntries MIME type => manifest key */
    public static function fromManifest(
        ViteManifest $manifest,
        string $fallbackEntry,
        int $width,
        int $height,
        string $description,
        array $modernEntries = []
    ): self {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image width and height must be positive intrinsic dimensions.');
        }

        $sources = [];

        foreach ($modernEntries as $type => $entry) {
            if (!in_array($type, ['image/avif', 'image/webp'], true)) {
                throw new InvalidArgumentException(sprintf('Unsupported modern image type "%s".', $type));
            }

            $sources[] = ['type' => $type, 'source' => $manifest->asset($entry)];
        }

        return new self(
            $manifest->asset($fallbackEntry),
            $width,
            $height,
            $description,
            $sources
        );
    }

    public function source(): string
    {
        return $this->source;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return list<array{type: string, source: string}> */
    public function modernSources(): array
    {
        return $this->modernSources;
    }
}
