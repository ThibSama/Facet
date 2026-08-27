<?php

/**
 * Failure-injection fixture: a working error view.
 *
 * It prints exactly what the presenter handed it, so whatever appears in the
 * response is what the disclosure rules allowed — nothing is reconstructed here.
 *
 * @var \Facet\Html\ViewContext $view
 * @var int                     $status
 * @var string                  $title
 * @var string                  $message
 * @var bool                    $debug
 * @var list<string>            $diagnostics
 */

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title><?= $view->text($title) ?></title></head>
<body>
    <h1><?= $view->text($title) ?></h1>
    <p><?= $view->text($message) ?></p>
    <p data-status="<?= $view->attr($status) ?>"></p>
    <?php if ($debug): ?>
    <ul id="diagnostics">
        <?php foreach ($diagnostics as $line): ?>
        <li><?= $view->text($line) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</body>
</html>
