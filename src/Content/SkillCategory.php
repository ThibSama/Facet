<?php

declare(strict_types=1);

namespace Facet\Content;

/**
 * Grouping for skills, mirroring the categories used in the historical
 * portfolio so the corpus stays comparable with its source.
 */
enum SkillCategory: string
{
    case Language = 'language';
    case Framework = 'framework';
    case Database = 'database';
    case Tooling = 'tooling';
    case Certification = 'certification';
}
