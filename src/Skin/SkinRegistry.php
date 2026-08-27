<?php

declare(strict_types=1);

namespace Facet\Skin;

use InvalidArgumentException;

/**
 * The canonical list of skins the application can render with.
 *
 * The MVP registry holds exactly one real skin. The point of the registry is
 * not plurality today but the boundary: adding a second skin later is a change
 * to this file alone, not to routing, content or the shared runtime.
 */
final class SkinRegistry
{
    /** Bumped when the skin contract changes in a way consumers must react to. */
    public const VERSION = '1.0.0';

    public const EVOLVING_INTERFACE = 'evolving-interface';

    public const DEFAULT_SKIN = self::EVOLVING_INTERFACE;

    /** @var array<string, SkinDefinition> */
    private array $skins;

    private string $defaultId;

    private static ?self $default = null;

    /**
     * @param array<string, SkinDefinition> $skins
     */
    private function __construct(array $skins, string $defaultId)
    {
        $this->skins = $skins;
        $this->defaultId = $defaultId;
    }

    /**
     * @param list<SkinDefinition> $skins
     *
     * @throws InvalidArgumentException when the set is empty, duplicated, or the default is absent
     */
    public static function create(array $skins, string $defaultId): self
    {
        if ($skins === []) {
            throw new InvalidArgumentException('A skin registry must declare at least one skin.');
        }

        $indexed = [];

        foreach ($skins as $skin) {
            if (isset($indexed[$skin->id()])) {
                throw new InvalidArgumentException(sprintf('Duplicate skin id "%s" in the registry.', $skin->id()));
            }

            $indexed[$skin->id()] = $skin;
        }

        if (!isset($indexed[$defaultId])) {
            throw new InvalidArgumentException(sprintf(
                'Default skin "%s" is not registered. Registered skins: %s.',
                $defaultId,
                implode(', ', array_keys($indexed))
            ));
        }

        return new self($indexed, $defaultId);
    }

    /**
     * The registry the application boots with.
     */
    public static function default(): self
    {
        return self::$default ??= self::create(self::build(), self::DEFAULT_SKIN);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->skins);
    }

    /**
     * @return list<SkinDefinition>
     */
    public function all(): array
    {
        return array_values($this->skins);
    }

    public function count(): int
    {
        return count($this->skins);
    }

    public function has(string $id): bool
    {
        return isset($this->skins[$id]);
    }

    public function defaultId(): string
    {
        return $this->defaultId;
    }

    public function defaultSkin(): SkinDefinition
    {
        return $this->skins[$this->defaultId];
    }

    /**
     * Strict lookup: an unknown id is an error, never a silent default.
     *
     * @throws UnknownSkinException
     */
    public function get(string $id): SkinDefinition
    {
        if (!isset($this->skins[$id])) {
            throw UnknownSkinException::forId($id, $this->ids());
        }

        return $this->skins[$id];
    }

    /**
     * Tolerant lookup: an unknown or absent id yields null, so callers that
     * intend a fallback have to write it explicitly.
     */
    public function find(?string $id): ?SkinDefinition
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->skins[$id] ?? null;
    }

    /**
     * The one place an unknown id is allowed to degrade, and it degrades to a
     * single deterministic answer: the registered default.
     */
    public function findOrDefault(?string $id): SkinDefinition
    {
        return $this->find($id) ?? $this->defaultSkin();
    }

    /**
     * @return list<SkinDefinition>
     */
    private static function build(): array
    {
        return [
            SkinDefinition::define(
                self::EVOLVING_INTERFACE,
                self::EVOLVING_INTERFACE,
                'resources/skins/' . self::EVOLVING_INTERFACE . '/views',
                ['resources/skins/' . self::EVOLVING_INTERFACE . '/skin.ts'],
                [
                    SkinCapability::ServerRenderedViews,
                    SkinCapability::ProgressiveEnhancement,
                    SkinCapability::IsolatedStylesheet,
                ]
            ),
        ];
    }
}
