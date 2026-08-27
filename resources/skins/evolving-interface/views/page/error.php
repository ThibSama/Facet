<?php

/**
 * evolving-interface — logical view "page.error".
 *
 * The skin styles the error; it never decides what an error may say. `$title`,
 * `$message` and `$status` are derived from the status code alone, and
 * `$diagnostics` is empty unless the application is running in debug — so this
 * template cannot disclose anything the runtime withheld.
 *
 * @var \Facet\Html\ViewContext $view
 * @var int                     $status
 * @var string                  $title
 * @var string                  $message
 * @var bool                    $debug
 * @var list<string>            $diagnostics
 */

declare(strict_types=1);

use Facet\Html\Html;

ob_start();

?>
<p class="text-sm font-medium facet-ink-subtle"><?= $view->text($status) ?></p>
<h1 class="mt-2 text-3xl font-semibold tracking-tight"><?= $view->text($title) ?></h1>
<p class="mt-4 max-w-prose facet-ink-muted"><?= $view->text($message) ?></p>
<p class="mt-8">
    <a class="facet-link underline" href="<?= $view->url('/') ?>">Back to the home page</a>
</p>

<?php if ($debug && $diagnostics !== []): ?>
<section class="mt-12" aria-labelledby="diagnostics">
    <h2 id="diagnostics" class="text-xl font-semibold">Diagnostics</h2>
    <p class="mt-1 text-sm facet-ink-subtle">Shown because the application is running in debug mode.</p>
    <ul class="mt-4 space-y-1 font-mono text-xs facet-ink-muted">
        <?php foreach ($diagnostics as $line): ?>
        <li><?= $view->text($line) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
