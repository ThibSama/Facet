<?php

/**
 * evolving-interface — the public language switch.
 *
 * Two links and a landmark. Changing language is navigation to another
 * canonical URL, so that is exactly what this is: no button, no script, no
 * form, and nothing that stops working when JavaScript does not run. Where each
 * link goes was decided by {@see \Facet\Navigation\LanguageSwitch}; this file
 * decides only how it looks.
 *
 * Each link says its language twice. The visible mark is two letters, because
 * the header has room for two letters and not for "Français"; the full name
 * follows it in a clipped span, so the accessible name is "FR Français" — it
 * contains the visible label, which is what keeps a voice-control user able to
 * say what they see, and it names the language in the language it is offering
 * rather than in a translation of it.
 *
 * `aria-current="true"` marks the language in effect rather than `page`: the
 * primary navigation already says which page you are on, and what this control
 * states is which language you are reading it in. The current language stays a
 * real link on purpose — it is the canonical URL of the page you are on, and a
 * disabled control here would be one more thing that cannot be copied, shared
 * or opened in a new tab.
 *
 * @var \Facet\Html\ViewContext            $view
 * @var \Facet\Navigation\LanguageSwitch   $languageSwitch
 */

declare(strict_types=1);

?>
            <nav class="facet-lang" aria-label="<?= $view->attr($languageSwitch->label()) ?>" data-facet-lang>
                <ul class="facet-lang__list">
                    <?php foreach ($languageSwitch->items() as $language): ?>
                    <li class="facet-lang__item">
                        <a
                            class="facet-lang__link"
                            href="<?= $view->url($language->href()) ?>"
                            hreflang="<?= $view->attr($language->hrefLang()) ?>"
                            lang="<?= $view->attr($language->hrefLang()) ?>"
                            <?= $view->attributes(['aria-current' => $language->ariaCurrent()]) ?>
                        ><?= $view->text($language->label()) ?><span class="facet-lang__full"> <?= $view->text($language->endonym()) ?></span></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
