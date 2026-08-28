<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Account\Role;
use Facet\Auth\Authenticator;
use Facet\Config\Config;
use Facet\Contact\ContactMessage;
use Facet\Contact\ContactMessageStatus;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use Facet\Tests\Support\InMemoryAccountRepository;
use Facet\Tests\Support\RecordingContactMessageReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminStatusMutationTest extends TestCase
{
    private ArraySession $session;

    private InMemoryAccountRepository $accounts;

    private RecordingContactMessageReader $inbox;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->accounts = new InMemoryAccountRepository();
        $this->inbox = RecordingContactMessageReader::with([
            new ContactMessage(
                7,
                'Ada',
                'ada@example.com',
                'Subject',
                'Body',
                ContactMessageStatus::New,
                '2026-08-28 12:00:00'
            ),
        ]);
    }

    private function app(?RecordingContactMessageReader $inbox = null): Application
    {
        $inbox ??= $this->inbox;

        return Application::boot(
            dirname(__DIR__, 2),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'admin-status-key',
                'APP_LOCALE' => 'en',
            ]),
            null,
            null,
            null,
            $this->session,
            null,
            null,
            $this->accounts,
            $inbox,
            $inbox
        );
    }

    private function signIn(Role $role): void
    {
        $account = $this->accounts->add($role->value . '@example.com', 'a sufficiently long password', $role);
        $this->session->put(Authenticator::SESSION_KEY, (string) $account->id());
    }

    private function token(): string
    {
        return (new CsrfGuard())->token($this->session);
    }

    /** @return array<string, array{string}> */
    public static function statuses(): array
    {
        return ['new' => ['new'], 'read' => ['read'], 'archived' => ['archived']];
    }

    #[DataProvider('statuses')]
    public function testEveryClosedStatusUsesPrgAndChangesExactlyTheSelectedRow(string $status): void
    {
        $this->signIn(Role::Admin);
        $token = $this->token();

        $response = $this->app()->handle(Request::create('POST', '/admin/messages', [], [
            '_token' => $token,
            'id' => '7',
            'status' => $status,
        ]));

        self::assertSame(303, $response->status());
        self::assertSame('/admin/messages?id=7', $response->header('Location'));
        self::assertSame($status, $this->inbox->find(7)?->status()->value);
        self::assertNotSame($token, $this->token(), 'A successful mutation rotates its proof of intent');

        $refresh = $this->app()->handle(Request::create('GET', '/admin/messages?id=7', ['id' => '7']));
        self::assertSame(200, $refresh->status());
        self::assertSame($status, $this->inbox->find(7)?->status()->value);
    }

    public function testMalformedInputAndUnknownRowsNeverMutate(): void
    {
        $this->signIn(Role::Admin);

        foreach (['', '0', '-1', '1.0', 'abc', '2147483648', str_repeat('9', 200)] as $id) {
            $response = $this->app()->handle(Request::create('POST', '/admin/messages', [], [
                '_token' => $this->token(), 'id' => $id, 'status' => 'read',
            ]));
            self::assertSame(400, $response->status(), $id);
            self::assertSame(ContactMessageStatus::New, $this->inbox->find(7)?->status());
        }

        foreach (['', 'READ', 'deleted', 'new ', '<script>'] as $status) {
            $response = $this->app()->handle(Request::create('POST', '/admin/messages', [], [
                '_token' => $this->token(), 'id' => '7', 'status' => $status,
            ]));
            self::assertSame(422, $response->status(), $status);
            self::assertSame(ContactMessageStatus::New, $this->inbox->find(7)?->status());
        }

        $missing = $this->app()->handle(Request::create('POST', '/admin/messages', [], [
            '_token' => $this->token(), 'id' => '99', 'status' => 'read',
        ]));
        self::assertSame(404, $missing->status());
        self::assertSame(ContactMessageStatus::New, $this->inbox->find(7)?->status());
    }

    public function testAnonymousClientAndBadTokenRequestsChangeNothing(): void
    {
        $body = ['_token' => $this->token(), 'id' => '7', 'status' => 'archived'];
        self::assertSame(303, $this->app()->handle(Request::create('POST', '/admin/messages', [], $body))->status());
        self::assertSame(ContactMessageStatus::New, $this->inbox->find(7)?->status());

        $this->signIn(Role::Client);
        $body['_token'] = $this->token();
        self::assertSame(403, $this->app()->handle(Request::create('POST', '/admin/messages', [], $body))->status());
        self::assertSame(ContactMessageStatus::New, $this->inbox->find(7)?->status());

        $this->session = new ArraySession();
        $this->accounts = new InMemoryAccountRepository();
        $this->signIn(Role::Admin);
        $body['_token'] = 'wrong';
        self::assertSame(403, $this->app()->handle(Request::create('POST', '/admin/messages', [], $body))->status());
        self::assertSame(ContactMessageStatus::New, $this->inbox->find(7)?->status());
    }

    public function testUpdateFailureNeverClaimsSuccess(): void
    {
        $this->signIn(Role::Admin);
        $response = $this->app(RecordingContactMessageReader::failing())->handle(
            Request::create('POST', '/admin/messages', [], [
                '_token' => $this->token(), 'id' => '7', 'status' => 'read',
            ])
        );

        self::assertSame(500, $response->status());
        self::assertNull($response->header('Location'));
        foreach (['UPDATE', 'contact_messages', dirname(__DIR__, 2)] as $leak) {
            self::assertStringNotContainsString($leak, $response->body());
        }
    }
}
