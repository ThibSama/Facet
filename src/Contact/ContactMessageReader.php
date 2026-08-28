<?php

declare(strict_types=1);

namespace Facet\Contact;

interface ContactMessageReader
{
    /** @return list<ContactMessage> */
    public function newest(int $limit): array;

    public function find(int $id): ?ContactMessage;
}
