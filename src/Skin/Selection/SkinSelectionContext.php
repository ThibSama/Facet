<?php

declare(strict_types=1);

namespace Facet\Skin\Selection;

use Facet\Config\Config;

/**
 * Everything a selection policy is allowed to know about a request.
 *
 * The environment gate lives here rather than in a policy on purpose: an
 * override that production must never honour is dropped while the context is
 * built, so no policy — present or future, careful or not — can reach a value
 * production never captured.
 */
final class SkinSelectionContext
{
    /** Query parameter a developer may use to preview a specific skin. */
    public const QUERY_PARAMETER = 'skin';

    /** Transport for carrying an explicit choice across navigation. */
    public const PERSISTENCE_KEY = 'facet_skin';

    private ?string $requestedSkinId;

    private ?string $persistedSkinId;

    private string $environment;

    private bool $overridesAllowed;

    private function __construct(
        ?string $requestedSkinId,
        ?string $persistedSkinId,
        string $environment,
        bool $overridesAllowed
    ) {
        $this->requestedSkinId = $requestedSkinId;
        $this->persistedSkinId = $persistedSkinId;
        $this->environment = $environment;
        $this->overridesAllowed = $overridesAllowed;
    }

    /**
     * Builds the context from raw request input.
     *
     * @param array<array-key, mixed> $query   typically $_GET
     * @param array<array-key, mixed> $cookies typically $_COOKIE
     */
    public static function fromRequest(array $query, array $cookies, Config $config): self
    {
        // Config::environment() is the single source of truth for how safe the
        // request is; production is closed, everything else is open.
        $overridesAllowed = !$config->isProduction();

        return new self(
            $overridesAllowed ? self::stringValue($query, self::QUERY_PARAMETER) : null,
            $overridesAllowed ? self::stringValue($cookies, self::PERSISTENCE_KEY) : null,
            $config->environment(),
            $overridesAllowed
        );
    }

    /**
     * A context that never carries an override, whatever it is handed.
     */
    public static function locked(string $environment = 'production'): self
    {
        return new self(null, null, $environment, false);
    }

    public static function development(?string $requestedSkinId = null, ?string $persistedSkinId = null): self
    {
        return new self($requestedSkinId, $persistedSkinId, 'local', true);
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * False in production: no request input may steer skin selection there.
     */
    public function allowsOverride(): bool
    {
        return $this->overridesAllowed;
    }

    /**
     * The skin explicitly asked for, or null — including whenever the
     * environment forbids overrides, where the value is never captured.
     */
    public function requestedSkinId(): ?string
    {
        return $this->overridesAllowed ? $this->requestedSkinId : null;
    }

    public function persistedSkinId(): ?string
    {
        return $this->overridesAllowed ? $this->persistedSkinId : null;
    }

    public function hasRequest(): bool
    {
        return $this->requestedSkinId() !== null;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
