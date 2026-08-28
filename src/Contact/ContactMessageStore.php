<?php

declare(strict_types=1);

namespace Facet\Contact;

/**
 * Somewhere a validated contact message can be kept.
 *
 * The interface exists so the HTTP layer can depend on *storing a message*
 * rather than on MariaDB. That is not abstraction for its own sake: the public
 * site is file-backed and a structural test asserts that no class under
 * `src/Http` can even name `Facet\Database`. This seam is what keeps that true
 * now that a public page writes a row.
 */
interface ContactMessageStore
{
    /**
     * Store one message and return the identifier it was given.
     *
     * Implementations either store the message completely or throw. There is no
     * partially-stored outcome and no boolean return: a caller that receives an
     * id knows a row exists, and a caller that catches knows none does.
     *
     * @throws ContactStoreException when the message was not stored
     */
    public function store(ContactSubmission $submission): int;
}
