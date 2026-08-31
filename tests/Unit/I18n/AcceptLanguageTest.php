<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\I18n;

use Facet\I18n\AcceptLanguage;
use Facet\I18n\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The only negotiation this site performs, and the only signal it is allowed to
 * negotiate on.
 *
 * It answers one question — which localized URL an *unprefixed* entry route
 * should redirect to — and it answers null whenever the header does not decide,
 * which the resolver reads as "fall back", never as an error.
 */
final class AcceptLanguageTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?Locale}>
     */
    public static function headers(): array
    {
        return [
            'absent' => [null, null],
            'empty' => ['', null],
            'plain french' => ['fr', Locale::Fr],
            'plain english' => ['en', Locale::En],
            'regional french' => ['fr-FR', Locale::Fr],
            'canadian french' => ['fr-CA', Locale::Fr],
            'american english' => ['en-US', Locale::En],
            'british english' => ['en-GB', Locale::En],
            'weighted english' => ['en-US,en;q=0.9,fr;q=0.8', Locale::En],
            'weighted french' => ['fr-FR,fr;q=0.9,en;q=0.8', Locale::Fr],
            'unsupported then english' => ['de-DE,de;q=0.9,en;q=0.8', Locale::En],
            'unsupported only' => ['de-DE', null],
            'several unsupported' => ['de,es,it,ja', null],
            'wildcard only' => ['*', null],
            'english refused outright' => ['en;q=0, fr;q=0.1', Locale::Fr],
            'order breaks a tie' => ['en,fr', Locale::En],
            'reverse order breaks it the other way' => ['fr,en', Locale::Fr],
            'quality beats order' => ['en;q=0.4,fr;q=0.9', Locale::Fr],
            'malformed' => ['@@@ not a header ???', null],
            'malformed quality' => ['fr;q=banana', Locale::Fr],
            'whitespace' => ['  fr-FR ,  en ;q=0.5 ', Locale::Fr],
            'absurdly long' => [str_repeat('de-DE,', 200) . 'en', null],
        ];
    }

    #[DataProvider('headers')]
    public function testTheHeaderIsReadWellEnoughToChooseBetweenTwoLanguages(
        ?string $header,
        ?Locale $expected
    ): void {
        self::assertSame($expected, AcceptLanguage::preferred($header));
    }
}
