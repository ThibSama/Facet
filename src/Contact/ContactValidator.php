<?php

declare(strict_types=1);

namespace Facet\Contact;

use Facet\Support\EmailAddress;

/**
 * The one server-side definition of an acceptable contact submission.
 *
 * Every bound below is the column's bound, stated in the same unit the column
 * counts in. `contact_messages` is `VARCHAR(120)`, `VARCHAR(254)`,
 * `VARCHAR(200)` and a `CHECK (CHAR_LENGTH(message) BETWEEN 1 AND 5000)`, and
 * MariaDB's `CHAR_LENGTH` counts characters, so the checks here use
 * `mb_strlen` and not `strlen`. Measuring bytes would reject a legitimate
 * accented name at 120 characters and — worse in the other direction — is the
 * kind of mismatch that turns a validation failure into a 500 from the driver.
 * The intended failure mode is that this class rejects everything the schema
 * would reject, so the schema's constraints are the second line and never the
 * first one a visitor meets.
 *
 * Nothing the browser did is trusted. `required` and `type="email"` are on the
 * form as conveniences for the person typing; a submission that arrives from
 * `curl` with neither is judged by exactly the same code, which is why the
 * tests post directly rather than through a browser.
 *
 * **Unexpected fields are ignored.** That is a stated policy, not an accident:
 * the validator reads the four names it knows and never iterates the request
 * body, so an extra parameter — a submit button's name, a browser extension's
 * addition, or an attacker's attempt at mass assignment — cannot reach a
 * column, cannot be echoed back into the page, and cannot change a verdict. The
 * alternative, rejecting the submission outright, would let a third party break
 * the form for a visitor by adding a parameter to a link.
 */
final class ContactValidator
{
    /** The fields this form has, in document order. */
    public const FIELDS = ['name', 'email', 'subject', 'message'];

    public const NAME_MAX = 120;
    public const EMAIL_MAX = EmailAddress::MAX_LENGTH;
    public const SUBJECT_MAX = 200;
    public const MESSAGE_MAX = 5000;

    /**
     * The bound each field is judged against, by field name.
     *
     * Stated as data so the presentation layer can name the number in whatever
     * language it is writing in — "at most 120 characters" is one sentence with
     * a value in it, not two sentences to keep in step.
     *
     * @var array<string, int>
     */
    public const MAX_LENGTHS = [
        'name' => self::NAME_MAX,
        'email' => self::EMAIL_MAX,
        'subject' => self::SUBJECT_MAX,
        'message' => self::MESSAGE_MAX,
    ];

    /**
     * Every reason this validator can refuse a field, exhaustively.
     *
     * The list is the contract between validation and presentation: each value
     * is a translation key suffix, and {@see \Facet\I18n\Translations} declares
     * `contact.error.<reason>` for every one of them in both languages. A
     * reason added here without its sentence is caught by the completeness
     * test rather than by a visitor.
     *
     * @var list<string>
     */
    public const REASONS = [
        'name.missing',
        'name.tooLong',
        'email.missing',
        'email.tooLong',
        'email.malformed',
        'subject.missing',
        'subject.tooLong',
        'message.missing',
        'message.tooLong',
    ];

    /**
     * Validate a request body.
     *
     * @param array<string, string> $body
     */
    public function validate(array $body): ContactValidation
    {
        $values = [
            'name' => $this->singleLine($body['name'] ?? ''),
            'email' => EmailAddress::normalise($this->singleLine($body['email'] ?? '')),
            'subject' => $this->singleLine($body['subject'] ?? ''),
            'message' => $this->multiLine($body['message'] ?? ''),
        ];

        $errors = [];

        // The verdict is a reason, never a sentence. Which field failed and
        // why is a server decision; what a visitor is told about it is a
        // presentation decision, and since PORT-137 it is one that depends on
        // the language the page is being rendered in. Keeping prose out of here
        // is what stops the contact form from needing a second, translated
        // copy of its own validation.
        if ($values['name'] === '') {
            $errors['name'] = 'name.missing';
        } elseif (mb_strlen($values['name']) > self::NAME_MAX) {
            $errors['name'] = 'name.tooLong';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'email.missing';
        } elseif (mb_strlen($values['email']) > self::EMAIL_MAX) {
            $errors['email'] = 'email.tooLong';
        } elseif (!EmailAddress::isValid($values['email'])) {
            $errors['email'] = 'email.malformed';
        }

        if ($values['subject'] === '') {
            $errors['subject'] = 'subject.missing';
        } elseif (mb_strlen($values['subject']) > self::SUBJECT_MAX) {
            $errors['subject'] = 'subject.tooLong';
        }

        if ($values['message'] === '') {
            $errors['message'] = 'message.missing';
        } elseif (mb_strlen($values['message']) > self::MESSAGE_MAX) {
            $errors['message'] = 'message.tooLong';
        }

        if ($errors !== []) {
            return ContactValidation::rejected($values, $errors);
        }

        return ContactValidation::accepted($values, new ContactSubmission(
            $values['name'],
            // Canonical rather than merely normalised: this is the exact form
            // the `email COLLATE utf8mb4_bin = LOWER(email)` CHECK demands.
            EmailAddress::canonical($values['email']),
            $values['subject'],
            $values['message']
        ));
    }

    /**
     * A value for a control that is one line by construction.
     *
     * Control characters are removed rather than escaped. A newline in a name
     * or a subject is never something a person meant to type into a single-line
     * input, and it is the payload shape that turns a stored value into header
     * injection the day these messages are forwarded by email. Removing it here
     * means no downstream consumer has to remember to.
     */
    private function singleLine(string $value): string
    {
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        if (!is_string($collapsed)) {
            // Invalid UTF-8 makes the pattern fail rather than match; such a
            // value is not text and is refused by being emptied.
            return '';
        }

        return $this->wellFormed(trim($collapsed));
    }

    /**
     * A value for the textarea: line breaks survive, everything else does not.
     *
     * Line endings are normalised to `\n` so a message written in a browser on
     * Windows and one written on Linux are stored identically, and so the 5000
     * character bound means the same thing for both.
     */
    private function multiLine(string $value): string
    {
        $normalised = preg_replace('/\r\n?/u', "\n", $value);

        if (!is_string($normalised)) {
            return '';
        }

        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $normalised);

        if (!is_string($stripped)) {
            return '';
        }

        return $this->wellFormed(trim($stripped));
    }

    /**
     * Reject text that is not valid UTF-8.
     *
     * The column is `utf8mb4`; handing MariaDB a byte sequence it cannot decode
     * is a driver-level error, which would surface as a 500 on a request that
     * should simply have been a validation failure.
     */
    private function wellFormed(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : '';
    }
}
