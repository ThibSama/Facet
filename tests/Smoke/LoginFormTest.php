<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Account\AccountStatus;
use Facet\Account\Role;
use Facet\Auth\Authenticator;
use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\InMemoryAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * The sign-in page: the document it serves, and what it does with a submission.
 *
 * The form is asserted against the DOM the server produced with scripts
 * stripped, because a login form that needs JavaScript is a login form that
 * fails closed for exactly the people least able to work around it. Everything
 * a submission needs — the controls, their names, the token — has to be in the
 * markup itself.
 *
 * The behavioural half is about what the page is allowed to say. One sentence
 * for every failure, and never the password back.
 */
final class LoginFormTest extends TestCase
{
    private const EMAIL = 'ada@example.com';
    private const PASSWORD = 'a sufficiently long password';

    private InMemoryAccountRepository $accounts;

    private ArraySession $session;

    private Application $app;

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->session = new ArraySession();
        $this->app = Application::boot(
            self::root(),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'login-form-key',
                'APP_LOCALE' => 'en',
            ]),
            null,
            null,
            null,
            $this->session,
            null,
            null,
            $this->accounts
        );
    }

    private function token(): string
    {
        return (new CsrfGuard())->token($this->session);
    }

    /** @param array<string, string> $body */
    private function submit(array $body): Response
    {
        return $this->app->handle(Request::create('POST', '/login', [], $body));
    }

    private function page(): string
    {
        $response = $this->app->handle(Request::create('GET', '/login'));

        self::assertSame(200, $response->status());

        return Dom::withoutScripts($response->body());
    }

    // ------------------------------------------------------- the document

    public function testTheFormIsCompleteAndUsableWithNoJavaScript(): void
    {
        $xpath = Dom::of($this->page());

        $form = Dom::element($xpath, '//main//form');

        self::assertSame('post', strtolower($form->getAttribute('method')));
        self::assertSame('/login', $form->getAttribute('action'));

        $email = Dom::element($xpath, '//main//form//input[@name="email"]');
        self::assertSame('email', $email->getAttribute('type'));
        self::assertSame('username', $email->getAttribute('autocomplete'));

        $password = Dom::element($xpath, '//main//form//input[@name="password"]');
        self::assertSame('password', $password->getAttribute('type'));
        self::assertSame('current-password', $password->getAttribute('autocomplete'));

        // Both controls are labelled to a reader, not merely placeheld.
        foreach (['login-email' => 'Email', 'login-password' => 'Password'] as $id => $label) {
            self::assertSame(
                $label,
                Dom::textOf(Dom::element($xpath, sprintf('//main//form//label[@for="%s"]', $id)))
            );
        }

        Dom::element($xpath, '//main//form//button[@type="submit"]');

        $token = Dom::element($xpath, '//main//form//input[@name="_token"]')->getAttribute('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, 'The token must survive script stripping');
    }

    /**
     * The page must not name an account, a role or an address it has not been
     * given — a sign-in form is a page an unauthenticated stranger reads.
     */
    public function testTheFormDisclosesNothingAboutWhoHasAnAccount(): void
    {
        $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);

        $page = $this->page();

        // Ordinary words like "client" appear in the page's own prose, so the
        // needles are the things only a leak could put there: a stored address,
        // a column name, a query.
        foreach ([self::EMAIL, 'password_hash', 'SELECT', 'FROM users', 'auth.account'] as $leak) {
            self::assertStringNotContainsString($leak, $page, 'leaked ' . $leak);
        }

        // And nothing in the markup is keyed by a role.
        self::assertStringNotContainsString('data-role', $page);
    }

    // ------------------------------------------------------ the submission

    public function testValidCredentialsSignInAndLandOnTheRoleArea(): void
    {
        foreach ([[Role::Admin, '/admin'], [Role::Client, '/client']] as [$role, $expected]) {
            $this->setUp();

            $account = $this->accounts->add(self::EMAIL, self::PASSWORD, $role);

            $response = $this->submit([
                '_token' => $this->token(),
                'email' => self::EMAIL,
                'password' => self::PASSWORD,
            ]);

            self::assertSame(Response::STATUS_SEE_OTHER, $response->status(), $role->value);
            self::assertSame($expected, $response->header('Location'), $role->value);
            self::assertSame((string) $account->id(), $this->session->get(Authenticator::SESSION_KEY));
            self::assertSame(1, $this->session->regenerations(), 'Login must re-key the session');
        }
    }

    /**
     * The address a person typed is matched the way it was stored.
     */
    public function testTheSubmittedAddressIsCanonicalised(): void
    {
        $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);

        $response = $this->submit([
            '_token' => $this->token(),
            'email' => '  Ada@Example.COM  ',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin', $response->header('Location'));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function refusals(): array
    {
        return [
            'unknown address' => ['nobody@example.com', self::PASSWORD, false],
            'wrong password' => [self::EMAIL, 'not the password', false],
            'blank password' => [self::EMAIL, '', false],
            'malformed address' => ['not-an-address', self::PASSWORD, false],
            'disabled account' => ['grace@example.com', self::PASSWORD, true],
        ];
    }

    /**
     * Every refusal is the same status, the same sentence and the same
     * unauthenticated session. Asserted case by case *and* compared to each
     * other below, because "all generic" is a property of the set.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testEveryRefusalLooksIdentical(string $email, string $password, bool $disabled): void
    {
        $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);
        $this->accounts->add('grace@example.com', self::PASSWORD, Role::Client, AccountStatus::Disabled);

        $response = $this->submit(['_token' => $this->token(), 'email' => $email, 'password' => $password]);

        self::assertSame(Response::STATUS_UNPROCESSABLE_CONTENT, $response->status());
        self::assertFalse($this->session->has(Authenticator::SESSION_KEY));
        self::assertSame(0, $this->session->regenerations(), 'A refused attempt must not re-key anything');

        $notice = Dom::textOf(Dom::element(
            Dom::of(Dom::withoutScripts($response->body())),
            '//main//*[@id="login-notice"]'
        ));

        self::assertStringContainsString('did not match', $notice);

        // Nothing in the answer distinguishes the reason it failed.
        foreach (['disabled', 'unknown', 'no such', 'incorrect password', 'not found'] as $tell) {
            self::assertStringNotContainsStringIgnoringCase($tell, $notice, $disabled ? 'disabled' : 'other');
        }
    }

    public function testAllRefusalsProduceByteIdenticalNotices(): void
    {
        $notices = [];

        foreach (self::refusals() as $label => [$email, $password]) {
            $this->setUp();
            $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);
            $this->accounts->add('grace@example.com', self::PASSWORD, Role::Client, AccountStatus::Disabled);

            $response = $this->submit(['_token' => $this->token(), 'email' => $email, 'password' => $password]);

            $notices[$label] = Dom::textOf(Dom::element(
                Dom::of(Dom::withoutScripts($response->body())),
                '//main//*[@id="login-notice"]'
            ));
        }

        self::assertCount(1, array_unique(array_values($notices)), 'Refusals must be indistinguishable');
    }

    /**
     * The one thing a rejected login page must never contain.
     */
    public function testARejectedSubmissionNeverRedisplaysThePassword(): void
    {
        $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);

        $secret = 'this-exact-string-was-typed-as-a-password';

        $response = $this->submit(['_token' => $this->token(), 'email' => self::EMAIL, 'password' => $secret]);

        self::assertSame(422, $response->status());
        self::assertStringNotContainsString($secret, $response->body());

        $xpath = Dom::of(Dom::withoutScripts($response->body()));

        // The address does come back, so a typo can be corrected.
        self::assertSame(self::EMAIL, Dom::element($xpath, '//main//form//input[@name="email"]')->getAttribute('value'));

        // The password control has no value attribute at all — not an empty
        // one that a later edit could start filling in.
        self::assertFalse(
            Dom::element($xpath, '//main//form//input[@name="password"]')->hasAttribute('value'),
            'A password control must not carry a value attribute'
        );
    }

    /**
     * The refusal is carried by the form's description, not by a live region.
     *
     * This page arrives as a whole new document, so the notice is already in
     * the markup before any assistive technology begins watching it: there is
     * no later change for `aria-live` to announce, and a region that never
     * fires is a promise the page does not keep. Naming the notice in the
     * form's `aria-describedby` is what actually reaches someone who moves
     * straight to a control, which is exactly what a refused sign-in invites.
     */
    public function testTheRefusalIsDescribedByTheFormRatherThanAnnouncedByALiveRegion(): void
    {
        $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);

        $rejected = $this->submit(['_token' => $this->token(), 'email' => self::EMAIL, 'password' => 'wrong']);

        $xpath = Dom::of(Dom::withoutScripts($rejected->body()));

        $notice = Dom::element($xpath, '//main//*[@id="login-notice"]');

        self::assertFalse($notice->hasAttribute('role'), 'A notice present at load is not a live region');
        self::assertFalse($notice->hasAttribute('aria-live'), 'A notice present at load is not a live region');

        // The form points at the refusal first, then at the standing statement.
        $described = Dom::element($xpath, '//main//form')->getAttribute('aria-describedby');

        self::assertSame('login-notice login-status', $described);

        // Every id it names is really there.
        foreach (preg_split('/\s+/', trim($described)) ?: [] as $target) {
            Dom::element($xpath, sprintf('//*[@id="%s"]', $target), 'Dangling reference: ' . $target);
        }

        // With nothing refused there is no notice, and nothing to point at.
        $fresh = Dom::of(Dom::withoutScripts($this->page()));

        self::assertSame(0, Dom::query($fresh, '//main//*[@id="login-notice"]')->length);
        self::assertSame('login-status', Dom::element($fresh, '//main//form')->getAttribute('aria-describedby'));
    }

    /**
     * A rejected form is still submittable with the token it carries.
     */
    public function testTheRejectedFormIsStillSubmittable(): void
    {
        $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);

        $rejected = $this->submit(['_token' => $this->token(), 'email' => self::EMAIL, 'password' => 'wrong']);

        $token = Dom::element(
            Dom::of(Dom::withoutScripts($rejected->body())),
            '//main//form//input[@name="_token"]'
        )->getAttribute('value');

        $accepted = $this->submit(['_token' => $token, 'email' => self::EMAIL, 'password' => self::PASSWORD]);

        self::assertSame(303, $accepted->status());
        self::assertSame('/admin', $accepted->header('Location'));
    }

    /**
     * Requirement 5: an invalid token is 403, and it is decided before the
     * credentials are ever looked at — a submission that cannot show it was
     * composed here does not get to ask whether a password is right.
     */
    public function testASubmissionWithoutAValidTokenIsForbiddenBeforeAnyLookup(): void
    {
        $this->accounts->add(self::EMAIL, self::PASSWORD, Role::Admin);

        foreach ([[], ['_token' => ''], ['_token' => 'wrong'], ['_token' => str_repeat('f', 64)]] as $token) {
            $response = $this->submit($token + ['email' => self::EMAIL, 'password' => self::PASSWORD]);

            self::assertSame(403, $response->status());
            self::assertFalse($this->session->has(Authenticator::SESSION_KEY));
        }

        self::assertSame(0, $this->accounts->resolutions());
    }
}
