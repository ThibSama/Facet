<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Config\Config;
use Facet\Contact\ContactMessageStore;
use Facet\Contact\ContactValidator;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\Security\CsrfGuard;
use Facet\Security\RateLimiter;
use Facet\Session\ArraySession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\FrozenClock;
use Facet\Tests\Support\RecordingContactMessageStore;
use PHPUnit\Framework\TestCase;

/**
 * POST /contact, branch by branch, with the row count asserted every time.
 *
 * The application is driven directly rather than over HTTP so that every path
 * — including the ones a browser will not produce, like a submission with no
 * token at all — is reachable, and so that time is a value the test controls.
 * The same journey is proved over real HTTP with real cookies in
 * {@see ContactHttpFlowTest}, and against real MariaDB in
 * {@see \Facet\Tests\Database\ContactMessagePersistenceTest}; this file is where
 * the *decisions* are pinned down.
 *
 * Every test asserts a count, not just a status. "It returned 403" and "it
 * returned 403 and stored nothing" are different claims, and only the second
 * one is the security property.
 */
final class ContactSubmissionTest extends TestCase
{
    /** A well-formed submission, used wherever the content is not the subject. */
    private const VALID = [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'subject' => 'About the analytical engine',
        'message' => 'I would like to discuss a collaboration.',
    ];

    private ArraySession $session;

    private RecordingContactMessageStore $store;

    private FrozenClock $clock;

