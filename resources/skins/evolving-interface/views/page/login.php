<?php

/**
 * evolving-interface — logical view "page.login".
 *
 * A plain server-side sign-in form: a real action, a real method, no
 * JavaScript. Two controls, a token, and nothing else — a login page is the one
 * page on a site where extra surface is never worth what it costs.
 *
 * Nothing here decides anything, and that is load-bearing rather than stylistic.
 * This document is not a security boundary: by the time it renders, the guard
 * has already established that the visitor is not signed in, and the handler
 * has already decided whether a submission identified anybody. There is no
 * branch below that shows or hides anything on the strength of a role, because
 * a template that hid a section from the wrong visitor would be hiding markup
 * from somebody whose request should never have got this far.
 *
 * The password is rendered with no `value`, in every state. A rejected form
 * redisplays the address so a typo can be fixed; a password put back into
 * markup is a credential written into the document, the browser's cache and
 * anything that stores a response body.
 *
 * The failure notice says one thing whatever went wrong. An unknown address, a
 * wrong password and a disabled account are the same sentence here because they
 * are the same sentence in the handler — the page could not tell them apart if
 * it wanted to.
 *
 * @var \Facet\Html\ViewContext $view
 * @var string                  $csrfField     name of the CSRF form field
 * @var string                  $csrfToken     this session's token
 * @var string                  $emailField    name of the address control
 * @var string                  $passwordField name of the password control
 * @var array<string, string>   $values        values to redisplay (never the password)
 * @var array{kind: string, text: string}|null $notice form-level statement
 */

declare(strict_types=1);

use Facet\Html\Html;

$title = 'Sign in';

$values = $values ?? [];
$notice = $notice ?? null;

$email = isset($values[$emailField]) && is_string($values[$emailField]) ? $values[$emailField] : '';

$noticeClass = ($notice['kind'] ?? '') === 'success' ? 'facet-notice--success' : 'facet-notice--error';

ob_start();

?>
<h1 class="text-3xl font-semibold tracking-tight">Sign in</h1>

<p id="login-status" class="mt-4 max-w-prose facet-ink-muted">
    This form is for the people who maintain this site and the clients it is shared with.
    There is nothing to sign up for, and nothing here that a visitor needs.
</p>

<?php if (is_array($notice) && isset($notice['text']) && $notice['text'] !== ''): ?>
<?php
/*
 * The form-level statement. `role="status"` with `aria-live="polite"` means a
 * screen reader announces the refusal without the visitor having to go looking
 * for it, and the element is placed before the form so the reading order
 * matches the announcement.
 */
?>
<p
    class="mt-6 max-w-md rounded facet-notice <?= $view->attr($noticeClass) ?> px-4 py-3"
    id="login-notice"
    role="status"
    aria-live="polite"
><?= $view->text($notice['text']) ?></p>
<?php endif; ?>

<form
    class="mt-8 max-w-md space-y-6"
    method="post"
    action="<?= $view->url('/login') ?>"
    aria-describedby="login-status"
>
    <?php
    /*
     * Proof that this submission was composed on this page, in this session.
     * A hidden input rather than a header, because the form must work with no
     * JavaScript at all — there is nothing here to set a header with.
     */
    ?>
    <input type="hidden" name="<?= $view->attr($csrfField) ?>" value="<?= $view->attr($csrfToken) ?>">

    <div>
        <label class="block text-sm font-medium" for="login-email">Email</label>
        <input
            class="mt-1 w-full rounded facet-field border px-3 py-2"
            id="login-email"
            type="email"
            name="<?= $view->attr($emailField) ?>"
            value="<?= $view->attr($email) ?>"
            required
            aria-required="true"
            aria-describedby="login-email-help"
            autocomplete="username"
            inputmode="email"
            spellcheck="false"
            autocapitalize="none"
            maxlength="254"
        >
        <p class="mt-1 text-sm facet-field-help" id="login-email-help">The address the account was created with.</p>
    </div>

    <div>
        <label class="block text-sm font-medium" for="login-password">Password</label>
        <?php
        /*
         * No `value` attribute, in any state. `autocomplete="current-password"`
         * so a password manager offers the stored one rather than treating this
         * as a new credential to save.
         */
        ?>
        <input
            class="mt-1 w-full rounded facet-field border px-3 py-2"
            id="login-password"
            type="password"
            name="<?= $view->attr($passwordField) ?>"
            required
            aria-required="true"
            aria-describedby="login-password-help"
            autocomplete="current-password"
            maxlength="200"
        >
        <p class="mt-1 text-sm facet-field-help" id="login-password-help">Nothing you type here is ever shown back to you.</p>
    </div>

    <p>
        <button class="rounded facet-button px-4 py-2" type="submit">Sign in</button>
    </p>
</form>
<?php

$content = Html::trusted((string) ob_get_clean());

require dirname(__DIR__) . '/layout.php';
