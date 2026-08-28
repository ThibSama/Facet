<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use Facet\Contact\ContactMessageStore;
use Facet\Contact\ContactStoreException;
use Facet\Contact\ContactSubmission;

/**
 * A message store that counts.
 *
 * Almost every claim this checkpoint makes is a claim about *row counts* — a
 * refused token inserts nothing, a filled honeypot inserts nothing, a refresh
 * inserts nothing more, a failed write inserts nothing partial. Counting in
 * memory makes each of those a single exact assertion, and lets the failure
 * case be injected rather than simulated by breaking a real database.
 *
 * The live counterpart of every one of these assertions is run against MariaDB
 * in {@see \Facet\Tests\Database\ContactMessagePersistenceTest} and over real
 * HTTP in {@see \Facet\Tests\Smoke\ContactHttpFlowTest}. This one exists so the
 * *branches* are exhaustively covered without a database per case.
 */
final class RecordingContactMessageStore implements ContactMessageStore
{
    /** @var list<array<string, string>> */
    private array $stored = [];

    private bool $failing;

    private int $nextId = 1;

    public function __construct(bool $failing = false)
    {
        $this->failing = $failing;
    }

    public static function failing(): self
    {
        return new self(true);
    }

    public function store(ContactSubmission $submission): int
    {
        if ($this->failing) {
            // Thrown before anything is recorded: the point of the fixture is
            // that a failed write leaves nothing behind, not even a half row.
            throw ContactStoreException::writeFailed();
        }

        $this->stored[] = $submission->toArray();

        return $this->nextId++;
    }

    public function count(): int
    {
        return count($this->stored);
    }

    /**
     * @return list<array<string, string>>
     */
    public function all(): array
    {
        return $this->stored;
    }

    /**
     * @return array<string, string>|null
     */
    public function last(): ?array
    {
        return $this->stored === [] ? null : $this->stored[count($this->stored) - 1];
    }
}
