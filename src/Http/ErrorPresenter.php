<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\I18n\Locale;
use Facet\I18n\LocalizedRoutes;
use Facet\I18n\Translations;
use Facet\Routing\RouteCatalog;
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
     * The statuses that have public text of their own. Anything else is
     * reported as a 500, which is the honest answer for a failure this class
     * has no wording for.
     *
     * @var list<int>
     */
    private const STATUSES = [400, 403, 404, 405, 422, 500, 501];

    /**
     * The wording of last resort, if the catalog itself is what broke.
     *
     * This class's second rule is that error rendering cannot fail, and since
     * PORT-137 the wording comes from {@see Translations} — one more moving
     * part. It is read directly and without the {@see \Facet\I18n\Translator},
     * so a missing key degrades to these two sentences rather than raising a
     * second exception while reporting the first.
     *
     * @var array<string, array{title: string, message: string}>
     */
    private const LAST_RESORT = [
        'fr' => [
            'title' => 'Erreur',
            'message' => "La page n'a pas pu être affichée.",
        ],
        'en' => [
            'title' => 'Error',
            'message' => 'The page could not be displayed.',
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
        ?Request $request = null,
        ?Locale $locale = null
    ): Response {
        $locale ??= Locale::default();
        $status = in_array($status, self::STATUSES, true) ? $status : Response::STATUS_INTERNAL_SERVER_ERROR;
        $text = self::publicText($status, $locale);

        $headers = ($error instanceof HttpException ? $error->headers() : []) + [
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        $data = $shared + [
            'locale' => $locale,
            'status' => $status,
            'title' => $text['title'],
            'message' => $text['message'],
            'debug' => $this->debug,
            'diagnostics' => $this->debug ? $this->diagnostics($error, $request) : [],
        ];

        return Response::html($this->render($skin, $data, $status, $text, $locale), $status, $headers);
    }

    /**
     * @param array<string, mixed>                 $data
     * @param array{title: string, message: string} $text
     */
    private function render(
        ?SkinDefinition $skin,
        array $data,
        int $status,
        array $text,
        Locale $locale
    ): string {
        if ($skin === null || !$this->renderer->supports($skin, self::VIEW)) {
            return self::fallbackDocument($status, $text, self::stringList($data['diagnostics'] ?? []), $locale);
        }

        try {
            return $this->renderer->render($skin, self::VIEW, $data);
        } catch (Throwable) {
            // A broken error template must never become the error being
            // reported. Degrade to markup that has no moving parts at all.
            return self::fallbackDocument($status, $text, self::stringList($data['diagnostics'] ?? []), $locale);
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
    public static function fallbackDocument(
        int $status,
        array $text,
        array $diagnostics = [],
        ?Locale $locale = null
    ): string {
        $locale ??= Locale::default();

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
            $details = '<h2>' . $escape(self::lookup('error.diagnostics.title', $locale)) . '</h2><ul>'
                . $details . '</ul>';
        }

        return '<!doctype html>' . "\n"
            . '<html lang="' . $escape($locale->htmlLang()) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $escape($text['title']) . '</title></head>'
            . '<body><main><h1>' . $escape($text['title']) . '</h1>'
            . '<p>' . $escape($text['message']) . '</p>'
            . '<p><a href="' . $escape(LocalizedRoutes::path(RouteCatalog::HOME, $locale)) . '">'
            . $escape(self::lookup('error.backHome', $locale)) . '</a></p>'
            . $details
            . '</main></body></html>' . "\n";
    }

    /**
     * The public text of a status, in one language.
     *
     * @return array{title: string, message: string}
     */
    public static function publicText(int $status, ?Locale $locale = null): array
    {
        $locale ??= Locale::default();
        $status = in_array($status, self::STATUSES, true) ? $status : Response::STATUS_INTERNAL_SERVER_ERROR;

        $title = self::lookup('error.' . $status . '.title', $locale);
        $message = self::lookup('error.' . $status . '.message', $locale);

        return $title === '' || $message === ''
            ? self::LAST_RESORT[$locale->value]
            : ['title' => $title, 'message' => $message];
    }

    /**
     * A catalog entry, read without the translator so nothing here can throw.
     */
    private static function lookup(string $key, Locale $locale): string
    {
        $entry = Translations::all()[$key] ?? null;

        return is_array($entry) && isset($entry[$locale->value]) ? $entry[$locale->value] : '';
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
