<?php

/**
 * evolving-interface — logical view "page.contact".
 *
 * A plain server-side form: a real action, a real method, no JavaScript. The
 * route declares GET and POST, and this document is the GET half of that
 * contract.
 *
 * @var \Facet\Html\ViewContext $view
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Contact';

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">Contact</h1>
<p class="mt-4 max-w-prose facet-ink-muted">Send a message and it will reach me directly.</p>

<form class="mt-8 max-w-md space-y-4" method="post" action="<?= $view->url('/contact') ?>">
    <p>
        <label class="block text-sm font-medium" for="contact-name">Name</label>
        <input class="mt-1 w-full rounded facet-field border px-3 py-2"
               id="contact-name" name="name" type="text" required>
    </p>
    <p>
        <label class="block text-sm font-medium" for="contact-email">Email</label>
        <input class="mt-1 w-full rounded facet-field border px-3 py-2"
               id="contact-email" name="email" type="email" required>
    </p>
    <p>
        <label class="block text-sm font-medium" for="contact-message">Message</label>
        <textarea class="mt-1 w-full rounded facet-field border px-3 py-2"
                  id="contact-message" name="message" rows="6" required></textarea>
    </p>
    <p>
        <button class="rounded facet-button px-4 py-2" type="submit">Send</button>
    </p>
</form>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
