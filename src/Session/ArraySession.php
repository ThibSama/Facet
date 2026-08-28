<?php

declare(strict_types=1);

namespace Facet\Session;

/**
 * A session that lives in an array and nowhere else.
 *
 * Two uses, both deliberate. In tests it makes session-backed behaviour —
 * tokens, flashes, throttling — assertable without a web server. In
 * production it is the *fail-closed default*: an application booted without a
 * real session still renders, but every CSRF check it performs compares
 * against a token that no other request can have seen, so a POST is refused
 * rather than accepted on an absent guard.
 */
final class ArraySession implements Session
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    public function pull(string $key): ?string
    {
        $value = $this->get($key);

        $this->forget($key);

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
