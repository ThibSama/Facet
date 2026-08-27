<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Html;

use Facet\Html\Escaper;
use Facet\Html\Html;
use Facet\Html\ViewContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TypeError;

/**
 * Escaping is the default, and raw output is a decision you have to make.
 *
 * The type signature of {@see ViewContext::raw()} is the mechanism: a template
 * cannot reach raw output by interpolating a string, only by constructing an
 * {@see Html} on purpose. That is what turns "remember to escape" into
 * "escaping is what happens unless you say otherwise".
 */
final class EscapingTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function hostileValues(): array
    {
        return [
            ['<script>alert(1)</script>'],
            ['" onmouseover="alert(1)'],
            ["' onfocus='alert(1)"],
            ['<img src=x onerror=alert(1)>'],
            ['a & b'],
            ['</title><script>x</script>'],
        ];
    }

    #[DataProvider('hostileValues')]
    public function testTextIsEscaped(string $value): void
    {
        $escaped = (new ViewContext())->text($value);

        self::assertStringNotContainsString('<', $escaped);
        self::assertStringNotContainsString('>', $escaped);
        self::assertSame($value, html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    #[DataProvider('hostileValues')]
    public function testAttributesAreEscapedIncludingBothQuoteStyles(string $value): void
    {
        $escaped = (new ViewContext())->attr($value);

        self::assertStringNotContainsString('"', $escaped);
        self::assertStringNotContainsString("'", $escaped);
        self::assertStringNotContainsString('<', $escaped);
    }

    public function testNullAndNumbersArePrintable(): void
    {
        $view = new ViewContext();

        self::assertSame('', $view->text(null));
        self::assertSame('404', $view->text(404));
        self::assertSame('1.5', $view->text(1.5));
    }

    public function testExecutableUrlSchemesAreNeutralised(): void
    {
        $view = new ViewContext();

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>', 'vbscript:x'] as $url) {
            self::assertSame(Escaper::BLOCKED_URL, $view->url($url), $url . ' must not survive');
        }

        self::assertSame(Escaper::BLOCKED_URL, $view->url("java\tscript:alert(1)"));
        self::assertSame(Escaper::BLOCKED_URL, $view->url(''));
    }

    public function testLegitimateUrlsSurviveEscaped(): void
    {
        $view = new ViewContext();

        self::assertSame('/projects/kushim', $view->url('/projects/kushim'));
        self::assertSame('https://example.test/a?b=1&amp;c=2', $view->url('https://example.test/a?b=1&c=2'));
        self::assertSame('mailto:someone@example.test', $view->url('mailto:someone@example.test'));
        self::assertSame('https://example.test/x', $view->url('//example.test/x'));
    }

    public function testRawOutputRequiresAnExplicitTrustedValue(): void
    {
        $view = new ViewContext();

        self::assertSame('<b>ok</b>', $view->raw(Html::trusted('<b>ok</b>')));
    }

    public function testAPlainStringCannotBecomeRawMarkup(): void
    {
        $view = new ViewContext();

        $this->expectException(TypeError::class);

        // Invoked reflectively because the refusal is the behaviour under
        // test: a direct call does not survive static analysis, which is the
        // same guarantee stated from the other side.
        (new ReflectionMethod(ViewContext::class, 'raw'))->invoke($view, '<script>alert(1)</script>');
    }

    public function testHtmlEscapingFactoryProducesSafeTrustedMarkup(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            (new ViewContext())->raw(Html::escaping('<script>alert(1)</script>'))
        );
    }

    public function testJoinEscapesEveryMember(): void
    {
        $joined = (new ViewContext())->join(['<a>', '<b>']);

        self::assertSame('&lt;a&gt;, &lt;b&gt;', $joined);
    }

    public function testAttributeListsSkipNullsAndRefuseInvalidNames(): void
    {
        $view = new ViewContext();

        self::assertSame('aria-current="page"', $view->attributes(['aria-current' => 'page', 'x' => null]));
        self::assertSame('', $view->attributes(['on click"' => 'alert(1)']));
    }
}
