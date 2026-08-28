<?php

declare(strict_types=1);

namespace Facet\Config;

use Facet\Support\DotEnv;

/**
 * Minimal configuration loader.
 *
 * Only what this checkpoint needs: read the environment, expose typed
 * accessors, and make sensitive values fail loudly instead of falling back to
 * a guessable default. Anything richer (caching, nested config trees, service
 * wiring) is deliberately deferred.
 */
final class Config
{
    /**
     * Keys that must never resolve to a default value. A missing one is a
     * hard failure rather than a silently insecure boot.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'APP_KEY',
        'DB_DSN',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromEnvironment(?string $basePath = null): self
    {
        $basePath ??= dirname(__DIR__, 2);

        DotEnv::load($basePath . DIRECTORY_SEPARATOR . '.env');

        /** @var array<string, string> $values */
        $values = [];

        foreach ($_ENV as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $values[$name] = (string) $value;
            }
        }

        foreach (self::SENSITIVE_KEYS as $key) {
            $fromProcess = getenv($key);

            if (is_string($fromProcess) && $fromProcess !== '') {
                $values[$key] = $fromProcess;
            }
        }

        return new self($values);
    }

    /**
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if ($this->isSensitive($key)) {
            // Sensitive values are never allowed a caller-supplied fallback.
            return $this->require($key);
        }

        return $this->has($key) ? $this->values[$key] : $default;
    }

    /**
     * @throws MissingConfigurationException when the key is absent or empty
     */
    public function require(string $key): string
    {
        if (!$this->has($key)) {
            throw MissingConfigurationException::forKey($key);
        }

        return $this->values[$key];
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        return in_array(strtolower($this->values[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    public function environment(): string
    {
        return $this->get('APP_ENV', 'production') ?? 'production';
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    /**
     * Debug output must never default to on, whatever the environment.
     */
    public function isDebug(): bool
    {
        return !$this->isProduction() && $this->bool('APP_DEBUG', false);
    }

    public function isSensitive(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    /**
     * @return list<string>
     */
    public static function sensitiveKeys(): array
    {
        return self::SENSITIVE_KEYS;
    }
}
