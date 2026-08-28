<?php

declare(strict_types=1);

namespace Facet\Session;

/**
 * The anonymous per-visitor store, reduced to what this checkpoint needs.
 *
 * Deliberately a string map and nothing more. A CSRF token, a one-shot flash
 * and a throttle window are all this checkpoint has to remember, and a narrow
 * interface is what keeps the application testable: {@see ArraySession} is a
 * complete implementation in a dozen lines, so every session-dependent branch
 * is exercised in process with no SAPI, no cookie and no `session_start()`.
 *
 * There is no authentication here on purpose. Login, roles and fixation
 * handling are a later checkpoint; this seam exists solely so the contact form
 * can be defended, and it deliberately stops there.
 */
interface Session
{
    public function has(string $key): bool;

    public function get(string $key): ?string;

    public function put(string $key, string $value): void;

    public function forget(string $key): void;

    /**
     * Read a value and remove it in the same breath — the flash idiom.
     *
     * It is one method rather than a get/forget pair because a flash that is
     * read and not cleared is exactly the bug that makes a PRG confirmation
     * reappear on the next page view.
     */
    public function pull(string $key): ?string;
}
