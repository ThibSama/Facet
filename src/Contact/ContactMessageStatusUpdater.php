<?php

declare(strict_types=1);

namespace Facet\Contact;

interface ContactMessageStatusUpdater
{
    /** Return false only when no row with this id exists. */
    public function updateStatus(int $id, ContactMessageStatus $status): bool;
}
