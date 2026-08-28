<?php

declare(strict_types=1);

namespace Facet\Contact;

use RuntimeException;
use Throwable;

final class ContactMessageMutationException extends RuntimeException
{
    public static function updateFailed(?Throwable $previous = null): self
    {
        return new self('The contact message status could not be updated.', 0, $previous);
    }
}
