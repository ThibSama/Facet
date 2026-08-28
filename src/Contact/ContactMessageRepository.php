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
final class ContactMessageRepository implements ContactMessageStore
{
    /**
     * The insert, written out once so the column list and the placeholder list
     * cannot drift apart unnoticed.
     */
    private const INSERT = 'INSERT INTO contact_messages (name, email, subject, message) '
        . 'VALUES (:name, :email, :subject, :message)';

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
}
