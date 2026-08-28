<?php

declare(strict_types=1);

namespace Facet\Contact;

use RuntimeException;
use Throwable;

final class ContactInboxException extends RuntimeException
{
    public static function readFailed(?Throwable $previous = null): self
    {
        return new self('The contact inbox could not be read.', 0, $previous);
    }
}
