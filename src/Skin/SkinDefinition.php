<?php

declare(strict_types=1);

namespace Facet\Skin;

use InvalidArgumentException;

/**
 * One skin, declared as immutable data.
 *
 * A skin knows four things and nothing else: a stable id, where its logical
 * views live (namespace + directory), which build entrypoints belong to it, and
 * what it is capable of. It knows nothing about routes, content or HTTP — the
 * shared runtime asks it to resolve a logical view identifier, and the skin
 * answers with a path it owns.
 */
final class SkinDefinition
{
    /**
     * A skin id is part of the public contract (it can appear in a dev query
     * string and in build entrypoint names), so it is deliberately narrow.
     */
    private const ID_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /** Logical views are dot-separated lowercase segments, e.g. "page.projects.show". */
    private const VIEW_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/';

    private string $id;

    private string $viewNamespace;

    private string $viewDirectory;

    /** @var non-empty-list<string> */
    private array $assetEntrypoints;

    /** @var list<SkinCapability> */
    private array $capabilities;

    /**
     * @param non-empty-list<string> $assetEntrypoints
     * @param list<SkinCapability>   $capabilities
     */
    private function __construct(
        string $id,
        string $viewNamespace,
        string $viewDirectory,
        array $assetEntrypoints,
        array $capabilities
    ) {
        $this->id = $id;
        $this->viewNamespace = $viewNamespace;
        $this->viewDirectory = $viewDirectory;
        $this->assetEntrypoints = $assetEntrypoints;
        $this->capabilities = $capabilities;
    }

    /**
     * @param list<string>         $assetEntrypoints manifest keys, e.g. "<skins-dir>/x/skin.ts"
     * @param list<SkinCapability> $capabilities
     *
     * @throws InvalidArgumentException when the declaration is internally inconsistent
     */
    public static function define(
        string $id,
        string $viewNamespace,
        string $viewDirectory,
        array $assetEntrypoints,
        array $capabilities = []
    ): self {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Skin id "%s" must be lowercase kebab-case, e.g. "my-skin".',
                $id
            ));
        }

        if (preg_match(self::ID_PATTERN, $viewNamespace) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Skin "%s" view namespace "%s" must be lowercase kebab-case.',
                $id,
                $viewNamespace
            ));
        }

        $viewDirectory = rtrim(str_replace('\\', '/', $viewDirectory), '/');

        if ($viewDirectory === '' || str_starts_with($viewDirectory, '/') || str_contains($viewDirectory, '..')) {
            throw new InvalidArgumentException(sprintf(
                'Skin "%s" must declare a relative view directory without traversal, got "%s".',
                $id,
                $viewDirectory
            ));
        }

        if ($assetEntrypoints === []) {
            throw new InvalidArgumentException(sprintf('Skin "%s" must declare at least one asset entrypoint.', $id));
        }

        foreach ($assetEntrypoints as $entrypoint) {
            if ($entrypoint === '' || str_contains($entrypoint, '..')) {
                throw new InvalidArgumentException(sprintf(
                    'Skin "%s" declares an invalid asset entrypoint "%s".',
                    $id,
                    $entrypoint
                ));
            }
        }

        $unique = [];
        foreach ($capabilities as $capability) {
            $unique[$capability->value] = $capability;
        }

        return new self($id, $viewNamespace, $viewDirectory, $assetEntrypoints, array_values($unique));
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * The namespace shared code may use to address this skin's views without
     * knowing its directory layout.
     */
    public function viewNamespace(): string
    {
        return $this->viewNamespace;
    }

    /** Project-root-relative directory holding this skin's templates. */
    public function viewDirectory(): string
    {
        return $this->viewDirectory;
    }

    /**
     * @return non-empty-list<string>
     */
    public function assetEntrypoints(): array
    {
        return $this->assetEntrypoints;
    }

    /**
     * @return list<SkinCapability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function supports(SkinCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function is(string $id): bool
    {
        return $this->id === $id;
    }

    /**
     * Translates a logical view identifier into a project-relative template
     * path owned by this skin. The pattern check is what keeps a caller-shaped
     * identifier from escaping the skin directory.
     *
     * @throws UnknownViewException when the identifier is malformed
     */
    public function viewPath(string $view): string
    {
        if (preg_match(self::VIEW_PATTERN, $view) !== 1) {
            throw UnknownViewException::malformed($view);
        }

        return $this->viewDirectory . '/' . str_replace('.', '/', $view) . '.php';
    }
}
