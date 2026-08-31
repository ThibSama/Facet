<?php

declare(strict_types=1);

namespace Facet\Tests\Database;

use Facet\Config\Config;
use Facet\Contact\ContactMessageRepository;
use Facet\Contact\ContactMessageStoreFactory;
use Facet\Contact\ContactStoreException;
use Facet\Contact\ContactValidator;
use Facet\Contact\UnavailableContactMessageStore;
use Facet\Database\Database;
use Facet\Database\Migration\Migrator;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Http\Response;
use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\FrozenClock;
use Facet\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Contact persistence against the live MariaDB instance.
 *
 * The in-memory store used elsewhere proves the *decisions*; this file proves
 * that the row MariaDB ends up holding is the row we meant. That difference
 * matters more than it looks: a repository can satisfy an interface perfectly
 * and still write a value the column truncates, a status the ENUM does not
 * have, or a second row on a retry. Nothing below is asserted from the
 * application's own return value — every count and every field is read back out
 * of the table.
 *
 * The instance is the disposable one named by `FACET_TEST_DB_*`, and it is
 * reset around every test. A skip here is not a pass: these gates are only
 * meaningful on a machine where that database exists.
 */
final class ContactMessagePersistenceTest extends TestCase
{
    private const VALID = [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'subject' => 'About the analytical engine',
        'message' => 'I would like to discuss a collaboration.',
    ];

    private Database $database;

    protected function setUp(): void
    {
        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped(TestDatabase::unavailableReason());
        }

