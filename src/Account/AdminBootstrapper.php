<?php

declare(strict_types=1);

namespace Facet\Account;

use Facet\Database\Database;
use Facet\Database\DatabaseException;
use Facet\Support\EmailAddress;
use SensitiveParameter;

/**
 * Creates the first administrator, from a shell.
 *
 * There is deliberately no HTTP counterpart to this class. A web-reachable
 * installer is a permanent liability — it has to be either removed after use
 * or defended forever, and both are things a deploy eventually gets wrong — so
 * the only way to mint an admin is with shell access to the machine.
 *
 * The password never reaches the database in a readable form and never reaches
 * this repository at all: {@see password_hash()} is applied here, and the
 * caller supplies the plaintext at run time.
 */
final class AdminBootstrapper
{
    public const ROLE = 'admin';

    /**
     * Long enough that an offline guess against the stored digest is not the
     * cheap attack. Not a policy engine — just a floor.
     */
    public const MINIMUM_PASSWORD_LENGTH = 12;

    /**
     * MariaDB reports every integrity violation, unique-key collisions
     * included, under SQLSTATE 23000.
     */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * @return int the new account's id
     *
     * @throws \Facet\Support\InvalidEmailAddressException when the address is unusable
     * @throws AccountException when the password is too weak or the address is taken
     * @throws DatabaseException on any other database failure
     */
    public function create(string $email, #[SensitiveParameter] string $password): int
    {
        // Throws on anything unstorable, and returns the same normalised form
        // the schema's CHECK constraint insists on.
        $canonical = EmailAddress::canonical($email);

        $this->assertPasswordIsAcceptable($password);

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $this->database->execute(
                'INSERT INTO users (email, password_hash, role, status) '
                . 'VALUES (:email, :password_hash, :role, :status)',
                [
                    'email' => $canonical,
                    'password_hash' => $hash,
                    'role' => self::ROLE,
                    'status' => 'active',
                ]
            );
        } catch (DatabaseException $e) {
            if ($e->sqlState() === self::SQLSTATE_INTEGRITY_VIOLATION) {
                // Re-phrased rather than re-thrown: the driver text names the
                // index and the colliding value, which is more than the
                // operator needs and more than a log should hold.
                throw AccountException::alreadyExists($canonical);
            }

            throw $e;
        }

        $id = $this->database->lastInsertId();

        if ($id === null) {
            throw AccountException::notCreated($canonical);
        }

        return (int) $id;
    }

    public function exists(string $email): bool
    {
        return $this->database->selectValue(
            'SELECT COUNT(*) FROM users WHERE email = :email',
            ['email' => EmailAddress::normalise($email)]
        ) > 0;
    }

    public function adminCount(): int
    {
        return (int) $this->database->selectValue(
            'SELECT COUNT(*) FROM users WHERE role = :role',
            ['role' => self::ROLE]
        );
    }

    /**
     * @throws AccountException
     */
    private function assertPasswordIsAcceptable(#[SensitiveParameter] string $password): void
    {
        if (trim($password) === '') {
            throw AccountException::passwordIsBlank();
        }

        // Bytes, not characters: the bcrypt input limit is measured in bytes,
        // and counting graphemes here would overstate the strength of a short
        // multi-byte password.
        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw AccountException::passwordTooShort(self::MINIMUM_PASSWORD_LENGTH);
        }
    }
}
