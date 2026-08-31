<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Contact;

use Facet\Contact\ContactValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator, asserted against the bounds the schema actually enforces.
 *
 * These tests exist because the failure they prevent is invisible until
 * production: a validator that is *looser* than its columns turns a typo into a
 * 500 from the driver, and one that is *tighter* rejects a legitimate message
 * for no stated reason. Every length case below is therefore checked at the
 * boundary and one past it, in characters, because `CHAR_LENGTH` counts
 * characters and so must this.
 *
 * Nothing here goes through a browser. That is the point: `required` and
 * `type="email"` are not part of the boundary, so the boundary is tested the
 * way an attacker meets it — a bare array of strings.
 */
final class ContactValidatorTest extends TestCase
{
    private static function validator(): ContactValidator
    {
        return new ContactValidator();
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function body(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'subject' => 'A note',
            'message' => 'Hello there.',
        ];
    }

    // ------------------------------------------------------------- nominal

    public function testANominalSubmissionIsAcceptedAndNormalised(): void
    {
        $validation = self::validator()->validate(self::body([
            'name' => "  Ada Lovelace \t",
            'email' => '  Ada@Example.COM ',
            'subject' => '  A note  ',
            'message' => "  Hello\r\nthere.  ",
        ]));

        self::assertTrue($validation->isValid());
        self::assertSame([], $validation->errors());

        $submission = $validation->submission();
        self::assertNotNull($submission);

        self::assertSame('Ada Lovelace', $submission->name());
        self::assertSame('ada@example.com', $submission->email(), 'The stored address is the canonical one');
        self::assertSame('A note', $submission->subject());
        self::assertSame("Hello\nthere.", $submission->message(), 'CRLF is normalised to LF');
    }

    /**
     * The values handed back for redisplay are the ones that were judged, so a
     * visitor is never shown one string and told another was rejected.
     */
    public function testTheValuesReturnedAreTheNormalisedOnesThatWereJudged(): void
    {
        $validation = self::validator()->validate(self::body(['subject' => '   ']));

        self::assertFalse($validation->isValid());
        self::assertSame('', $validation->values()['subject']);
        self::assertSame('Ada Lovelace', $validation->values()['name']);
        self::assertSame('ada@example.com', $validation->values()['email']);
    }

    // -------------------------------------------------------------- bounds

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function boundedFields(): array
    {
        return [
            'name' => ['name', ContactValidator::NAME_MAX],
            'subject' => ['subject', ContactValidator::SUBJECT_MAX],
            'message' => ['message', ContactValidator::MESSAGE_MAX],
        ];
    }

    #[DataProvider('boundedFields')]
    public function testAFieldIsAcceptedAtItsBoundAndRejectedOnePast(string $field, int $max): void
    {
        $atBound = self::validator()->validate(self::body([$field => str_repeat('a', $max)]));

        self::assertTrue($atBound->isValid(), $field . ' must be accepted at exactly ' . $max);

        $past = self::validator()->validate(self::body([$field => str_repeat('a', $max + 1)]));

        self::assertFalse($past->isValid(), $field . ' must be refused at ' . ($max + 1));
        self::assertArrayHasKey($field, $past->errors());

        // The verdict is a reason, not a sentence: what a visitor reads is
        // composed in the language of the page from the reason and the bound.
        // See Application::contactErrors().
        self::assertSame($field . '.tooLong', $past->error($field));
        self::assertSame($max, ContactValidator::MAX_LENGTHS[$field]);
    }

    /**
     * The bound is counted in characters, not bytes — the unit `CHAR_LENGTH`
     * uses. A name of 120 accented characters is 240 bytes and is legitimate.
     */
    public function testBoundsAreCountedInCharactersAndNotBytes(): void
    {
        $name = str_repeat('é', ContactValidator::NAME_MAX);

        self::assertSame(240, strlen($name), 'The fixture is genuinely multi-byte');

        self::assertTrue(self::validator()->validate(self::body(['name' => $name]))->isValid());
        self::assertFalse(
            self::validator()->validate(self::body(['name' => $name . 'é']))->isValid()
        );
    }

    #[DataProvider('boundedFields')]
    public function testAFieldIsRequiredWhateverItIsPaddedWith(string $field): void
    {
        foreach (['', '   ', "\t", "\n", "\r\n"] as $blank) {
            $validation = self::validator()->validate(self::body([$field => $blank]));

            self::assertFalse($validation->isValid(), $field . ' must not accept blank input');
            self::assertArrayHasKey($field, $validation->errors());
        }
    }

    /**
     * Absent keys are the case a browser cannot produce and `curl` produces by
     * default. They are the same failure as an empty string, never a notice.
     */
    public function testAnEntirelyAbsentBodyIsFourFieldErrorsAndNotAFailure(): void
    {
        $validation = self::validator()->validate([]);

        self::assertFalse($validation->isValid());
        self::assertSame(ContactValidator::FIELDS, array_keys($validation->errors()));
        self::assertNull($validation->submission());
    }

