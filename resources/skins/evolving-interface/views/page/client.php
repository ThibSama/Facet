<?php

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Client area';
ob_start();
?>
<h1 class="text-3xl font-semibold tracking-tight">Client area</h1>
<p class="mt-4 max-w-prose facet-ink-muted">
    This private area identifies your account. No client feature has been delivered here yet.
</p>
<?php require dirname(__DIR__) . '/partials/private-session.php'; ?>
<?php
$content = Html::trusted((string) ob_get_clean());
require dirname(__DIR__) . '/layout.php';
