<?php

declare(strict_types=1);

namespace Facet\Contact;

final class UnavailableContactMessageStatusUpdater implements ContactMessageStatusUpdater
{
    public function updateStatus(int $id, ContactMessageStatus $status): bool
    {
        throw ContactMessageMutationException::updateFailed();
    }
}
