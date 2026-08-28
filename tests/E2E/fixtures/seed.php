<?php

declare(strict_types=1);

/**
 * Deterministic database state for the Playwright end-to-end suite.
 *
 *     php tests/E2E/fixtures/seed.php --migrate   drop every table, then migrate
 *     php tests/E2E/fixtures/seed.php             truncate and re-seed
 *     php tests/E2E/fixtures/seed.php --report    print the current state as JSON
 *
 * The suite owns one disposable schema and nothing else. Credentials come from
 * `FACET_TEST_DB_*` — the same keys the PHPUnit database gates use, and
 * deliberately not the application's `DB_*` keys — so running the E2E suite can
 * never reach a database somebody configured for real use. The guard below
 * refuses outright unless the DSN names `facet_test`: a reset that drops tables
 * must not be one typo away from dropping the wrong ones.
 *
 * Everything it writes goes through the application's own mechanisms: the
 * migrator builds the schema, {@see AdminBootstrapper} mints the administrator
 * with the hash the login path verifies, and {@see ContactMessageRepository}
 * inserts the messages the admin inbox reads. The one direct statement is the
 * client account, because the bootstrapper only ever creates admins; it uses
 * the same `password_hash()` the bootstrapper does and the same columns the
 * schema constrains.
 *
 * `--migrate` is separated from the per-test reset on purpose. The schema is
 * built once and proved once; each test then truncates, which also resets
 * AUTO_INCREMENT, so every test sees the same rows under the same ids
 * regardless of what ran before it or in what order.
 */

use Facet\Account\AdminBootstrapper;
use Facet\Contact\ContactMessageRepository;
use Facet\Contact\ContactSubmission;
use Facet\Database\Database;
use Facet\Database\DatabaseCredentials;
use Facet\Database\Migration\Migrator;
use Facet\Support\DotEnv;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * The accounts and messages every E2E test starts from.
 *
 * Written down here, in one place, and read from TypeScript through
 * `--report`, so the assertions in the specs and the rows in the database
 * cannot drift apart.
 */
const E2E_ADMIN_EMAIL = 'e2e-admin@facet.test';
const E2E_CLIENT_EMAIL = 'e2e-client@facet.test';
const E2E_PASSWORD = 'e2e-fixture-password';

/** @var list<array{name: string, email: string, subject: string, message: string, status: string}> */
const E2E_MESSAGES = [
    [
        'name' => 'Ada Fixture',
        'email' => 'ada@example.test',
        'subject' => 'Seeded new message',
        'message' => "First seeded message.\nIt arrives unread.",
        'status' => 'new',
    ],
    [
        'name' => 'Grace Fixture',
        'email' => 'grace@example.test',
        'subject' => 'Seeded read message',
        'message' => 'Second seeded message, already read.',
        'status' => 'read',
    ],
    [
        'name' => 'Alan Fixture',
        'email' => 'alan@example.test',
        'subject' => 'Seeded archived message',
        'message' => 'Third seeded message, archived.',
        'status' => 'archived',
    ],
];

$root = dirname(__DIR__, 3);

DotEnv::load($root . '/.env.testing');

/**
 * @return string the value, or exits with a message naming only the key
 */
function e2e_env(string $key): string
{
    $fromProcess = getenv($key);

    if (is_string($fromProcess) && $fromProcess !== '') {
        return $fromProcess;
    }

    $fromFile = $_ENV[$key] ?? null;

    if (is_string($fromFile) && $fromFile !== '') {
        return $fromFile;
    }

    fwrite(STDERR, sprintf(
        "%s is not set. Export FACET_TEST_DB_DSN/USER/PASSWORD, or create .env.testing,\n"
        . "to run the end-to-end suite. A missing database is a blocked run, not a pass.\n",
        $key
    ));
    exit(2);
}

$dsn = e2e_env('FACET_TEST_DB_DSN');

// The one hard stop. `facet_test` is the only schema this script may touch, and
// the check is on the DSN rather than on a flag a caller could forget.
if (!preg_match('/(?:^|;)\s*dbname\s*=\s*facet_test\s*(?:;|$)/i', $dsn)) {
    fwrite(STDERR, "Refusing to run: FACET_TEST_DB_DSN does not name the facet_test schema.\n");
    exit(2);
}

$database = new Database(DatabaseCredentials::create(
    $dsn,
    e2e_env('FACET_TEST_DB_USER'),
    e2e_env('FACET_TEST_DB_PASSWORD')
));

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);

