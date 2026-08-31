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
 * @var \Facet\I18n\Translator  $t
 */

declare(strict_types=1);

use Facet\Content\Period;

require __DIR__ . '/locale-context.php';

/** @var \Facet\I18n\Translator $periodTranslator */
$periodTranslator = $t;

$periodLabel = static function (Period $period) use ($periodTranslator): string {
    if ($period->isOngoing()) {
        return $period->start() . ' — ' . $periodTranslator->text('content.period.present');
    }

    $end = (string) $period->end();

    return $period->start() === $end ? $end : $period->start() . ' — ' . $end;
};