    private Application $app;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->store = new RecordingContactMessageStore();
        $this->clock = new FrozenClock();
        $this->app = self::boot($this->session, $this->store, $this->clock);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function boot(
        ArraySession $session,
        ContactMessageStore $store,
        FrozenClock $clock
    ): Application {
        return Application::boot(
            self::root(),
            // Production, because the disclosure rules are strictest there and
            // a failure branch must be safe in the configuration that ships.
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'contact-submission-test-key',
                'APP_LOCALE' => 'en',
                'APP_DEBUG' => 'false',
            ]),
            null,
            null,
            null,
            $session,
            $store,
            $clock
        );
    }

    // ----------------------------------------------------------- helpers

    private function get(): Response
    {
        return $this->app->handle(Request::create('GET', '/contact'));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body): Response
    {
        return $this->app->handle(Request::create('POST', '/contact', [], $body));
    }

    /**
     * The token the page is currently serving, read out of the rendered form
     * exactly as a browser would take it.
     */
    private function tokenFromPage(?Response $page = null): string
    {
        $page ??= $this->get();

        $input = Dom::element(
            Dom::of(Dom::withoutScripts($page->body())),
            '//main//form//input[@name="' . CsrfGuard::FIELD . '"]',
            'The form must carry exactly one CSRF field'
        );

        self::assertSame('hidden', $input->getAttribute('type'));

        $token = $input->getAttribute('value');
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function validBody(array $overrides = []): array
    {
        return $overrides + [CsrfGuard::FIELD => $this->tokenFromPage()] + self::VALID;
    }

    // ------------------------------------------------------------- CSRF

    public function testGetEmitsAHiddenTokenTiedToTheSession(): void
    {
        $token = $this->tokenFromPage();

        self::assertSame($token, $this->session->get(CsrfGuard::SESSION_KEY));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    /**
     * The token is not in the URL, not in a cookie of its own, and not
     * anywhere a cross-origin document could read it back.
     */
    public function testTheTokenIsNotLeakedIntoAUrl(): void
    {
        $body = $this->get()->body();
        $token = $this->tokenFromPage();

        self::assertStringNotContainsString('?' . CsrfGuard::FIELD, $body);
        self::assertStringNotContainsString('/contact?', $body);

        foreach (Dom::attributes(Dom::of($body), '//a[@href]', 'href') as $href) {
            self::assertStringNotContainsString($token, $href);
        }
    }

    /**
     * The four ways a submission can fail to prove intent, all deterministic,
     * all 403, all zero inserts.
     */
    public function testEveryUnprovenSubmissionIsA403WithNoInsert(): void
    {
        $issued = $this->tokenFromPage();

        $cases = [
            'no token field at all' => self::VALID,
            'empty token' => [CsrfGuard::FIELD => ''] + self::VALID,
            'wrong token' => [CsrfGuard::FIELD => str_repeat('0', 64)] + self::VALID,
            'truncated token' => [CsrfGuard::FIELD => substr($issued, 0, 32)] + self::VALID,
            'token from another session' => [
                CsrfGuard::FIELD => (new CsrfGuard())->token(new ArraySession()),
            ] + self::VALID,
        ];

        foreach ($cases as $why => $body) {
            $response = $this->post($body);

            self::assertSame(Response::STATUS_FORBIDDEN, $response->status(), $why);
            self::assertSame(0, $this->store->count(), $why . ' must store nothing');
            self::assertFalse($response->isRedirect(), $why . ' must not look like a success');
        }

        // Deterministic: the same refusal, twice, with the same answer.
        self::assertSame($this->post(self::VALID)->status(), $this->post(self::VALID)->status());
        self::assertSame(0, $this->store->count());
    }

    /**
     * A refusal must not become a disclosure, and must not tell an attacker
     * which half of the check they failed.
     */
    public function testARefusedSubmissionDisclosesNothing(): void
    {
        $body = $this->post(self::VALID)->body();

        foreach ([
            self::root(),
            'contact-submission-test-key',
            'CSRF',
            'token',
            'Stack trace',
            'Exception',
            'SQLSTATE',
        ] as $leak) {
            self::assertStringNotContainsString($leak, $body, 'A 403 leaked ' . $leak);
        }
    }

    public function testAValidTokenIsAcceptedAndRotatedAfterwards(): void
    {
        $token = $this->tokenFromPage();

        $response = $this->post([CsrfGuard::FIELD => $token] + self::VALID);

        self::assertSame(Response::STATUS_SEE_OTHER, $response->status());
        self::assertSame(1, $this->store->count());

        $rotated = $this->session->get(CsrfGuard::SESSION_KEY);

        self::assertNotSame($token, $rotated, 'The token that authorised an insert must not survive it');
        self::assertSame($rotated, $this->tokenFromPage(), 'The next page serves the new token');
    }

    /**
     * Replay: the exact bytes that authorised one insert cannot authorise a
     * second. This is the property token rotation exists for.
     */
    public function testAnAcceptedTokenCannotBeReplayed(): void
    {
        $token = $this->tokenFromPage();

        self::assertSame(Response::STATUS_SEE_OTHER, $this->post([CsrfGuard::FIELD => $token] + self::VALID)->status());

        $replay = $this->post([CsrfGuard::FIELD => $token] + self::VALID);

        self::assertSame(Response::STATUS_FORBIDDEN, $replay->status());
        self::assertSame(1, $this->store->count(), 'A replayed token must not add a second row');
    }

    /**
     * Rotation is available on demand, not only as a side effect of success —
     * the property a later checkpoint needs when it rotates on privilege
     * change.
     */
    public function testATokenCanBeDeliberatelyRotatedAndTheOldOneStopsWorking(): void
    {
        $original = $this->tokenFromPage();

        $rotated = (new CsrfGuard())->rotate($this->session);

        self::assertNotSame($original, $rotated);
        self::assertSame($rotated, $this->tokenFromPage(), 'The form serves the rotated token');

        self::assertSame(Response::STATUS_FORBIDDEN, $this->post([CsrfGuard::FIELD => $original] + self::VALID)->status());
        self::assertSame(0, $this->store->count());

        self::assertSame(Response::STATUS_SEE_OTHER, $this->post([CsrfGuard::FIELD => $rotated] + self::VALID)->status());
        self::assertSame(1, $this->store->count());
    }

    /**
     * A session that was never issued a token — the shape a bare `curl` POST
     * arrives in — is refused rather than treated as "nothing to compare".
     */
    public function testASessionThatWasNeverIssuedATokenIsRefused(): void
    {
        $fresh = self::boot(new ArraySession(), $this->store, $this->clock);

        $response = $fresh->handle(Request::create('POST', '/contact', [], [
            CsrfGuard::FIELD => str_repeat('a', 64),
        ] + self::VALID));

        self::assertSame(Response::STATUS_FORBIDDEN, $response->status());
        self::assertSame(0, $this->store->count());
    }

    // ------------------------------------------------------- validation

    public function testAnInvalidSubmissionIsRedisplayedWithFieldErrorsAndStoresNothing(): void
    {
        $response = $this->post($this->validBody([
            'name' => '',
            'email' => 'not-an-address',
            'subject' => str_repeat('s', ContactValidator::SUBJECT_MAX + 1),
            'message' => '   ',
        ]));

        self::assertSame(Response::STATUS_UNPROCESSABLE_CONTENT, $response->status());
        self::assertSame(0, $this->store->count());
        self::assertFalse($response->isRedirect(), 'A rejection must not redirect: there is nothing to redirect to');

        $xpath = Dom::of(Dom::withoutScripts($response->body()));

        foreach (ContactValidator::FIELDS as $field) {
            $error = Dom::element($xpath, sprintf('//main//*[@id="contact-%s-error"]', $field));

            self::assertNotSame('', Dom::textOf($error), $field . ' must say what was wrong with it');

            $control = Dom::element($xpath, sprintf('//main//form//*[@name="%s"]', $field));

            self::assertSame('true', $control->getAttribute('aria-invalid'), $field);
            self::assertSame(
                'contact-' . $field . '-help contact-' . $field . '-error',
                $control->getAttribute('aria-describedby'),
                $field . ' must still describe itself with the same stable ids'
            );
        }
    }

    /**
     * A rejected form comes back filled in. Retyping a message because the
     * subject was one character too long is the fastest way to lose it.
     */
    public function testARejectedFormComesBackWithTheSubmittedValues(): void
    {
        $response = $this->post($this->validBody(['email' => 'not-an-address']));

        $xpath = Dom::of(Dom::withoutScripts($response->body()));

        self::assertSame(
            self::VALID['name'],
            Dom::element($xpath, '//main//form//input[@name="name"]')->getAttribute('value')
        );
        self::assertSame(
            'not-an-address',
            Dom::element($xpath, '//main//form//input[@name="email"]')->getAttribute('value')
        );
        self::assertSame(
            self::VALID['message'],
            Dom::textOf(Dom::element($xpath, '//main//form//textarea[@name="message"]'))
        );
    }

    /**
     * The form that comes back is submittable. A rejection that also destroys
     * the token turns one mistake into a 403 on the correction.
     */
    public function testTheRedisplayedFormStillCarriesAWorkingToken(): void
    {
        $rejected = $this->post($this->validBody(['email' => 'nope']));

        self::assertSame(Response::STATUS_UNPROCESSABLE_CONTENT, $rejected->status());

        $corrected = $this->post([CsrfGuard::FIELD => $this->tokenFromPage($rejected)] + self::VALID);

        self::assertSame(Response::STATUS_SEE_OTHER, $corrected->status());
        self::assertSame(1, $this->store->count());
    }

    /**
     * Native constraints are not the boundary. A submission with none of the
     * things a browser would have enforced is judged by the server alone.
     */
    public function testNativeConstraintsAreNotWhatDecides(): void
    {
        $response = $this->post([CsrfGuard::FIELD => $this->tokenFromPage()]);

        self::assertSame(Response::STATUS_UNPROCESSABLE_CONTENT, $response->status());
        self::assertSame(0, $this->store->count());
    }

    // -------------------------------------------------------------- XSS

    /**
     * Hostile input is reflected inert, in every context it reaches.
     *
     * The value goes into an attribute for the inputs and into element text for
     * the textarea, which are different escaping contexts and are therefore
     * asserted separately. What is checked is not "the payload is absent" but
     * "the payload is present and is text" — a page that silently dropped the
     * value would pass a naive absence check while quietly losing the message.
     */
    public function testHostileValuesAreReflectedInertAndNotExecuted(): void
    {
        $payload = '<script>alert("xss")</script>';
        $breakout = '"><script>alert(1)</script>';

        $response = $this->post($this->validBody([
            'name' => $breakout,
            'email' => $payload,
            'subject' => $payload,
            'message' => $payload,
        ]));

        self::assertSame(Response::STATUS_UNPROCESSABLE_CONTENT, $response->status());
        self::assertSame(0, $this->store->count());

        $html = $response->body();

        self::assertStringNotContainsString('<script>alert', $html, 'No payload may survive as markup');
        self::assertStringNotContainsString('"><script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html, 'It must survive as text, not vanish');

        // And the document really does parse with no script element the server
        // did not put there, and the value really did come back.
        $xpath = Dom::of($html);

        self::assertSame(
            $breakout,
            Dom::element($xpath, '//main//form//input[@name="name"]')->getAttribute('value')
        );
        self::assertSame(
            $payload,
            Dom::textOf(Dom::element($xpath, '//main//form//textarea[@name="message"]'))
        );
        self::assertSame(0, Dom::query($xpath, '//main//script')->length);
    }

    /**
     * The same payload on the path that succeeds: it is stored verbatim and
     * printed inert. Escaping belongs at output, where the context is known,
     * not at storage, where it is not.
     */
    public function testAStoredHostileMessageIsKeptVerbatimAndPrintedInert(): void
    {
        $payload = '<img src=x onerror=alert(1)>';

        $response = $this->post($this->validBody(['message' => $payload]));

        self::assertSame(Response::STATUS_SEE_OTHER, $response->status());
        self::assertSame(1, $this->store->count());

        $stored = $this->store->last();
        self::assertNotNull($stored);
        self::assertSame($payload, $stored['message'], 'The store keeps what was written');

        $landing = $this->get();

        self::assertStringNotContainsString('onerror=', $landing->body());
    }

    // --------------------------------------------------- unknown fields

    /**
     * The stated policy, at the HTTP boundary: unexpected fields are ignored.
     * They cannot reach a column, cannot flip a verdict, and cannot appear in
     * the page.
     */
    public function testUnexpectedFieldsAreIgnoredAndNeverStoredOrReflected(): void
    {
        $response = $this->post($this->validBody([
            'id' => '999',
            'status' => 'archived',
            'created_at' => '1999-01-01 00:00:00',
            'is_admin' => '1',
            'surprise' => '<b>unexpected</b>',
        ]));

        self::assertSame(Response::STATUS_SEE_OTHER, $response->status());
        self::assertSame(1, $this->store->count());

        $stored = $this->store->last();
        self::assertNotNull($stored);

        self::assertSame(ContactValidator::FIELDS, array_keys($stored));
        self::assertSame(self::VALID['name'], $stored['name']);

        self::assertStringNotContainsString('unexpected', $this->get()->body());
    }

    // -------------------------------------------------------- honeypot

    public function testTheFormCarriesAHoneypotThatCostsNobodyAnything(): void
    {
        $xpath = Dom::of(Dom::withoutScripts($this->get()->body()));

        $wrapper = Dom::element($xpath, '//main//form//*[@aria-hidden="true"]');

        self::assertSame('true', $wrapper->getAttribute('aria-hidden'), 'Assistive technology must skip it');

        $decoy = Dom::element($xpath, '//main//form//*[@aria-hidden="true"]//input');

        self::assertSame('-1', $decoy->getAttribute('tabindex'), 'The keyboard path must not land on it');
        self::assertSame('off', $decoy->getAttribute('autocomplete'));
        self::assertSame('', $decoy->getAttribute('value'), 'It ships empty');
        self::assertNotSame('hidden', $decoy->getAttribute('type'), 'A hidden input is the one thing a bot skips');
        self::assertFalse($decoy->hasAttribute('required'), 'It must never block a real submission');

        // It degrades honestly: without the stylesheet it is a labelled field
        // whose own label says what to do with it.
        $label = Dom::element($xpath, sprintf('//main//form//label[@for="%s"]', $decoy->getAttribute('id')));

        self::assertStringContainsStringIgnoringCase('empty', Dom::textOf($label));

        // And the real fields are untouched by its presence.
        self::assertSame(
            ContactValidator::FIELDS,
            Dom::attributes(
                $xpath,
                '//main//form//*[not(ancestor-or-self::*[@aria-hidden="true"])][@name][not(@type="hidden")]',
                'name'
            )
        );
    }

    /**
     * A filled honeypot is answered exactly as a success is, and stores
     * nothing. The indistinguishability is the mechanism: a bot that could
     * tell would stop filling the field in.
     */
    public function testAFilledHoneypotStoresNothingAndRevealsNoDetection(): void
    {
        $genuine = $this->post($this->validBody());

        self::assertSame(1, $this->store->count());
        $this->get(); // consume the flash, so both journeys start level

        $trapped = $this->post($this->validBody(['website' => 'http://spam.example']));

        self::assertSame(1, $this->store->count(), 'A trapped submission must not be stored');

        self::assertSame($genuine->status(), $trapped->status());
        self::assertSame($genuine->header('Location'), $trapped->header('Location'));
        self::assertSame($genuine->body(), $trapped->body());

        // And the page it lands on says the same thing, so the difference is
        // not visible one step later either.
        self::assertStringContainsString('has been received', $this->get()->body());
    }

    /**
     * Whitespace in the decoy is not a fill. A browser that autofills a space
     * must not silently discard a real person's message.
     */
    public function testAnEmptyOrBlankHoneypotIsNotTreatedAsATrap(): void
    {
        foreach (['', ' ', "\t"] as $blank) {
            $this->post($this->validBody(['website' => $blank]));
        }

        self::assertSame(3, $this->store->count());
    }

    // -------------------------------------------------------- throttle

    public function testSubmissionsAreBoundedAndTheBoundIsReachable(): void
    {
        for ($i = 1; $i <= RateLimiter::DEFAULT_LIMIT; $i++) {
            self::assertSame(
                Response::STATUS_SEE_OTHER,
                $this->post($this->validBody())->status(),
                'Submission ' . $i . ' is within the allowance'
            );
        }

        self::assertSame(RateLimiter::DEFAULT_LIMIT, $this->store->count());

        $refused = $this->post($this->validBody());

        self::assertSame(Response::STATUS_TOO_MANY_REQUESTS, $refused->status());
        self::assertSame(RateLimiter::DEFAULT_LIMIT, $this->store->count(), 'A throttled submission stores nothing');
        self::assertFalse($refused->isRedirect(), 'A throttled submission must not claim success');

        // The visitor is told, and keeps what they typed.
        $xpath = Dom::of(Dom::withoutScripts($refused->body()));

        self::assertNotSame('', Dom::textOf(Dom::element($xpath, '//main//*[@id="contact-notice"]')));
        self::assertSame(
            self::VALID['name'],
            Dom::element($xpath, '//main//form//input[@name="name"]')->getAttribute('value')
        );

        // Bounded, not a lockout: the window really does elapse.
        $this->clock->advance(RateLimiter::DEFAULT_WINDOW + 1);

        self::assertSame(Response::STATUS_SEE_OTHER, $this->post($this->validBody())->status());
        self::assertSame(RateLimiter::DEFAULT_LIMIT + 1, $this->store->count());
    }

    /**
     * Deterministic: the same state gives the same answer, and the throttle is
     * evaluated after proof of intent so an unauthenticated flood cannot spend
     * a visitor's allowance for them.
     */
    public function testThrottlingIsDeterministicAndSitsBehindTheTokenCheck(): void
    {
        for ($i = 0; $i < 50; $i++) {
            self::assertSame(Response::STATUS_FORBIDDEN, $this->post(self::VALID)->status());
        }

        self::assertSame(0, $this->store->count());

        // The allowance was never touched by any of that.
        for ($i = 1; $i <= RateLimiter::DEFAULT_LIMIT; $i++) {
            self::assertSame(Response::STATUS_SEE_OTHER, $this->post($this->validBody())->status());
        }

        self::assertSame(RateLimiter::DEFAULT_LIMIT, $this->store->count());
    }

    // ------------------------------------------------------------- PRG

    public function testASuccessfulSubmissionRedirectsAndTheLandingPageConfirms(): void
    {
        $response = $this->post($this->validBody());

        self::assertSame(Response::STATUS_SEE_OTHER, $response->status(), '303, so the redirected request is a GET');
        self::assertSame('/contact', $response->header('Location'));
        self::assertSame('', $response->body());
        self::assertSame(1, $this->store->count());

        $landing = $this->get();

        self::assertSame(200, $landing->status());

        $notice = Dom::element(Dom::of(Dom::withoutScripts($landing->body())), '//main//*[@id="contact-notice"]');

        self::assertStringContainsString('has been received', Dom::textOf($notice));
        self::assertSame('status', $notice->getAttribute('role'), 'The confirmation must be announced');
    }

    /**
     * The confirmation claims receipt and storage, and nothing beyond it.
     *
     * This is the honesty rule the previous checkpoint enforced on a form that
     * did nothing, carried forward to a form that does something. The
     * application receives and stores a message; it does not email, forward or
     * acknowledge one, so a confirmation that says "I'll get back to you" would
     * be promising behaviour no code performs.
     */
    public function testTheConfirmationClaimsReceiptAndNotDelivery(): void
    {
        $this->post($this->validBody());

        $text = mb_strtolower(Dom::textOf(Dom::element(Dom::of(Dom::withoutScripts($this->get()->body())), '//main')));

        self::assertStringContainsString('received', $text);
        self::assertStringContainsString('stored', $text);

        foreach ([
            'will reach me',
            'i will get back',
            'i will reply',
            'i will respond',
            'delivered',
            'sent to me',
            'emailed',
            'forwarded to me',
            'expect a reply',
        ] as $claim) {
            self::assertStringNotContainsString($claim, $text, 'The confirmation must not promise ' . $claim);
        }
    }

    /**
     * The whole point of PRG: refreshing the page you land on cannot submit
     * again, and the confirmation does not persist into a page that has
     * nothing to confirm.
     */
    public function testRefreshingTheLandingPageNeitherInsertsNorReconfirms(): void
    {
        $this->post($this->validBody());

        self::assertStringContainsString('has been received', $this->get()->body());

        for ($i = 0; $i < 5; $i++) {
            $refresh = $this->get();

            self::assertSame(200, $refresh->status());
            self::assertStringNotContainsString('has been received', $refresh->body(), 'Refresh ' . $i);
        }

        self::assertSame(1, $this->store->count(), 'No refresh may add a row');
    }

    /**
     * The confirmation is session state, not a URL the visitor can type.
     * `?sent=1` would let anyone manufacture a confirmation for a message that
     * was never stored.
     */
    public function testTheConfirmationCannotBeForgedFromTheUrl(): void
    {
        foreach ([
            ['sent' => '1'],
            ['success' => 'true'],
            ['contact' => 'sent'],
            ['flash' => 'sent'],
        ] as $query) {
            $response = $this->app->handle(Request::create(
                'GET',
                '/contact?' . http_build_query($query),
                $query
            ));

            self::assertSame(200, $response->status());
            self::assertStringNotContainsString('has been received', $response->body(), (string) key($query));
        }

        // And a genuine success does not put one there either.
        self::assertSame('/contact', $this->post($this->validBody())->header('Location'));
        self::assertSame(0, substr_count((string) $this->post($this->validBody())->header('Location'), '?'));
    }

    // ------------------------------------------------------ store failure

    /**
     * A database that will not take the row. The visitor must be told plainly
     * that the message was not received — the only outcome worse than losing a
     * message is claiming to have kept it.
     */
    public function testAFailedWriteIsASafeFailureWithNoConfirmationAndNoRow(): void
    {
        $store = RecordingContactMessageStore::failing();
        $session = new ArraySession();
        $app = self::boot($session, $store, $this->clock);

        $page = $app->handle(Request::create('GET', '/contact'));
        $token = $this->tokenFromPage($page);

        $response = $app->handle(Request::create('POST', '/contact', [], [CsrfGuard::FIELD => $token] + self::VALID));

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->status());
        self::assertSame(0, $store->count(), 'Nothing partial may be left behind');
        self::assertFalse($response->isRedirect(), 'A failure must not use the success path');

        $xpath = Dom::of(Dom::withoutScripts($response->body()));
        $notice = Dom::textOf(Dom::element($xpath, '//main//*[@id="contact-notice"]'));

        self::assertStringContainsString('not been received', $notice, 'The visitor is told the truth');
        self::assertStringNotContainsString('has been received', $response->body());

        // What they wrote is still on the page, so it is not lost.
        self::assertSame(
            self::VALID['message'],
            Dom::textOf(Dom::element($xpath, '//main//form//textarea[@name="message"]'))
        );

        // And the failure discloses nothing about the cause.
        foreach ([
            self::root(),
            'SQLSTATE',
            'PDO',
            'ContactStoreException',
            'Stack trace',
            'INSERT INTO',
            'contact-submission-test-key',
        ] as $leak) {
            self::assertStringNotContainsString($leak, $response->body(), 'The failure page leaked ' . $leak);
        }

        // No flash was set: the next GET is an ordinary form, not a stale
        // confirmation.
        self::assertStringNotContainsString(
            'has been received',
            $app->handle(Request::create('GET', '/contact'))->body()
        );
    }

    /**
     * With no database configured at all — the shape a fresh checkout deploys
     * in — a submission fails safely rather than 500ing out of the framework.
     */
    public function testASubmissionWithNoStoreConfiguredFailsSafely(): void
    {
        $session = new ArraySession();

        $app = Application::boot(
            self::root(),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'no-store-key',
                'APP_LOCALE' => 'en',
            ]),
            null,
            null,
            null,
            $session
        );

        $token = $this->tokenFromPage($app->handle(Request::create('GET', '/contact')));

        $response = $app->handle(Request::create('POST', '/contact', [], [CsrfGuard::FIELD => $token] + self::VALID));

        self::assertSame(Response::STATUS_INTERNAL_SERVER_ERROR, $response->status());
        self::assertStringNotContainsString('DB_DSN', $response->body());
        self::assertStringNotContainsString('has been received', $response->body());
    }
}
