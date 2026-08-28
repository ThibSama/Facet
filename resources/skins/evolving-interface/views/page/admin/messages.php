<?php

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Contact messages';
ob_start();
?>
<p><a class="facet-link underline" href="<?= $view->url('/admin') ?>">Administration</a></p>
<h1 class="mt-4 text-3xl font-semibold tracking-tight">Contact messages</h1>

<?php if ($messages === []): ?>
<p class="mt-6 facet-ink-muted" id="empty-inbox">There are no contact messages.</p>
<?php else: ?>
<table class="mt-6 w-full facet-inbox">
    <thead><tr><th scope="col">ID</th><th scope="col">Sender</th><th scope="col">Subject</th><th scope="col">Status</th><th scope="col">Created</th></tr></thead>
    <tbody>
    <?php foreach ($messages as $message): ?>
    <tr>
        <td data-label="ID"><?= $view->text($message->id()) ?></td>
        <td data-label="Sender"><?= $view->text($message->name()) ?></td>
        <td data-label="Subject"><a class="facet-link underline" href="<?= $view->url('/admin/messages?id=' . $message->id()) ?>"><?= $view->text($message->subject()) ?></a></td>
        <td data-label="Status"><?= $view->text($message->status()->value) ?></td>
        <td data-label="Created"><time datetime="<?= $view->attr($message->createdAt()) ?>"><?= $view->text($message->createdAt()) ?></time></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($selectedMessage !== null): ?>
<article class="mt-10 facet-message" aria-labelledby="message-subject">
    <h2 id="message-subject" class="text-2xl font-semibold"><?= $view->text($selectedMessage->subject()) ?></h2>
    <p class="mt-2 facet-ink-muted">From <?= $view->text($selectedMessage->name()) ?> &lt;<?= $view->text($selectedMessage->email()) ?>&gt;</p>
    <p class="mt-4 whitespace-pre-wrap"><?= $view->text($selectedMessage->message()) ?></p>
    <form class="mt-6 flex items-end gap-4 facet-inline-form" method="post" action="<?= $view->url('/admin/messages') ?>">
        <input type="hidden" name="<?= $view->attr($csrfField) ?>" value="<?= $view->attr($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $view->attr($selectedMessage->id()) ?>">
        <label>Status
            <select name="status">
                <?php foreach (['new', 'read', 'archived'] as $status): ?>
                <option value="<?= $view->attr($status) ?>"<?php if ($selectedMessage->status()->value === $status): ?> selected<?php endif; ?>><?= $view->text($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="rounded facet-button px-4 py-2" type="submit">Update status</button>
    </form>
</article>
<?php endif; ?>

<?php require dirname(__DIR__, 2) . '/partials/private-session.php'; ?>
<?php
$content = Html::trusted((string) ob_get_clean());
require dirname(__DIR__, 2) . '/layout.php';