    // --------------------------------------------------------------- email

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedAddresses(): array
    {
        return [
            'no at' => ['ada.example.com'],
            'no domain' => ['ada@'],
            'no local part' => ['@example.com'],
            'spaces inside' => ['ada lovelace@example.com'],
            'two at signs' => ['ada@@example.com'],
            'no tld' => ['ada@example'],
            'header injection' => ["ada@example.com\r\nBcc: victim@example.com"],
            'over the column' => [str_repeat('a', 250) . '@example.com'],
        ];
    }

    #[DataProvider('malformedAddresses')]
    public function testAMalformedAddressIsRejected(string $email): void
    {
        $validation = self::validator()->validate(self::body(['email' => $email]));

        self::assertFalse($validation->isValid(), $email . ' must not be accepted');
        self::assertArrayHasKey('email', $validation->errors());
        self::assertNull($validation->submission());
    }

    // ------------------------------------------------------ control bytes

    /**
     * A newline in a single-line control is never something a person typed
     * into it, and it is the shape that becomes header injection the day these
     * messages are forwarded. It is removed before length is even measured.
     */
    public function testControlCharactersAreStrippedFromSingleLineFields(): void
    {
        $validation = self::validator()->validate(self::body([
            'name' => "Ada\r\nBcc: victim@example.com",
            'subject' => "A note\x00with a NUL",
        ]));

        self::assertTrue($validation->isValid());

        $submission = $validation->submission();
        self::assertNotNull($submission);

        self::assertStringNotContainsString("\n", $submission->name());
        self::assertStringNotContainsString("\r", $submission->name());
        self::assertStringNotContainsString("\x00", $submission->subject());
    }

    public function testTheMessageKeepsItsLineBreaksAndLosesEverythingElse(): void
    {
        $validation = self::validator()->validate(self::body([
            'message' => "One\nTwo\r\nThree\x07\x00",
        ]));

        $submission = $validation->submission();
        self::assertNotNull($submission);

        self::assertSame("One\nTwo\nThree", $submission->message());
    }

    /**
     * Bytes that are not UTF-8 are not text. Accepting them would hand the
     * `utf8mb4` column something the driver refuses, turning what should be a
     * validation failure into a 500.
     */
    public function testInvalidUtf8IsRejectedRatherThanPassedToTheColumn(): void
    {
        $validation = self::validator()->validate(self::body(['name' => "Ada \xC3\x28 Lovelace"]));

        self::assertFalse($validation->isValid());
        self::assertArrayHasKey('name', $validation->errors());
    }

    // ------------------------------------------------------------ payloads

    /**
     * The validator is not a sanitiser and must not pretend to be one. Markup
     * in a message is legitimate text — someone may well be writing to ask
     * about a `<script>` tag — so it is stored verbatim and made inert by
     * escaping at the point of output, which is the only place that knows the
     * context. What matters here is that it does not become a *rejection*
     * either, because a validator that silently strips is a validator whose
     * stored value differs from the one it showed the visitor.
     */
    public function testMarkupIsAcceptedVerbatimAndNeitherStrippedNorRejected(): void
    {
        $payload = '<script>alert("xss")</script>';

        $validation = self::validator()->validate(self::body([
            'name' => $payload,
            'subject' => $payload,
            'message' => $payload,
        ]));

        self::assertTrue($validation->isValid());

        $submission = $validation->submission();
        self::assertNotNull($submission);

        self::assertSame($payload, $submission->name());
        self::assertSame($payload, $submission->subject());
        self::assertSame($payload, $submission->message());
    }

    // ------------------------------------------------------ unknown fields

    /**
     * The stated policy for unexpected fields: they are ignored.
     *
     * Not "rejected" — a third party could then break the form for a visitor
     * by adding a parameter to a link — and not "accepted", which is mass
     * assignment. The validator reads the four names it knows and never
     * iterates the body, so an extra parameter cannot reach a column, cannot
     * be echoed back, and cannot change a verdict.
     */
    public function testUnexpectedFieldsAreIgnoredEntirely(): void
    {
        $validation = self::validator()->validate(self::body([
            'id' => '1',
            'status' => 'archived',
            'created_at' => '1999-01-01 00:00:00',
            'is_admin' => '1',
            '_token' => 'whatever',
            'website' => 'http://spam.example',
        ]));

        self::assertTrue($validation->isValid(), 'An extra field must not break a valid submission');

        self::assertSame(
            ContactValidator::FIELDS,
            array_keys($validation->values()),
            'Only the four known fields are carried forward'
        );

        $submission = $validation->submission();
        self::assertNotNull($submission);

        self::assertSame(ContactValidator::FIELDS, array_keys($submission->toArray()));
    }

    /**
     * And an unknown field cannot rescue a missing known one.
     */
    public function testAnUnknownFieldCannotStandInForAKnownOne(): void
    {
        $validation = self::validator()->validate([
            'Name' => 'Ada',
            'e-mail' => 'ada@example.com',
            'subject' => 'A note',
            'message' => 'Hello there.',
        ]);

        self::assertFalse($validation->isValid());
        self::assertSame(['name', 'email'], array_keys($validation->errors()));
    }
}
