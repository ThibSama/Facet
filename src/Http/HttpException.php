<?php

declare(strict_types=1);

namespace Facet\Http;

use RuntimeException;
use Throwable;

/**
 * A failure the application already knows the HTTP meaning of.
 *
 * The distinction that matters: the *status* is public, the *message* is not.
 * A handler may put whatever detail it likes in the exception message for logs
 * and debug pages; {@see ErrorPresenter} only ever shows the status-derived
 * public text in production.
 */
final class HttpException extends RuntimeException
{
    private int $statusCode;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(int $statusCode, string $message, array $headers = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * Refused on the merits: the request was understood and is not permitted.
     *
     * The distinction from 400 matters for a rejected CSRF token — the body was
     * perfectly well formed, it simply carried no proof that this session asked
     * for the action.
     */
    public static function forbidden(string $message = 'The request was refused.'): self
    {
        return new self(Response::STATUS_FORBIDDEN, $message);
    }

    public static function notFound(string $message = 'No route matched the request.'): self
    {
        return new self(Response::STATUS_NOT_FOUND, $message);
    }

    public static function methodNotAllowed(string $allow, string $message = 'Method not accepted by this route.'): self
    {
        return new self(Response::STATUS_METHOD_NOT_ALLOWED, $message, ['Allow' => $allow]);
    }

    public static function badRequest(string $message = 'The request could not be understood.'): self
    {
        return new self(Response::STATUS_BAD_REQUEST, $message);
    }

    public static function unprocessable(string $message = 'The request values are invalid.'): self
    {
        return new self(Response::STATUS_UNPROCESSABLE_CONTENT, $message);
    }

    public static function notImplemented(string $message = 'This route is declared but not yet implemented.'): self
    {
        return new self(Response::STATUS_NOT_IMPLEMENTED, $message);
    }

    public static function internal(string $message, ?Throwable $previous = null): self
    {
        return new self(Response::STATUS_INTERNAL_SERVER_ERROR, $message, [], $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
