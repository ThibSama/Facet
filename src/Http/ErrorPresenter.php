<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRenderer;
use Throwable;

/**
 * Turns a failure into an HTML response that discloses nothing it should not.
 *
 * Two rules drive the whole class.
 *
 * First, disclosure is decided by environment, not by the exception. The public
 * text of an error is derived from its *status code* only; the exception's own
 * message, file, line and trace are attached solely when debug is on, and even
 * then in a bounded, path-stripped form. That is why an exception carrying a
 * secret cannot leak it in production: nothing in the production branch ever
 * reads the message.
 *
 * Second, error rendering must not be able to fail. A skin template is a moving
 * part like any other, so rendering the error view is attempted, and any
 * failure of that attempt falls back to markup built into this file. An error
 * page that throws while reporting an error is how a 500 turns into a blank
 * page or, worse, a stack trace printed by the SAPI.
 */
final class ErrorPresenter
{
    /** The logical view a skin may supply to style error pages. */
    public const VIEW = 'page.error';

    /**
     * Public, status-derived text. No entry here can reveal anything about the
     * failure that produced it, which is exactly the property we want.
     *
     * @var array<int, array{title: string, message: string}>
     */
    private const PUBLIC_TEXT = [
        400 => [
            'title' => 'Bad request',
            'message' => 'The request could not be understood.',
        ],
        403 => [
            'title' => 'Not available',
            'message' => 'This page is not available.',
        ],
        404 => [
            'title' => 'Page not found',
            'message' => 'This page does not exist.',
        ],
        405 => [
            'title' => 'Method not allowed',
            'message' => 'This page does not accept that kind of request.',
        ],
        422 => [
            'title' => 'Invalid values',
            'message' => 'One or more submitted values are invalid.',
        ],
        501 => [
            'title' => 'Not available yet',
            'message' => 'This page is not available yet.',
        ],
        500 => [
            'title' => 'Something went wrong',
            'message' => 'The page could not be displayed. Please try again later.',
        ],
    ];

    /** How many call frames a debug page may show. */
    private const MAX_TRACE_FRAMES = 8;

    private SkinRenderer $renderer;

    private bool $debug;

    public function __construct(SkinRenderer $renderer, bool $debug)
    {
        $this->renderer = $renderer;
        $this->debug = $debug;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @param array<string, mixed> $shared data every view of this request gets
     */
    public function present(
        Throwable $error,
        int $status,
        ?SkinDefinition $skin = null,
        array $shared = [],
        ?Request $request = null
    ): Response {
        $status = isset(self::PUBLIC_TEXT[$status]) ? $status : Response::STATUS_INTERNAL_SERVER_ERROR;
        $text = self::PUBLIC_TEXT[$status];

        $headers = $error instanceof HttpException ? $error->headers() : [];

        $data = $shared + [
            'status' => $status,
            'title' => $text['title'],
            'message' => $text['message'],
            'debug' => $this->debug,
            'diagnostics' => $this->debug ? $this->diagnostics($error, $request) : [],
        ];

        return Response::html($this->render($skin, $data, $status, $text), $status, $headers);
    }

    /**
     * @param array<string, mixed>                 $data
     * @param array{title: string, message: string} $text
     */
    private function render(?SkinDefinition $skin, array $data, int $status, array $text): string
    {
        if ($skin === null || !$this->renderer->supports($skin, self::VIEW)) {
            return self::fallbackDocument($status, $text, self::stringList($data['diagnostics'] ?? []));
        }

        try {
            return $this->renderer->render($skin, self::VIEW, $data);
        } catch (Throwable) {
            // A broken error template must never become the error being
            // reported. Degrade to markup that has no moving parts at all.
            return self::fallbackDocument($status, $text, self::stringList($data['diagnostics'] ?? []));
        }
    }

    /**
     * Bounded, path-free diagnostic context. Debug-only by construction: the
     * caller decides whether to ask for it, and never does in production.
     *
     * @return list<string>
     */
    private function diagnostics(Throwable $error, ?Request $request): array
    {
        $lines = [
            'exception: ' . $error::class,
            'message: ' . $error->getMessage(),
            'origin: ' . basename($error->getFile()) . ':' . $error->getLine(),
        ];

        if ($request !== null) {
            $lines[] = 'request: ' . $request->method() . ' ' . $request->path();
        }

        $frames = 0;

        foreach ($error->getTrace() as $frame) {
            if ($frames >= self::MAX_TRACE_FRAMES) {
                break;
            }

            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] . '::' : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '{closure}';
            $line = isset($frame['line']) && is_int($frame['line']) ? ':' . $frame['line'] : '';
            $file = isset($frame['file']) && is_string($frame['file']) ? basename($frame['file']) : 'internal';

            $lines[] = 'at ' . $class . $function . '() in ' . $file . $line;
            $frames++;
        }

        $previous = $error->getPrevious();

        if ($previous !== null) {
            $lines[] = 'caused by: ' . $previous::class . ': ' . $previous->getMessage();
        }

        return $lines;
    }

    /**
     * The last line of defence: a complete, valid document that depends on no
     * template, no skin, no asset and no JavaScript.
     *
     * @param array{title: string, message: string} $text
     * @param list<string>                          $diagnostics
     */
    public static function fallbackDocument(int $status, array $text, array $diagnostics = []): string
    {
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );

        $details = '';

        foreach ($diagnostics as $line) {
            $details .= '<li>' . $escape($line) . '</li>';
        }

        if ($details !== '') {
            $details = '<h2>Diagnostics</h2><ul>' . $details . '</ul>';
        }

        return '<!doctype html>' . "\n"
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $escape($text['title']) . '</title></head>'
            . '<body><main><h1>' . $escape($text['title']) . '</h1>'
            . '<p>' . $escape($text['message']) . '</p>'
            . '<p><a href="/">Back to the home page</a></p>'
            . $details
            . '</main></body></html>' . "\n";
    }

    /**
     * @return array{title: string, message: string}
     */
    public static function publicText(int $status): array
    {
        return self::PUBLIC_TEXT[$status] ?? self::PUBLIC_TEXT[Response::STATUS_INTERNAL_SERVER_ERROR];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $lines = [];

        foreach ($value as $line) {
            if (is_string($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