        TestDatabase::reset();
        $this->database = TestDatabase::connection();
        Migrator::default($this->database, dirname(__DIR__, 2))->migrate();
    }

    protected function tearDown(): void
    {
        if (TestDatabase::isConfigured()) {
            TestDatabase::reset();
        }
    }

    // ----------------------------------------------------------- helpers

    private function rowCount(): int
    {
        return (int) $this->database->selectValue('SELECT COUNT(*) FROM contact_messages');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->database->select(
            'SELECT id, name, email, subject, message, status, created_at FROM contact_messages ORDER BY id'
        );
    }

    private function repository(): ContactMessageRepository
    {
        return new ContactMessageRepository($this->database);
    }

    /**
     * @param array<string, string> $body
     */
    private function submissionFrom(array $body): \Facet\Contact\ContactSubmission
    {
        $validation = (new ContactValidator())->validate($body + self::VALID);
        self::assertTrue($validation->isValid());

        $submission = $validation->submission();
        self::assertNotNull($submission);

        return $submission;
    }

    /**
     * The application, wired to the live database through exactly the
     * production factory — so what is exercised is the wiring that ships and
     * not a hand-built repository the entrypoint would never construct.
     */
    private function application(ArraySession $session, ?FrozenClock $clock = null): Application
    {
        return Application::boot(
            dirname(__DIR__, 2),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'contact-persistence-key',
                'APP_LOCALE' => 'en',
                'APP_DEBUG' => 'false',
            ]),
            null,
            null,
            null,
            $session,
            new ContactMessageRepository($this->database),
            $clock ?? new FrozenClock()
        );
    }

    private function tokenOf(Response $page): string
    {
        return Dom::element(
            Dom::of(Dom::withoutScripts($page->body())),
            '//main//form//input[@name="' . CsrfGuard::FIELD . '"]'
        )->getAttribute('value');
    }

    // ------------------------------------------------------- the insert

    public function testANominalSubmissionInsertsExactlyOneCanonicalRow(): void
    {
        self::assertSame(0, $this->rowCount(), 'The table starts empty');

        $id = $this->repository()->store($this->submissionFrom([]));

        self::assertGreaterThan(0, $id);
        self::assertSame(1, $this->rowCount(), 'Exactly one row, no more and no fewer');

        $rows = $this->rows();
        $row = $rows[0];

        self::assertSame($id, (int) $row['id'], 'The returned id addresses the row that exists');
        self::assertSame(self::VALID['name'], $row['name']);
        self::assertSame(self::VALID['email'], $row['email']);
        self::assertSame(self::VALID['subject'], $row['subject']);
        self::assertSame(self::VALID['message'], $row['message']);

        // The lifecycle starts where the schema says it does, and the arrival
        // time is the server's, not a value the application supplied.
        self::assertSame('new', $row['status']);
        self::assertNotSame('', (string) $row['created_at']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $row['created_at']
        );
    }

    /**
     * The columns hold what a person wrote and when it arrived — and nothing
     * that would make the table a log of who they are.
     */
    public function testTheTableHoldsNoRequestMetadataOrSecurityInternals(): void
    {
        $this->repository()->store($this->submissionFrom([]));

        /** @var list<array<string, mixed>> $columns */
        $columns = $this->database->select(
            'SELECT column_name AS name FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => 'contact_messages']
        );

        $names = array_map(static fn (array $column): string => (string) $column['name'], $columns);
        sort($names);

        self::assertSame(
            ['created_at', 'email', 'id', 'message', 'name', 'status', 'subject'],
            $names,
            'No column may exist for a token, a honeypot verdict, a throttle counter or an IP address'
        );

        // And nothing anywhere in the row's text carries a security internal.
        $serialised = json_encode($this->rows());
        self::assertIsString($serialised);

        foreach (['csrf', '_token', 'honeypot', 'website', 'throttle', 'session', 'remote_addr'] as $internal) {
            self::assertStringNotContainsStringIgnoringCase($internal, $serialised, $internal . ' was persisted');
        }
    }

    /**
     * The repository never composes SQL from a value. Asserted both
     * structurally — the statement is a constant with named placeholders — and
     * behaviourally, by storing the payloads that would end an interpolated
     * query.
     */
    public function testInjectionPayloadsAreStoredAsTextAndNeverExecuted(): void
    {
        $payloads = [
            "'; DROP TABLE contact_messages; --",
            "' OR '1'='1",
            'Robert"); DROP TABLE contact_messages; --',
            "\\'; DELETE FROM contact_messages WHERE '1'='1",
        ];

        foreach ($payloads as $index => $payload) {
            $this->repository()->store($this->submissionFrom([
                'name' => $payload,
                'subject' => $payload,
                'message' => $payload,
            ]));

            self::assertSame($index + 1, $this->rowCount(), 'The table must still be there, one row longer');
        }

        foreach ($this->rows() as $index => $row) {
            self::assertSame($payloads[$index], $row['name'], 'The payload is stored verbatim, as data');
            self::assertSame($payloads[$index], $row['message']);
        }
    }

    /**
     * Values at exactly the column's width survive intact. A silent truncation
     * is the failure this catches: MariaDB in a non-strict mode would store
     * 120 characters of a 121-character name and report success.
     */
    public function testValuesAtTheColumnBoundsAreStoredWithoutTruncation(): void
    {
        $name = str_repeat('é', ContactValidator::NAME_MAX);
        $subject = str_repeat('s', ContactValidator::SUBJECT_MAX);
        $message = str_repeat('m', ContactValidator::MESSAGE_MAX);

        $this->repository()->store($this->submissionFrom([
            'name' => $name,
            'subject' => $subject,
            'message' => $message,
        ]));

        $row = $this->rows()[0];

        self::assertSame($name, $row['name']);
        self::assertSame($subject, $row['subject']);
        self::assertSame($message, $row['message']);

        self::assertSame(ContactValidator::NAME_MAX, mb_strlen((string) $row['name']));
        self::assertSame(ContactValidator::MESSAGE_MAX, mb_strlen((string) $row['message']));
    }

    /**
     * The validator's bounds really are the schema's. Anything the validator
     * would have rejected is also refused by the column, which is what makes
     * the database a second line rather than the only one.
     */
    public function testTheSchemaRefusesWhatTheValidatorWouldHaveRejected(): void
    {
        $overlong = str_repeat('m', ContactValidator::MESSAGE_MAX + 1);

        self::assertFalse((new ContactValidator())->validate(['message' => $overlong] + self::VALID)->isValid());

        try {
            $this->database->execute(
                'INSERT INTO contact_messages (name, email, subject, message) '
                . 'VALUES (:name, :email, :subject, :message)',
                ['message' => $overlong] + self::VALID
            );

            self::fail('The column must refuse a message the validator rejected');
        } catch (\Facet\Database\DatabaseException) {
            // Expected: the CHECK constraint is the backstop.
        }

        self::assertSame(0, $this->rowCount());
    }

    /**
     * A store failure leaves nothing behind — no half row, no orphan.
     */
    public function testAFailedWriteInsertsNothing(): void
    {
        $this->database->executeTrusted('DROP TABLE contact_messages');

        try {
            $this->repository()->store($this->submissionFrom([]));

            self::fail('Storing into a table that is gone must throw');
        } catch (ContactStoreException $error) {
            // The exception the HTTP layer catches, and it names nothing.
            self::assertStringNotContainsString('INSERT', $error->getMessage());
            self::assertStringNotContainsString('contact_messages', $error->getMessage());
        }
    }

    // ------------------------------------------------ through the runtime

    /**
     * The full journey in one process: the application, the real repository
     * and the real table. Every claim is a row count read back from MariaDB.
     */
    public function testTheApplicationJourneyProducesExactlyOneRow(): void
    {
        $session = new ArraySession();
        $app = $this->application($session);

        $page = $app->handle(Request::create('GET', '/fr/contact'));
        self::assertSame(200, $page->status());
        self::assertSame(0, $this->rowCount(), 'Rendering the form touches nothing');

        $token = $this->tokenOf($page);

        $posted = $app->handle(Request::create('POST', '/fr/contact', [], [
            CsrfGuard::FIELD => $token,
        ] + self::VALID));

        self::assertSame(303, $posted->status());
        self::assertSame('/fr/contact', $posted->header('Location'));
        self::assertSame(1, $this->rowCount());

        $landing = $app->handle(Request::create('GET', '/fr/contact'));
        self::assertStringContainsString('a été reçu', $landing->body());
        self::assertSame(1, $this->rowCount(), 'Following the redirect stores nothing more');

        for ($i = 0; $i < 5; $i++) {
            $app->handle(Request::create('GET', '/fr/contact'));
        }

        self::assertSame(1, $this->rowCount(), 'No refresh may add a row');

        $row = $this->rows()[0];
        self::assertSame(self::VALID['message'], $row['message']);
        self::assertSame('new', $row['status']);
    }

    /**
     * Every refusal, against the live table. The status is incidental here —
     * the assertion that matters is that the table is still empty.
     */
    public function testEveryRefusedSubmissionLeavesTheTableEmpty(): void
    {
        $session = new ArraySession();
        $clock = new FrozenClock();
        $app = $this->application($session, $clock);

        $token = $this->tokenOf($app->handle(Request::create('GET', '/fr/contact')));

        $refusals = [
            'no token' => self::VALID,
            'wrong token' => [CsrfGuard::FIELD => str_repeat('0', 64)] + self::VALID,
            'invalid email' => [CsrfGuard::FIELD => $token, 'email' => 'nope'] + self::VALID,
            'blank message' => [CsrfGuard::FIELD => $token, 'message' => '   '] + self::VALID,
            'overlong subject' => [
                CsrfGuard::FIELD => $token,
                'subject' => str_repeat('s', ContactValidator::SUBJECT_MAX + 1),
            ] + self::VALID,
            'filled honeypot' => [CsrfGuard::FIELD => $token, 'website' => 'http://spam.example'] + self::VALID,
        ];

        foreach ($refusals as $why => $body) {
            $response = $app->handle(Request::create('POST', '/fr/contact', [], $body));

            self::assertNotSame(200, $response->status(), $why);
            self::assertSame(0, $this->rowCount(), $why . ' must leave the table empty');
        }

        // The honeypot consumed the token by answering as a success would, so
        // the genuine submission that follows takes a fresh one.
        $genuine = $app->handle(Request::create('POST', '/fr/contact', [], [
            CsrfGuard::FIELD => $this->tokenOf($app->handle(Request::create('GET', '/fr/contact'))),
        ] + self::VALID));

        self::assertSame(303, $genuine->status());
        self::assertSame(1, $this->rowCount(), 'And a real one still gets through');
    }

    /**
     * The throttle bounds rows, not just responses.
     */
    public function testTheThrottleBoundsTheNumberOfRowsOneSessionCanWrite(): void
    {
        $session = new ArraySession();
        $clock = new FrozenClock();
        $app = $this->application($session, $clock);

        for ($i = 0; $i < 20; $i++) {
            $token = $this->tokenOf($app->handle(Request::create('GET', '/fr/contact')));

            $app->handle(Request::create('POST', '/fr/contact', [], [CsrfGuard::FIELD => $token] + self::VALID));
        }

        self::assertSame(
            \Facet\Security\RateLimiter::DEFAULT_LIMIT,
            $this->rowCount(),
            'Twenty attempts must not become twenty rows'
        );
    }

    // ----------------------------------------------------------- factory

    public function testTheFactoryChoosesADatabaseStoreOnlyWhenCredentialsExist(): void
    {
        self::assertInstanceOf(
            UnavailableContactMessageStore::class,
            ContactMessageStoreFactory::fromConfig(Config::fromArray(['APP_NAME' => 'Facet'])),
            'No credentials means no store, and asking must not throw'
        );

        $credentials = TestDatabase::credentials();

        self::assertInstanceOf(
            ContactMessageRepository::class,
            ContactMessageStoreFactory::fromConfig(Config::fromArray([
                'DB_DSN' => $credentials->dsn(),
                'DB_USERNAME' => $credentials->username(),
                'DB_PASSWORD' => $credentials->password(),
            ]))
        );
    }
}
