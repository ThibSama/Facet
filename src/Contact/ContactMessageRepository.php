<?php

declare(strict_types=1);

namespace Facet\Contact;

use Facet\Database\Database;
use Facet\Database\DatabaseException;

/**
 * The MariaDB-backed message store.
 *
 * One statement, fully parameterised. Not "escaped carefully" — parameterised:
 * {@see Database} runs with `ATTR_EMULATE_PREPARES => false`, so the four
 * values below are sent to the server separately from the SQL text and are
 * never part of a statement the parser sees. No column name, table name or
 * value in this class is composed from anything a request supplied.
 *
 * `status` and `created_at` are deliberately absent from the INSERT. The schema
 * defaults them to `new` and `CURRENT_TIMESTAMP`, and letting the database own
 * both means a message's arrival time is the server's clock rather than a value
 * a caller could get wrong — and that a stored message's lifecycle always
 * starts in the one state the ENUM calls new.
 *
 * Equally deliberate is what is *not* here: no IP address, no user agent, no
 * session identifier, no CSRF token, no honeypot result, no throttle counter.
 * The table holds what a person wrote and when it arrived, because that is what
 * answering them requires, and holding more would be collecting personal data
 * for no stated purpose.
 */
final class ContactMessageRepository implements ContactMessageStore, ContactMessageReader, ContactMessageStatusUpdater
{
    /**
     * The insert, written out once so the column list and the placeholder list
     * cannot drift apart unnoticed.
     */
    private const INSERT = 'INSERT INTO contact_messages (name, email, subject, message) '
        . 'VALUES (:name, :email, :subject, :message)';

    private const NEWEST = 'SELECT id, name, email, subject, message, status, created_at '
        . 'FROM contact_messages ORDER BY created_at DESC, id DESC LIMIT 100';

    private const FIND = 'SELECT id, name, email, subject, message, status, created_at '
        . 'FROM contact_messages WHERE id = :id';

    private const UPDATE_STATUS = 'UPDATE contact_messages SET status = :status WHERE id = :id';

    private const EXISTS = 'SELECT id FROM contact_messages WHERE id = :id';

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function store(ContactSubmission $submission): int
    {
        try {
            $this->database->execute(self::INSERT, $submission->toArray());

            $id = $this->database->lastInsertId();
        } catch (DatabaseException $error) {
            // The database exception is already credential-scrubbed, but it
            // still names SQL. It is attached as a cause for the debug page and
            // never becomes the text a visitor reads.
            throw ContactStoreException::writeFailed($error);
        }

        if ($id === null || !ctype_digit($id)) {
            throw ContactStoreException::writeFailed();
        }

        return (int) $id;
    }

    public function newest(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('The inbox limit must be between 1 and 100.');
        }

        try {
            $rows = $this->database->select(self::NEWEST);
        } catch (DatabaseException $error) {
            throw ContactInboxException::readFailed($error);
        }

        return array_map(self::hydrate(...), array_slice($rows, 0, $limit));
    }

    public function find(int $id): ?ContactMessage
    {
        try {
            $row = $this->database->selectOne(self::FIND, ['id' => $id]);
        } catch (DatabaseException $error) {
            throw ContactInboxException::readFailed($error);
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function updateStatus(int $id, ContactMessageStatus $status): bool
    {
        try {
            $changed = $this->database->execute(self::UPDATE_STATUS, [
                'status' => $status->value,
                'id' => $id,
            ]);

            if ($changed === 1) {
                return true;
            }

            return $this->database->selectValue(self::EXISTS, ['id' => $id]) !== null;
        } catch (DatabaseException $error) {
            throw ContactMessageMutationException::updateFailed($error);
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ContactMessage
    {
        $status = ContactMessageStatus::tryFrom((string) ($row['status'] ?? ''));

        if ($status === null) {
            throw ContactInboxException::readFailed();
        }

        return new ContactMessage(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['subject'] ?? ''),
            (string) ($row['message'] ?? ''),
            $status,
            (string) ($row['created_at'] ?? '')
        );
    }
}
