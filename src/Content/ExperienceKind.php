<?php

declare(strict_types=1);

namespace Facet\Content;

/**
 * What kind of experience an entry records.
 *
 * Professional experience is a separate, explicit kind precisely so that an
 * education entry can never be mistaken for employment.
 */
enum ExperienceKind: string
{
    case Education = 'education';
    case Professional = 'professional';
    case Volunteer = 'volunteer';
}
