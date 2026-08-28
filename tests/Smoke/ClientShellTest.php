<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Account\Role;
use Facet\Auth\Authenticator;
use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Security\CsrfGuard;
use Facet\Session\ArraySession;
use Facet\Tests\Support\Dom;
use Facet\Tests\Support\InMemoryAccountRepository;
use Facet\Tests\Support\RecordingContactMessageReader;
use PHPUnit\Framework\TestCase;

final class ClientShellTest extends TestCase
{
    public function testClientShellIsTruthfulPrivateAndCarriesARealLogoutForm(): void
    {
        $session = new ArraySession();
        $accounts = new InMemoryAccountRepository();
        $client = $accounts->add('client@example.com', 'a sufficiently long password', Role::Client);
        $session->put(Authenticator::SESSION_KEY, (string) $client->id());

        $app = Application::boot(
            dirname(__DIR__, 2),
            Config::fromArray([
                'APP_NAME' => 'Facet',
                'APP_ENV' => 'production',
                'APP_KEY' => 'client-shell-key',
                'APP_LOCALE' => 'en',
            ]),
            null,
            null,
            null,
            $session,
            null,
            null,
            $accounts,
            new RecordingContactMessageReader()
        );

        $response = $app->handle(Request::create('GET', '/client'));
        self::assertSame(200, $response->status());

        $xpath = Dom::of(Dom::withoutScripts($response->body()));
        $mainText = Dom::textOf(Dom::element($xpath, '//main'));
        self::assertStringContainsString('client@example.com', $mainText);
        self::assertStringContainsString('No client feature has been delivered', $response->body());
        foreach (['dashboard', 'project', 'file', 'download', 'upload', 'invoice'] as $fiction) {
            self::assertStringNotContainsStringIgnoringCase($fiction, $mainText, $fiction);
        }

        Dom::element($xpath, '//main//form[@method="post" and @action="/logout"]');
        self::assertSame(0, Dom::query($xpath, '//a[@href="/logout"]')->length);
        $token = Dom::element($xpath, '//main//form/input[@name="_token"]')
            ->getAttribute('value');
        self::assertTrue((new CsrfGuard())->isValid($session, $token));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response->body());
    }
}
