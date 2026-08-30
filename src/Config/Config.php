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
    public const LOCAL_OVERRIDE_FILE = '.env.local';

    /**
     * Prefix reserved for the test suite's own credentials. `.env.testing`
     * names the disposable `facet_test` schema, and the application must never
     * be able to reach it: these names are dropped on the way in, so no boot
     * can resolve one even when the suite has already loaded the file into
     * `$_ENV` in the same process.
     */
    private const TEST_ONLY_PREFIX = 'FACET_TEST_';

    private const ENVIRONMENT_KEY = 'APP_ENV';

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

        $base = $basePath . DIRECTORY_SEPARATOR . '.env';
        $override = $basePath . DIRECTORY_SEPARATOR . self::LOCAL_OVERRIDE_FILE;

        // Which environment we are in must not itself be decidable by the
        // machine-local override: otherwise "is this production?" would depend
        // on the very file production is not allowed to read. It is answered by
        // the process environment, then `.env`, and nothing else.
        if (!self::resolvesToProduction($base)) {
            // `.env.local` is loaded first *because* DotEnv never overwrites a
            // name that is already set. Process environment > .env.local > .env
            // falls out of the load order, with no merge step to get wrong.
            DotEnv::load($override, [self::ENVIRONMENT_KEY]);
        }

        DotEnv::load($base);

        /** @var array<string, string> $values */
        $values = [];

        foreach ($_ENV as $name => $value) {
            if (is_string($name) && is_scalar($value) && !self::isTestOnly($name)) {
                $values[$name] = (string) $value;
            }
        }

        // $_ENV alone is not the process environment: whether PHP populates it
        // at all depends on `variables_order`, which is an ini setting and not
        // something a caller exporting a variable can be expected to know. The
        // process environment is read directly and overlaid last, so exporting
        // a value wins over both files on every SAPI and every configuration.
        $fromProcess = getenv();

        foreach ($fromProcess as $name => $value) {
            if ($value !== '' && !self::isTestOnly($name)) {
                $values[$name] = $value;
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
        if (self::isTestOnly($key)) {
            return false;
        }

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
        if (self::isTestOnly($key)) {
            throw MissingConfigurationException::forTestOnlyKey($key);
        }

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

    /**
     * Is this name owned by the test suite rather than the application?
     *
     * `.env.testing` is loaded into `$_ENV` by the PHPUnit bootstrap, so the
     * application and the suite share one process. This is the boundary that
     * keeps the application from resolving a test credential by accident.
     */
    public static function isTestOnly(string $key): bool
    {
        return str_starts_with($key, self::TEST_ONLY_PREFIX);
    }

    /**
     * The environment as decided *without* consulting `.env.local`.
     *
     * Production is the default, so an unreadable or silent `.env` keeps the
     * override file out of the picture rather than opting into it.
     */
    private static function resolvesToProduction(string $baseFile): bool
    {
        $fromProcess = getenv(self::ENVIRONMENT_KEY);

        if (is_string($fromProcess) && $fromProcess !== '') {
            return $fromProcess === 'production';
        }

        $fromSuperglobal = $_ENV[self::ENVIRONMENT_KEY] ?? null;

        if (is_string($fromSuperglobal) && $fromSuperglobal !== '') {
            return $fromSuperglobal === 'production';
        }

        $fromFile = DotEnv::read($baseFile)[self::ENVIRONMENT_KEY] ?? '';

        return $fromFile === '' || $fromFile === 'production';
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
