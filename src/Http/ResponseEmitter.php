<?php

declare(strict_types=1);

namespace Facet\Http;

/**
 * The one place a {@see Response} becomes real output.
 *
 * Isolating the side effects here is what lets every other component be a pure
 * function of its input: nothing else in the runtime calls header() or echoes.
 * Under the CLI SAPI the header calls are inert, so the same emitter serves the
 * web server and the smoke tests without a branch.
 */
final class ResponseEmitter
{
    public function emit(Response $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->status());

            foreach ($response->headers() as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $response->body();
    }
}
