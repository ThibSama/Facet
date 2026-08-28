<?php

declare(strict_types=1);

namespace Facet\Contact;

use RuntimeException;
use Throwable;

/**
 * The message could not be stored.
 *
 * One exception type for every reason storage can fail — unconfigured,
 * unreachable, rejected by a constraint — because the caller's response is the
 * same in all of them: tell the visitor honestly that the message was not
 * received, and disclose nothing about why. The underlying cause is attached
 * for logs and debug pages; the public text never comes from it.
 */
final class ContactStoreException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('No contact message store is configured.');
    }

    public static function writeFailed(?Throwable $previous = null): self
    {
        return new self('The contact message could not be written.', 0, $previous);
    }
}
