<?php

/**
 * evolving-interface — shell footer.
 *
 * The closing landmark of the shell. It carries no page state, so it renders
 * the same on a 200 and a 404, and it repeats the primary links as plain
 * anchors: a visitor who reached the bottom of a long page — or who has the
 * navigation collapsed — always has a way out that needs no JavaScript.
 *
 * @var \Facet\Html\ViewContext      $view
 * @var \Facet\Navigation\Navigation $navigation
 * @var string                       $appName
 */

declare(strict_types=1);

?>
    <footer class="facet-footer">
        <div class="facet-footer__inner facet-shell">
            <p class="facet-footer__name"><?= $view->text($appName) ?></p>
            <ul class="facet-footer__links">
                <?php foreach ($navigation->items() as $item): ?>
                <li>
                    <a
                        class="facet-footer__link"
                        href="<?= $view->url($item->href()) ?>"
                        <?= $view->attributes(['aria-current' => $item->ariaCurrent()]) ?>
                    ><?= $view->text($item->label()) ?></a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </footer>
