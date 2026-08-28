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
 *
 * The identifier lifecycle is modelled rather than performed: there is no
 * cookie to re-key, so {@see regenerate()} records that it happened and
 * {@see destroy()} does what destruction has to mean here — the data is gone.
 * Counting regenerations is what lets an in-process test assert that login
 * re-keys *before* it writes, which is the ordering the whole fixation defence
 * rests on; that the re-keying really changes a cookie is a claim only the real
 * SAPI can settle, and {@see \Facet\Tests\Smoke\AuthHttpFlowTest} settles it.
 */
final class ArraySession implements Session
{
    /** @var array<string, string> */
    private array $values;

    private int $regenerations = 0;

    private bool $destroyed = false;

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

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->values = [];
        $this->destroyed = true;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * How many times the identifier would have been re-keyed.
     */
    public function regenerations(): int
    {
        return $this->regenerations;
    }

    public function wasDestroyed(): bool
    {
        return $this->destroyed;
    }
}
