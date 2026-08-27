<?php

/**
 * evolving-interface — canonical period formatting.
 *
 * Defines `$periodLabel`, shared by the project views so the catalogue and a
 * case study never disagree about how the same dates read.
 *
 * The bounds are printed exactly as the corpus stores them — `YYYY` or
 * `YYYY-MM` — because widening them to a day, a month name or a locale would
 * be stating something no source supports. Only the join and the open-ended
 * wording are this skin's own vocabulary.
 *
 * @var \Facet\Html\ViewContext $view
 */

declare(strict_types=1);

use Facet\Content\Period;

$periodLabel = static function (Period $period): string {
    if ($period->isOngoing()) {
        return $period->start() . ' — present';
    }

    $end = (string) $period->end();

    return $period->start() === $end ? $end : $period->start() . ' — ' . $end;
};
