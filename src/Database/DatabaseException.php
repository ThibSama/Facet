<?php

declare(strict_types=1);

namespace Facet\Database;

use PDOException;
use RuntimeException;

/**
 * A database failure expressed in application terms.
 *
 * Two rules govern this class, and they pull against each other:
 *
 * 1. Nothing that reaches a message, a log line or a test assertion may
 *    contain a credential. The MySQL driver is not careful about this — an
 *    authentication failure reads `Access denied for user 'facet'@'10.0.0.1'`,
 *    which names the account — so every driver string is scrubbed on the way
 *    in rather than trusted.
 * 2. A developer still has to be able to debug the failure. The SQLSTATE and a
 *    redacted copy of the driver text are therefore preserved.
 *
 * The original {@see PDOException} is deliberately *not* chained. Chaining it
 * would put the unscrubbed driver message back into `(string) $exception` and
 * into any log that walks `getPrevious()`, which is exactly the leak rule 1
 * exists to prevent. {@see sqlState()} and {@see driverDetail()} carry the
 * causality forward in a form that is safe everywhere.
 */
final class DatabaseException extends RuntimeException
{
    private const REDACTED = '<redacted>';

    private ?string $sqlState;

    private string $driverDetail;

    private function __construct(string $message, ?string $sqlState, string $driverDetail)
    {
        parent::__construct($message);

        $this->sqlState = $sqlState;
        $this->driverDetail = $driverDetail;
    }

    public static function connectionFailed(PDOException $cause, DatabaseCredentials $credentials): self
    {
        return new self(
            'Could not establish a database connection.',
            self::sqlStateOf($cause),
            $credentials->redact($cause->getMessage())
        );
    }

    /**
     * @param string $sql developer-authored SQL; never a bound parameter value
     */
    public static function queryFailed(string $sql, PDOException $cause, DatabaseCredentials $credentials): self
    {
        return new self(
            sprintf('Database query failed: %s', self::summarise($sql)),
            self::sqlStateOf($cause),
            $credentials->redact($cause->getMessage())
        );
    }

    public static function transactionFailed(PDOException $cause, DatabaseCredentials $credentials): self
    {
        return new self(
            'Database transaction failed and was rolled back.',
            self::sqlStateOf($cause),
            $credentials->redact($cause->getMessage())
        );
    }

    public static function misconfigured(string $reason): self
    {
        return new self(
            sprintf('Database configuration is invalid: %s', $reason),
            null,
            self::REDACTED
        );
    }

    /**
     * The driver's five-character SQLSTATE, when it reported one.
     */
    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * The driver's own explanation, with every credential removed.
     */
    public function driverDetail(): string
    {
        return $this->driverDetail;
    }

    /**
     * PDO is inconsistent about where the SQLSTATE lands: for a statement
     * error it is the exception code (`'42S02'`), but for a connection error
     * the code is the driver's own int (`1045`) and the SQLSTATE is only in
     * `errorInfo`. Read the authoritative field first.
     */
    private static function sqlStateOf(PDOException $cause): ?string
    {
        $state = $cause->errorInfo[0] ?? null;

        if (is_string($state) && $state !== '') {
            return $state;
        }

        $code = $cause->getCode();

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * SQL is safe to echo, but an entire migration is not a useful message.
     */
    private static function summarise(string $sql): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($collapsed) > 120 ? substr($collapsed, 0, 117) . '...' : $collapsed;
    }
}
