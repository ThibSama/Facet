<?php

declare(strict_types=1);

namespace Facet\Contact;

final class UnavailableContactMessageReader implements ContactMessageReader
{
    public function newest(int $limit): array
    {
        throw ContactInboxException::readFailed();
    }

    public function find(int $id): ?ContactMessage
    {
        throw ContactInboxException::readFailed();
    }
}
