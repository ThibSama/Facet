<?php

declare(strict_types=1);

/**
 * Create the first administrator account.
 *
 *     php tools/create-admin.php --email=you@example.com
 *
 * The password is never taken from the command line: arguments are visible to
 * every process on the machine through `ps`, and they land in shell history.
 * It is read from the terminal with echo disabled, or — for an automated
 * provision — from the FACET_ADMIN_PASSWORD environment variable.
 *
 * There is no web equivalent of this script, and no default account: this
 * repository contains no password and no hash, so a fresh deployment has no
 * credential until a human runs this command.
 */

use Facet\Account\AccountException;
use Facet\Account\AdminBootstrapper;
use Facet\Config\Config;
use Facet\Config\MissingConfigurationException;
use Facet\Database\Database;
use Facet\Database\DatabaseException;
use Facet\Support\EmailAddress;
use Facet\Support\InvalidEmailAddressException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/**
 * Read a secret from the terminal without echoing it.
 *
 * Falls back to a plain read when stdin is not a terminal (a pipe, or CI), so
 * the script still works unattended.
 */
function facet_read_secret(string $prompt): string
{
    fwrite(STDERR, $prompt);

    $interactive = stream_isatty(STDIN) && DIRECTORY_SEPARATOR === '/';
    $restore = null;

    if ($interactive) {
        $current = shell_exec('stty -g 2>/dev/null');

        if (is_string($current) && trim($current) !== '') {
            $restore = trim($current);
            shell_exec('stty -echo 2>/dev/null');
        }
    }

    $line = fgets(STDIN);

    if ($restore !== null) {
        shell_exec('stty ' . escapeshellarg($restore) . ' 2>/dev/null');
        fwrite(STDERR, "\n");
    }

    return is_string($line) ? rtrim($line, "\r\n") : '';
}

/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);

$email = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--email=')) {
        $email = substr($argument, strlen('--email='));
    }
}

if ($email === null || $email === '') {
    fwrite(STDERR, "Usage: php tools/create-admin.php --email=you@example.com\n");
    exit(2);
}

$fromEnvironment = getenv('FACET_ADMIN_PASSWORD');

if (is_string($fromEnvironment) && $fromEnvironment !== '') {
    $password = $fromEnvironment;
} else {
    $password = facet_read_secret('Password: ');
    $confirmation = facet_read_secret('Confirm password: ');

    if ($password !== $confirmation) {
        fwrite(STDERR, "Passwords did not match; no account was created.\n");
        exit(2);
    }
}

$root = dirname(__DIR__);

try {
    $database = Database::fromConfig(Config::fromEnvironment($root));
    $bootstrapper = new AdminBootstrapper($database);

    $id = $bootstrapper->create($email, $password);

    // Report the address as it was stored, not as it was typed, so the
    // operator sees the value they will actually sign in with.
    fwrite(STDOUT, sprintf("Created admin account #%d for %s.\n", $id, EmailAddress::canonical($email)));
    exit(0);
} catch (MissingConfigurationException $e) {
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\n");
    exit(3);
} catch (InvalidEmailAddressException | AccountException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(4);
} catch (DatabaseException $e) {
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, '  ' . $e->driverDetail() . "\n");
    exit(5);
} finally {
    // Do not leave the plaintext sitting in memory any longer than needed.
    $password = '';
    unset($password);
}
