<?php

declare(strict_types=1);

?>
<div class="mt-8 flex flex-wrap items-center gap-4 border-t pt-6">
    <p class="facet-ink-muted">Signed in as <?= $view->text($accountEmail) ?>.</p>
    <form method="post" action="<?= $view->url('/logout') ?>">
        <input type="hidden" name="<?= $view->attr($csrfField) ?>" value="<?= $view->attr($csrfToken) ?>">
        <button class="rounded facet-button px-4 py-2" type="submit">Sign out</button>
    </form>
</div>
