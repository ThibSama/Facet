<?php

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Administration';
ob_start();
?>
<h1 class="text-3xl font-semibold tracking-tight">Administration</h1>
<p class="mt-4 max-w-prose facet-ink-muted">Private site administration.</p>
<p class="mt-6"><a class="facet-link underline" href="<?= $view->url('/admin/messages') ?>">Open contact messages</a></p>
<?php require dirname(__DIR__, 2) . '/partials/private-session.php'; ?>
<?php
$content = Html::trusted((string) ob_get_clean());
require dirname(__DIR__, 2) . '/layout.php';
