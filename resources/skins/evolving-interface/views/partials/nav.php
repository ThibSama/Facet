<?php

/**
 * evolving-interface — primary navigation landmark.
 *
 * The list is the navigation model the shared runtime handed over; this file
 * decides only how it looks. `aria-current` comes from the model rather than
 * from a path comparison written here, which is what keeps a project detail
 * URL highlighting Projects without every skin re-deriving that rule.
 *
 * Nothing here depends on hover: the links are links, and the collapse the
 * runtime may add is driven by a real button with real state.
 *
 * @var \Facet\Html\ViewContext      $view
 * @var \Facet\Navigation\Navigation $navigation
 * @var string                       $navigationId
 */

declare(strict_types=1);

?>
            <nav
                class="facet-nav"
                id="<?= $view->attr($navigationId) ?>"
                aria-label="<?= $view->attr($navigation->label()) ?>"
                data-facet-nav
            >
                <ul class="facet-nav__list">
                    <?php foreach ($navigation->items() as $item): ?>
                    <li class="facet-nav__item">
                        <a
                            class="facet-nav__link"
                            href="<?= $view->url($item->href()) ?>"
                            <?= $view->attributes(['aria-current' => $item->ariaCurrent()]) ?>
                        ><?= $view->text($item->label()) ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