/**
 * Drop every base table in the current schema.
 *
 * Tables rather than the schema itself, so the grant the test account holds
 * stays sufficient — the same reason {@see \Facet\Tests\Support\TestDatabase}
 * does it this way.
 */
function e2e_drop_all(Database $database): int
{
    /** @var list<array<string, mixed>> $tables */
    $tables = $database->select(
        'SELECT table_name AS name FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() AND table_type = :type',
        ['type' => 'BASE TABLE']
    );

    if ($tables === []) {
        return 0;
    }

    $database->executeTrusted('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tables as $table) {
        $name = (string) $table['name'];
        $database->executeTrusted(sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '``', $name)));
    }

    $database->executeTrusted('SET FOREIGN_KEY_CHECKS = 1');

    return count($tables);
}

/**
 * Empty the two tables the suite writes to.
 *
 * TRUNCATE rather than DELETE: it resets AUTO_INCREMENT, which is what makes
 * the seeded message ids 1, 2 and 3 in every test rather than only in the first.
 */
function e2e_truncate(Database $database): void
{
    $database->executeTrusted('SET FOREIGN_KEY_CHECKS = 0');
    $database->executeTrusted('TRUNCATE TABLE contact_messages');
    $database->executeTrusted('TRUNCATE TABLE users');
    $database->executeTrusted('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * @return array{admin: int, client: int, messages: list<int>}
 */
function e2e_seed(Database $database): array
{
    $adminId = (new AdminBootstrapper($database))->create(E2E_ADMIN_EMAIL, E2E_PASSWORD);

    // The bootstrapper only ever mints admins, by design, so the client row is
    // written here — with the same hashing function and the same columns.
    $database->execute(
        'INSERT INTO users (email, password_hash, role, status) '
        . 'VALUES (:email, :password_hash, :role, :status)',
        [
            'email' => E2E_CLIENT_EMAIL,
            'password_hash' => password_hash(E2E_PASSWORD, PASSWORD_DEFAULT),
            'role' => 'client',
            'status' => 'active',
        ]
    );

    $clientId = (int) $database->lastInsertId();

    $repository = new ContactMessageRepository($database);

    /** @var list<int> $ids */
    $ids = [];

    foreach (E2E_MESSAGES as $message) {
        $id = $repository->store(new ContactSubmission(
            $message['name'],
            $message['email'],
            $message['subject'],
            $message['message']
        ));

        // The lifecycle starts at `new` in the schema; the two later states are
        // applied through the same updater the admin screen uses.
        if ($message['status'] !== 'new') {
            $repository->updateStatus($id, \Facet\Contact\ContactMessageStatus::from($message['status']));
        }

        $ids[] = $id;
    }

    return ['admin' => $adminId, 'client' => $clientId, 'messages' => $ids];
}

/**
 * @return array<string, mixed>
 */
function e2e_report(Database $database): array
{
    /** @var list<array<string, mixed>> $users */
    $users = $database->select('SELECT id, email, role, status FROM users ORDER BY id');
    /** @var list<array<string, mixed>> $messages */
    $messages = $database->select('SELECT id, email, subject, status FROM contact_messages ORDER BY id');

    return [
        'schema' => (string) $database->selectValue('SELECT DATABASE()'),
        'users' => $users,
        'messages' => $messages,
        'adminEmail' => E2E_ADMIN_EMAIL,
        'clientEmail' => E2E_CLIENT_EMAIL,
        'password' => E2E_PASSWORD,
    ];
}

try {
    if (in_array('--report', $arguments, true)) {
        fwrite(STDOUT, json_encode(e2e_report($database), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        exit(0);
    }

    if (in_array('--migrate', $arguments, true)) {
        $dropped = e2e_drop_all($database);
        $applied = Migrator::default($database, $root)->migrate();

        fwrite(STDOUT, sprintf(
            "Dropped %d table(s); applied %d migration(s): %s\n",
            $dropped,
            count($applied),
            $applied === [] ? '(none)' : implode(', ', $applied)
        ));
    }

    e2e_truncate($database);
    $seeded = e2e_seed($database);

    fwrite(STDOUT, sprintf(
        "Seeded admin #%d, client #%d, messages %s\n",
        $seeded['admin'],
        $seeded['client'],
        implode(', ', array_map(static fn (int $id): string => '#' . $id, $seeded['messages']))
    ));

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("Fixture failure (%s): %s\n", $error::class, $error->getMessage()));
    exit(3);
}
