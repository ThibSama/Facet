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
use Facet\Session\ArraySession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\InMemoryAccountRepository;
use Facet\Tests\Support\RecordingContactMessageReader;
use PHPUnit\Framework\TestCase;

final class AdminInboxTest extends TestCase
{
    private ArraySession $session;

    private InMemoryAccountRepository $accounts;

    private RecordingContactMessageReader $reader;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->accounts = new InMemoryAccountRepository();
        $this->reader = new RecordingContactMessageReader();
    }

    private function app(?RecordingContactMessageReader $reader = null): Application
    {
        return Application::boot(
            dirname(__DIR__, 2),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'admin-inbox-key',
                'APP_LOCALE' => 'en',
            ]),
            null,
            null,
            null,
            $this->session,
            null,
            null,
            $this->accounts,
            $reader ?? $this->reader
        );
    }

    private function signIn(Role $role = Role::Admin): void
    {
        $account = $this->accounts->add($role->value . '@example.com', 'a sufficiently long password', $role);
        $this->session->put(Authenticator::SESSION_KEY, (string) $account->id());
    }

    private static function message(
        int $id,
        string $name = 'Ada Lovelace',
        string $subject = 'Analytical engine',
        string $body = 'A plain message.'
    ): ContactMessage {
        return new ContactMessage(
            $id,
            $name,
            'ada@example.com',
            $subject,
            $body,
            ContactMessageStatus::New,
            '2026-08-28 12:00:00'
        );
    }

    public function testAdminLandingIsTruthfulAndLinksToTheInbox(): void
    {
        $this->signIn();
        $response = $this->app()->handle(Request::create('GET', '/admin'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('href="/admin/messages"', $response->body());
        self::assertStringNotContainsString('metric', strtolower($response->body()));
    }

    public function testEmptyInboxIsExplicitAndBounded(): void
    {
        $this->signIn();
        $response = $this->app()->handle(Request::create('GET', '/admin/messages'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('There are no contact messages.', $response->body());
        self::assertSame(50, $this->reader->lastLimit());
    }

    public function testInboxIsNewestFirstAndCanSelectOneMessage(): void
    {
        $this->reader = RecordingContactMessageReader::with([self::message(1), self::message(3), self::message(2)]);
        $this->signIn();

        $response = $this->app()->handle(Request::create('GET', '/admin/messages?id=2', ['id' => '2']));
        self::assertSame(200, $response->status());

        $xpath = Dom::of(Dom::withoutScripts($response->body()));
        self::assertSame(['3', '2', '1'], array_map(
            static fn ($node): string => trim($node->textContent),
            iterator_to_array(Dom::query($xpath, '//tbody/tr/td[1]'))
        ));
        self::assertSame('Analytical engine', Dom::textOf(Dom::element($xpath, '//article/h2')));
    }

    public function testHostileStoredMarkupIsOnlyText(): void
    {
        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->reader = RecordingContactMessageReader::with([self::message(9, $payload, $payload, $payload)]);
        $this->signIn();

        $body = $this->app()->handle(Request::create('GET', '/admin/messages?id=9', ['id' => '9']))->body();

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testMalformedAndMissingIdsFailDeterministically(): void
    {
        $this->signIn();

        foreach (['', '0', '-1', '1.0', 'abc', '2147483648', str_repeat('9', 200)] as $id) {
            self::assertSame(400, $this->app()->handle(
                Request::create('GET', '/admin/messages?id=' . rawurlencode($id), ['id' => $id])
            )->status(), $id);
        }

        self::assertSame(404, $this->app()->handle(
            Request::create('GET', '/admin/messages?id=42', ['id' => '42'])
        )->status());
    }

    public function testPrivateAndErrorResponsesCarryRobotsNoindex(): void
    {
        $this->signIn();

        foreach (['/admin', '/admin/messages', '/client'] as $path) {
            $response = $this->app()->handle(Request::create('GET', $path));
            if ($path === '/client') {
                self::assertSame(403, $response->status());
            }
            self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response->body());
        }

        $notFound = $this->app()->handle(Request::create('GET', '/not-found'));
        self::assertSame(404, $notFound->status());
        self::assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            $notFound->body()
        );

        foreach (['/fr', '/fr/projects', '/fr/about', '/fr/contact'] as $path) {
            self::assertStringNotContainsString(
                'name="robots"',
                $this->app()->handle(Request::create('GET', $path))->body(),
                $path
            );
        }
    }

    public function testInboxFailureIsAProductionSafe500(): void
    {
        $this->signIn();
        $response = $this->app(RecordingContactMessageReader::failing())
            ->handle(Request::create('GET', '/admin/messages'));

        self::assertSame(500, $response->status());
        foreach (['SELECT', 'contact_messages', 'Database', dirname(__DIR__, 2)] as $leak) {
            self::assertStringNotContainsString($leak, $response->body());
        }
    }
}
