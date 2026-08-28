<?php

/**
 * evolving-interface — logical view "page.contact".
 *
 * A plain server-side form: a real action, a real method, no JavaScript. The
 * route declares GET and POST and both halves are now real — a submission is
 * validated, defended and stored, and this document renders every state that
 * can produce.
 *
 * Nothing here decides anything. The token, the field values, the per-field
 * errors and the notice are all handed down by the application; this file's
 * entire job is to place them where a reader and a screen reader will find
 * them. That division is what makes the security properties testable in one
 * place instead of being spread across markup.
 *
 * The structure the previous checkpoint set up is unchanged and is why this
 * change is small: every field already owned a help element and an *empty*
 * error element, both already named in the control's `aria-describedby`, so
 * rendering a validation failure is filling an element that already exists.
 *
 * The native constraints below remain conveniences for the person typing and
 * not a boundary: `required` and `type="email"` are enforced by nothing the
 * server can see, and a submission that arrives without either is judged by
 * exactly the same validator.
 *
 * Alternative ways to reach the author come from the canonical profile links
 * and nowhere else. No address this site cannot source is written here.
 *
 * @var \Facet\Html\ViewContext $view
 * @var \Facet\Content\Profile  $profile
 * @var string                  $csrfField     name of the CSRF form field
 * @var string                  $csrfToken     this session's token
 * @var string                  $honeypotField name of the decoy field
 * @var array<string, string>   $values        normalised values to redisplay
 * @var array<string, string>   $errors        field name => reason
 * @var array{kind: string, text: string}|null $notice form-level statement
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Contact';

$profileLinks = $profile->links();

$values = $values ?? [];
$errors = $errors ?? [];
$notice = $notice ?? null;

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
        'attributes' => ['type' => 'text', 'autocomplete' => 'name', 'autocapitalize' => 'words', 'maxlength' => 120],
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
            'maxlength' => 254,
        ],
    ],
    [
        'name' => 'subject',
        'label' => 'Subject',
        'help' => 'One line saying what the message is about.',
        'element' => 'input',
        'attributes' => ['type' => 'text', 'autocomplete' => 'off', 'maxlength' => 200],
    ],
    [
        'name' => 'message',
        'label' => 'Message',
        'help' => 'The message itself. Plain text — no formatting is interpreted.',
        'element' => 'textarea',
        'attributes' => ['rows' => 8, 'autocomplete' => 'off', 'maxlength' => 5000],
    ],
];

$noticeClass = ($notice['kind'] ?? '') === 'success' ? 'facet-notice--success' : 'facet-notice--error';

$hasNotice = is_array($notice) && isset($notice['text']) && $notice['text'] !== '';

/*
 * The form is described by the standing explanation, and — when a submission
 * has just been answered — by that outcome as well, so the outcome reaches
 * someone who moves straight to a field without reading down to it.
 */
$formDescribedBy = $hasNotice ? 'contact-notice contact-status' : 'contact-status';

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">Contact</h1>

<?php
/*
 * What the form actually does, stated once and stated exactly. A message is
 * received and kept where the author reads it; nothing is forwarded, emailed
 * or acknowledged automatically, and the page says so rather than letting a
 * visitor infer a delivery that does not happen.
 */
?>
<p id="contact-status" class="mt-4 max-w-prose facet-ink-muted">
    What you write here is stored on this site, where I read it. Nothing is forwarded anywhere automatically,
    so for anything urgent the links below are quicker.
</p>

<?php if ($hasNotice): ?>
<?php
/*
 * The form-level statement: the outcome of a submission, as opposed to the
 * standing description above. It sits before the form so the reading order
 * matches the order the page is understood in, and it is named first in the
 * form's `aria-describedby` so the outcome reaches someone who moves straight
 * to a field without reading down to it.
 *
 * The live region stays. Unlike the login refusal — which a visitor answers by
 * going back into the form, where the description reaches them — a
 * confirmation is the end of the errand: there is nothing left to fill in and
 * no reason to return to the form at all. Announcing it is the only channel
 * left that does not need JavaScript.
 */
?>
<p
    class="mt-6 max-w-md rounded facet-notice <?= $view->attr($noticeClass) ?> px-4 py-3"
    id="contact-notice"
    role="status"
    aria-live="polite"
