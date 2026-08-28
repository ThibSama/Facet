<?php

declare(strict_types=1);

namespace Facet\Contact;

/**
 * The store used when none is configured.
 *
 * A portfolio must keep rendering with no database credentials at all, so an
 * absent store cannot be a boot failure. It is instead a failure at the moment
 * something is actually submitted — which is both honest and the same code path
 * as a database that is configured but down, so the safe-failure behaviour is
 * exercised by every deployment shape rather than only by the broken one.
 */
final class UnavailableContactMessageStore implements ContactMessageStore
{
    public function store(ContactSubmission $submission): int
    {
        throw ContactStoreException::unavailable();
    }
}
