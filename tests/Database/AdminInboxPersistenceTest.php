<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Account\Role;
use Facet\Auth\Authenticator;
use Facet\Config\Config;
use Facet\Contact\ContactMessageRepository;
use Facet\Contact\ContactMessageStatus;
use Facet\Contact\ContactValidator;
use Facet\Database\Database;
use Facet\Database\Migration\Migrator;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Session\ArraySession;
use Facet\Tests\Support\InMemoryAccountRepository;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class AdminInboxPersistenceTest extends TestCase
{
    private Database $database;

    private ContactMessageRepository $repository;

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        TestDatabase::reset();
        $this->database = TestDatabase::connection();
        Migrator::default($this->database, dirname(__DIR__, 2))->migrate();
        $this->repository = new ContactMessageRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }
    }

    private function store(int $number, string $payload = 'Plain text'): int
    {
        $validation = (new ContactValidator())->validate([
            'name' => 'Sender ' . $number,
            'email' => 'sender' . $number . '@example.com',
            'subject' => 'Subject ' . $number . ' ' . $payload,
            'message' => 'Message ' . $number . ' ' . $payload,
        ]);
        self::assertTrue($validation->isValid());
        self::assertNotNull($validation->submission());

        return $this->repository->store($validation->submission());
    }

    private function app(ArraySession $session, InMemoryAccountRepository $accounts): Application
    {
        return Application::boot(
            dirname(__DIR__, 2),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'live-admin-inbox-key',
                'APP_LOCALE' => 'en',
            ]),
            null,
            null,
            null,
            $session,
            null,
            null,
            $accounts,
            $this->repository
        );
    }

    public function testLiveInboxIsExplicitWhenEmptyAndReadsRowsNewestFirst(): void
    {
        self::assertSame([], $this->repository->newest(50));

        $first = $this->store(1);
        $second = $this->store(2);

        $rows = $this->repository->newest(50);
        self::assertSame([$second, $first], array_map(static fn ($message): int => $message->id(), $rows));
        self::assertSame($first, $this->repository->find($first)?->id());
        self::assertNull($this->repository->find(2147483647));
    }

    public function testLiveInboxResultCountIsBounded(): void
    {
        for ($number = 1; $number <= 55; $number++) {
            $this->store($number);
        }

        self::assertCount(50, $this->repository->newest(50));
        self::assertSame(55, $this->repository->newest(1)[0]->id());
    }

    public function testLiveHostileRowRendersAsEscapedTextWithPrivateRobotsPolicy(): void
    {
        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $id = $this->store(7, $payload);
        $session = new ArraySession();
        $accounts = new InMemoryAccountRepository();
        $admin = $accounts->add('admin@example.com', 'a sufficiently long password', Role::Admin);
        $session->put(Authenticator::SESSION_KEY, (string) $admin->id());

        $body = $this->app($session, $accounts)->handle(
            Request::create('GET', '/admin/messages?id=' . $id, ['id' => (string) $id])
        )->body();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $body);
    }

    public function testLiveStatusMatrixTouchesOnlyTheRequestedExistingRow(): void
    {
        $first = $this->store(1);
        $second = $this->store(2);

        foreach (ContactMessageStatus::cases() as $status) {
            self::assertTrue($this->repository->updateStatus($first, $status));
            self::assertSame($status, $this->repository->find($first)?->status());
            self::assertSame(ContactMessageStatus::New, $this->repository->find($second)?->status());
        }

        self::assertFalse($this->repository->updateStatus(2147483647, ContactMessageStatus::Read));
        self::assertSame(2, (int) $this->database->selectValue('SELECT COUNT(*) FROM contact_messages'));
    }

    public function testLiveDatabaseFailureCannotProduceAMutationSuccess(): void
    {
        $id = $this->store(1);
        $this->database->executeTrusted('DROP TABLE contact_messages');

        $session = new ArraySession();
        $accounts = new InMemoryAccountRepository();
        $admin = $accounts->add('admin@example.com', 'a sufficiently long password', Role::Admin);
        $session->put(Authenticator::SESSION_KEY, (string) $admin->id());
        $token = (new \Facet\Security\CsrfGuard())->token($session);

        $response = $this->app($session, $accounts)->handle(Request::create('POST', '/admin/messages', [], [
            '_token' => $token,
            'id' => (string) $id,
            'status' => 'read',
        ]));

        self::assertSame(500, $response->status());
        self::assertNull($response->header('Location'));
        self::assertStringNotContainsString('contact_messages', $response->body());
    }
}