><?= $view->text($notice['text']) ?></p>
<?php endif; ?>

<form
    class="mt-8 max-w-md space-y-6 facet-form-panel"
    method="post"
    action="<?= $view->url('/contact') ?>"
    aria-describedby="<?= $view->attr($formDescribedBy) ?>"
>
    <?php
    /*
     * Proof that this submission was composed on this page, in this session.
     * A hidden input rather than a header, because the form must work with no
     * JavaScript at all — there is nothing here to set a header with.
     */
    ?>
    <input type="hidden" name="<?= $view->attr($csrfField) ?>" value="<?= $view->attr($csrfToken) ?>">

    <?php foreach ($fields as $field): ?>
    <?php
    $fieldId = 'contact-' . $field['name'];
    $helpId = $fieldId . '-help';
    $errorId = $fieldId . '-error';

    $error = isset($errors[$field['name']]) && is_string($errors[$field['name']])
        ? $errors[$field['name']]
        : '';

    $value = isset($values[$field['name']]) && is_string($values[$field['name']])
        ? $values[$field['name']]
        : '';

    // Shared by both control kinds. `required` is a hint to the browser and
    // is repeated to assistive technology, which is all it is: the server
    // decides what a valid submission is.
    //
    // `aria-invalid` is set only when there is an error to point at, so the
    // attribute means "this control was rejected" rather than being a
    // permanent fixture the reader learns to ignore.
    $controlAttributes = [
        'id' => $fieldId,
        'name' => $field['name'],
        'required' => 'required',
        'aria-required' => 'true',
        'aria-describedby' => $helpId . ' ' . $errorId,
        'aria-invalid' => $error === '' ? null : 'true',
    ] + $field['attributes'];
    ?>
    <div>
        <label class="block text-sm font-medium" for="<?= $view->attr($fieldId) ?>"><?= $view->text($field['label']) ?></label>

        <?php
        /*
         * The submitted value comes back so a rejected form is corrected
         * rather than retyped. It is printed through `$view` like everything
         * else — a value the visitor typed is the least trusted string on the
         * page, and it reaches an attribute in one branch and element text in
         * the other, which are different escaping contexts.
         */
        ?>
        <?php if ($field['element'] === 'textarea'): ?>
        <textarea class="mt-1 w-full rounded facet-field border px-3 py-2" <?= $view->attributes($controlAttributes) ?>><?= $view->text($value) ?></textarea>
        <?php else: ?>
        <input class="mt-1 w-full rounded facet-field border px-3 py-2" value="<?= $view->attr($value) ?>" <?= $view->attributes($controlAttributes) ?>>
        <?php endif; ?>

        <p class="mt-1 text-sm facet-field-help" id="<?= $view->attr($helpId) ?>"><?= $view->text($field['help']) ?></p>

        <?php
        /*
         * The error slot. It stays in the document whether or not it has
         * anything to say: an element that already exists and is already
         * referenced is one the server fills without touching anything else.
         * Empty, it collapses and contributes nothing to the accessible
         * description.
         */
        ?>
        <p class="mt-1 text-sm facet-field-error" id="<?= $view->attr($errorId) ?>" data-facet-field-error><?= $view->text($error) ?></p>
    </div>
    <?php endforeach; ?>

    <?php
    /*
     * The decoy. A person never sees it — it is moved off-screen by the
     * stylesheet, hidden from assistive technology and removed from the tab
     * order — so a value in it did not come from a person filling in this
     * form. It is a real, labelled input rather than `type="hidden"` because
     * a hidden input is exactly what an indiscriminate submitter knows to
     * leave alone.
     *
     * Nothing about it can impair the form: no keyboard path reaches it, no
     * screen reader announces it, and if the stylesheet never arrives it
     * degrades to a visible field whose own label says to leave it empty.
     */
    ?>
    <div class="facet-nectar" aria-hidden="true">
        <label for="contact-<?= $view->attr($honeypotField) ?>">Leave this field empty</label>
        <input
            id="contact-<?= $view->attr($honeypotField) ?>"
            type="text"
            name="<?= $view->attr($honeypotField) ?>"
            value=""
            tabindex="-1"
            autocomplete="off"
        >
    </div>

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
