<?php

/**
 * evolving-interface — logical view "page.contact".
 *
 * A plain server-side form: a real action, a real method, no JavaScript. The
 * route declares GET and POST, and this document is the GET half of that
 * contract — the POST half is a later checkpoint, and until it exists the page
 * says so rather than implying a message goes anywhere.
 *
 * Every control is built so the server can speak through it later without this
 * markup changing shape: each field owns a help element and an *empty* error
 * element, both already named in the input's `aria-describedby`. Filling the
 * error element is then the whole of rendering a validation failure — no ids
 * to invent, no attributes to add, no layout to redo.
 *
 * The native constraints below are conveniences for the person typing, not a
 * boundary: nothing here is trusted, because nothing here is enforced by this
 * document. Validation is the server's job at the checkpoint that accepts the
 * submission.
 *
 * Alternative ways to reach the author come from the canonical profile links
 * and nowhere else. No address this site cannot source is written here.
 *
 * @var \Facet\Html\ViewContext $view
 * @var \Facet\Content\Profile  $profile
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Contact';

$profileLinks = $profile->links();

/*
 * The fields, declared once and rendered by one loop, so a field cannot
 * acquire a label, a help target or an error target that the others lack.
 * `element` picks the control; everything else is attributes verbatim.
 *
 * @var list<array{name: string, label: string, help: string, element: string, attributes: array<string, string|int|null>}> $fields
 */
$fields = [
    [
        'name' => 'name',
        'label' => 'Name',
        'help' => 'How you would like to be addressed.',
        'element' => 'input',
        'attributes' => ['type' => 'text', 'autocomplete' => 'name', 'autocapitalize' => 'words'],
    ],
    [
        'name' => 'email',
        'label' => 'Email',
        'help' => 'The address a reply would be written to.',
        'element' => 'input',
        'attributes' => [
            'type' => 'email',
            'autocomplete' => 'email',
            'inputmode' => 'email',
            'spellcheck' => 'false',
        ],
    ],
    [
        'name' => 'subject',
        'label' => 'Subject',
        'help' => 'One line saying what the message is about.',
        'element' => 'input',
        'attributes' => ['type' => 'text', 'autocomplete' => 'off'],
    ],
    [
        'name' => 'message',
        'label' => 'Message',
        'help' => 'The message itself. Plain text — no formatting is interpreted.',
        'element' => 'textarea',
        'attributes' => ['rows' => 8, 'autocomplete' => 'off'],
    ],
];

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">Contact</h1>

<?php
/*
 * The one thing this page must not do is imply a delivery it cannot perform.
 * POST /contact has no handler yet, so the state of the form is stated plainly
 * and the working alternatives are named in the same breath.
 */
?>
<p id="contact-status" class="mt-4 max-w-prose facet-ink-muted">
    This form does not send anything yet. Until it does, the links below are the way to reach me.
</p>

<form
    class="mt-8 max-w-md space-y-6"
    method="post"
    action="<?= $view->url('/contact') ?>"
    aria-describedby="contact-status"
>
    <?php foreach ($fields as $field): ?>
    <?php
    $fieldId = 'contact-' . $field['name'];
    $helpId = $fieldId . '-help';
    $errorId = $fieldId . '-error';

    // Shared by both control kinds. `required` is a hint to the browser and
    // is repeated to assistive technology, which is all it is: the server
    // decides what a valid submission is.
    $controlAttributes = [
        'id' => $fieldId,
        'name' => $field['name'],
        'required' => 'required',
        'aria-required' => 'true',
        'aria-describedby' => $helpId . ' ' . $errorId,
    ] + $field['attributes'];
    ?>
    <div>
        <label class="block text-sm font-medium" for="<?= $view->attr($fieldId) ?>"><?= $view->text($field['label']) ?></label>

        <?php if ($field['element'] === 'textarea'): ?>
        <textarea class="mt-1 w-full rounded facet-field border px-3 py-2" <?= $view->attributes($controlAttributes) ?>></textarea>
        <?php else: ?>
        <input class="mt-1 w-full rounded facet-field border px-3 py-2" <?= $view->attributes($controlAttributes) ?>>
        <?php endif; ?>

        <p class="mt-1 text-sm facet-field-help" id="<?= $view->attr($helpId) ?>"><?= $view->text($field['help']) ?></p>

        <?php
        /*
         * The error slot. It is empty and it stays in the document: an element
         * that already exists and is already referenced is one the server can
         * fill without touching anything else. Empty, it collapses and
         * contributes nothing to the accessible description.
         */
        ?>
        <p class="mt-1 text-sm facet-field-error" id="<?= $view->attr($errorId) ?>" data-facet-field-error></p>
    </div>
    <?php endforeach; ?>

    <p>
        <button class="rounded facet-button px-4 py-2" type="submit">Send message</button>
    </p>
</form>

<?php if ($profileLinks !== []): ?>
<section class="mt-16" aria-labelledby="other-ways">
    <h2 id="other-ways" class="text-2xl font-semibold tracking-tight">Other ways to reach me</h2>

    <?php
    /*
     * Canonical label, canonical URL, link type as data. The corpus documents
     * no email address and no phone number, so this page offers neither.
     */
    ?>
    <ul class="mt-4 space-y-1">
        <?php foreach ($profileLinks as $profileLink): ?>
        <li>
            <a
                class="facet-link underline"
                href="<?= $view->url($profileLink->url()) ?>"
                rel="noopener noreferrer"
                data-link-type="<?= $view->attr($profileLink->type()->value) ?>"
            ><?= $view->text($profileLink->label()) ?></a>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
