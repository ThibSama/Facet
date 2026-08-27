<?php

/**
 * evolving-interface — the skin's one media slot.
 *
 * Every place this skin illustrates a Content entry goes through this file, so
 * "what a picture looks like here" is one decision rather than one per page.
 *
 * The slot is deliberately not an `<img>`. A {@see \Facet\Content\Media} may
 * have no source — that is the corpus's normal, documented state today — and
 * emitting an image element for a reference no asset answers would produce a
 * broken image on every page. Instead the slot renders an empty box that
 * announces itself with the media's mandatory textual description, so the
 * accessibility tree carries the same thing a reader would get from the
 * picture, and nothing is ever the *only* place a fact lives.
 *
 * Geometry is reserved inline rather than in the stylesheet: the ratio is the
 * one piece of the slot that must hold even before the skin's CSS arrives,
 * because it is what stops a card grid from reflowing when images land later.
 *
 * `data-facet-media` carries {@see \Facet\Content\Media::reference()} — the
 * corpus's logical, skin-independent name for the illustration. No mapping
 * from a logical reference to a built asset exists yet, so every reference
 * resolves to this neutral box; the attribute is what a later checkpoint keys
 * that mapping off, and it is why the slot never assumes the literal fallback.
 *
 * Callers set both variables immediately before the require: they are
 * arguments, and defaulting them here would hide a caller that forgot one.
 *
 * @var \Facet\Html\ViewContext $view
 * @var \Facet\Content\Media    $media      the entry's canonical illustration
 * @var string                  $mediaRatio a CSS `aspect-ratio` value
 */

declare(strict_types=1);

?>
<div
    class="facet-media"
    role="img"
    aria-label="<?= $view->attr($media->description()) ?>"
    data-facet-media="<?= $view->attr($media->reference()) ?>"
    style="aspect-ratio: <?= $view->attr($mediaRatio) ?>;"
></div>
