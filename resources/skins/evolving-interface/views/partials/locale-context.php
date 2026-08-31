<?php

/**
 * evolving-interface — the language a template is being rendered in.
 *
 * Every dispatched request is handed both values by the runtime, so in
 * production this file changes nothing. It exists for the other way a template
 * is rendered — directly, by a test or a preview, with hand-built data — where
 * the alternative is a fatal on an undefined variable rather than a page in the
 * default language. Establishing them in one partial is what keeps that
 * fallback a single decision instead of one per view.
 *
 * @var \Facet\I18n\Locale|null     $locale
 * @var \Facet\I18n\Translator|null $t
 */

declare(strict_types=1);

use Facet\I18n\Locale;
use Facet\I18n\Translator;

$locale = ($locale ?? null) instanceof Locale ? $locale : Locale::default();
$t = ($t ?? null) instanceof Translator ? $t : new Translator($locale);
