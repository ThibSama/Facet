<?php

declare(strict_types=1);

namespace Facet\Http;

use InvalidArgumentException;

/**
 * One outbound HTTP response, captured as immutable data.
 *
 * Nothing here writes to output, sets a header or touches a global: a response
 * is a value the application returns and a test can assert on in full. Actually
 * putting it on the wire is {@see ResponseEmitter}'s single job, which is the
 * only reason the runtime can be exercised without a web server.
 */
final class Response
{
    public const STATUS_OK = 200;
    public const STATUS_MOVED_PERMANENTLY = 301;
    public const STATUS_FOUND = 302;
    public const STATUS_SEE_OTHER = 303;
    public const STATUS_BAD_REQUEST = 400;
    public const STATUS_NOT_FOUND = 404;
    public const STATUS_METHOD_NOT_ALLOWED = 405;
    public const STATUS_INTERNAL_SERVER_ERROR = 500;
    public const STATUS_NOT_IMPLEMENTED = 501;

    /** @var array<int, string> */
    private const REASON_PHRASES = [
        200 => 'OK',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
    ];

    private int $status;

    /** @var array<string, string> */
    private array $headers;

    private string $body;

    /**
     * @param array<string, string> $headers
     */
    private function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws InvalidArgumentException when the status is outside the HTTP range
     */
    public static function html(string $html, int $status = self::STATUS_OK, array $headers = []): self
    {
        return new self(
            self::assertStatus($status),
            self::normaliseHeaders($headers + [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-cache',
            ]),
            $html
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function text(string $text, int $status = self::STATUS_OK, array $headers = []): self
    {
        return new self(
            self::assertStatus($status),
            self::normaliseHeaders($headers + ['Content-Type' => 'text/plain; charset=utf-8']),
            $text
        );
    }

    /**
     * A redirect is a first-class response value, not a header side effect
     * performed halfway through rendering.
     *
     * @throws InvalidArgumentException when the location could inject a header
     */
    public static function redirect(string $location, int $status = self::STATUS_FOUND): self
    {
        if ($location === '' || preg_match('/[\r\n\0]/', $location) === 1) {
            throw new InvalidArgumentException('A redirect location must be a single-line, non-empty value.');
        }

        if ($status < 300 || $status > 399) {
            throw new InvalidArgumentException(sprintf('Status %d is not a redirect status.', $status));
        }

        return new self($status, ['Location' => $location], '');
    }

    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function reasonPhrase(): string
    {
        return self::REASON_PHRASES[$this->status] ?? 'Unknown Status';
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && $this->hasHeader('Location');
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isError(): bool
    {
        return $this->status >= 400;
    }

    /**
     * @throws InvalidArgumentException when the header would span lines
     */
    public function withHeader(string $name, string $value): self
    {
        if ($name === '' || preg_match('/[\r\n\0]/', $name . $value) === 1) {
            throw new InvalidArgumentException(sprintf('Header "%s" must be a single-line name/value pair.', $name));
        }

        $headers = $this->headers;

        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }

        $headers[$name] = $value;

        return new self($this->status, $headers, $this->body);
    }

    public function withStatus(int $status): self
    {
        return new self(self::assertStatus($status), $this->headers, $this->body);
    }

    private static function assertStatus(int $status): int
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(sprintf('%d is not a valid HTTP status code.', $status));
        }

        return $status;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function normaliseHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if ($name === '' || preg_match('/[\r\n\0]/', $name . $value) === 1) {
                throw new InvalidArgumentException(sprintf('Header "%s" must be a single-line name/value pair.', $name));
            }
        }

        return $headers;
    }
}
