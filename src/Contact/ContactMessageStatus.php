<?php

declare(strict_types=1);

namespace Facet\Contact;

enum ContactMessageStatus: string
{
    case New = 'new';
    case Read = 'read';
    case Archived = 'archived';
}
