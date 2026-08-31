<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use DOMElement;
use DOMXPath;
use Facet\Config\Config;
use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Http\Application;
use Facet\Http\Request;
use Facet\Tests\Support\Dom;
use PHPUnit\Framework\TestCase;

/**
 * The contact page at /contact, asserted against the DOM the server produced
 * with scripts stripped.
 *
 * The page is a form before it is anything else, so the assertions are the
 * ones a form has to answer: is every control named to a reader, is every
 * control named to the server, can the server attach a message to a control
 * without this markup being redesigned, and does the page work with a keyboard
 * and no JavaScript. Two further rules are checked because they are easy to
 * break silently: the page must claim only what the application actually does,
 * and every alternative way to reach the author must come from the canonical
 * corpus.
 *
 * The form now carries two controls a *visitor* never interacts with — the
 * CSRF token and the honeypot — and the assertions below are careful to
 * exclude them by role rather than by name, so a visitor-facing claim stays a
 * claim about what a visitor meets. What those two controls do is asserted in
 * {@see ContactSubmissionTest} and {@see ContactHttpFlowTest}; what matters
 * here is that neither of them costs a person anything.
 */
final class ContactFormTest extends TestCase
{
    private static ?Corpus $corpus = null;

    /** The fields the page owes a visitor, in document order. */
    private const FIELDS = ['name', 'email', 'subject', 'message'];

    /**
     * Every named control a visitor actually meets — which is to say, all of
     * them except the hidden CSRF token and the honeypot the stylesheet and
     * `aria-hidden` between them remove from sight, from speech and from the
     * keyboard path.
     */
    private const VISIBLE_CONTROLS =
        '//main//form//*[@name][not(@type="hidden")][not(ancestor::*[@aria-hidden="true"])]';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default(self::root())->load();
    }

    private static function application(): Application
    {
        return Application::boot(self::root(), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'en',
        ]));
    }

    private static function html(): string
    {
        $response = self::application()->handle(Request::create('GET', '/fr/contact'));

        self::assertSame(200, $response->status());

        return $response->body();
    }

    /**
     * The page as a visitor without JavaScript receives it.
     */
    private static function page(): DOMXPath
    {
        return Dom::of(Dom::withoutScripts(self::html()));
    }

    private static function form(DOMXPath $xpath): DOMElement
    {
        return Dom::element($xpath, '//main//form', 'The page must carry exactly one form');
    }

    private static function control(DOMXPath $xpath, string $field): DOMElement
    {
        return Dom::element(
            $xpath,
            sprintf('//main//form//*[@name="%s"]', $field),
            $field . ' must be exactly one control'
        );
    }

    // ------------------------------------------------------------ fields

    /**
     * Criterion 7: name, email, subject and message, each a real control with
     * a label that is actually associated with it.
     */
    public function testEveryFieldExistsOnceAndIsLabelled(): void
    {
        $xpath = self::page();

        $names = Dom::attributes($xpath, self::VISIBLE_CONTROLS, 'name');
        self::assertSame(self::FIELDS, $names, 'The form asks a visitor for exactly these fields, in this order');

        foreach (self::FIELDS as $field) {
            $control = self::control($xpath, $field);

            $id = $control->getAttribute('id');
            self::assertNotSame('', $id, $field . ' must carry an id a label can point at');

            $label = Dom::element(
                $xpath,
                sprintf('//main//form//label[@for="%s"]', $id),
                $field . ' must have exactly one associated label'
            );

            self::assertNotSame('', Dom::textOf($label), $field . ' must have a visible label text');
        }

        self::assertSame(
            'textarea',
            self::control($xpath, 'message')->nodeName,
            'A message needs more than one line'
        );
    }

    /**
     * Criterion 8: the control types and autocomplete tokens a browser and a
     * password/identity manager can act on, and help text on every field.
     */
    public function testFieldsCarryUsefulTypesAutocompleteAndHelpText(): void
    {
        $xpath = self::page();

        self::assertSame('text', self::control($xpath, 'name')->getAttribute('type'));
        self::assertSame('name', self::control($xpath, 'name')->getAttribute('autocomplete'));

        $email = self::control($xpath, 'email');
        self::assertSame('email', $email->getAttribute('type'));
        self::assertSame('email', $email->getAttribute('autocomplete'));
        self::assertSame('email', $email->getAttribute('inputmode'));

        self::assertSame('text', self::control($xpath, 'subject')->getAttribute('type'));

        foreach (self::FIELDS as $field) {
            $control = self::control($xpath, $field);

            $help = Dom::element(
                $xpath,
                sprintf('//main//form//*[@id="%s-help"]', $control->getAttribute('id')),
                $field . ' must have exactly one help element'
            );

            self::assertNotSame('', Dom::textOf($help), $field . ' help text must say something');
        }
    }

    /**
     * Criterion 9: every field already points at a help target *and* an empty
     * error target, so a later checkpoint renders a server-side error by
     * filling an element that exists rather than by changing this markup.
     */
    public function testEveryFieldHasStableHelpAndErrorTargetsAlreadyDescribingIt(): void
    {
        $xpath = self::page();

        foreach (self::FIELDS as $field) {
            $control = self::control($xpath, $field);
            $id = $control->getAttribute('id');

            $described = preg_split('/\s+/', trim($control->getAttribute('aria-describedby'))) ?: [];

            self::assertSame(
                [$id . '-help', $id . '-error'],
                $described,
                $field . ' must be described by its help and its error slot'
            );

            foreach ($described as $target) {
                $element = Dom::element(
                    $xpath,
                    sprintf('//main//*[@id="%s"]', $target),
                    $target . ' must exist exactly once in the document'
                );

                if (str_ends_with($target, '-error')) {
                    self::assertSame('', Dom::textOf($element), 'The error slot ships empty');
                    self::assertTrue(
                        $element->hasAttribute('data-facet-field-error'),
                        $target . ' must be addressable as a field error slot'
                    );
                }
            }
        }

        // Nothing in the document points at an id that is not there.
        foreach (Dom::attributes($xpath, '//main//*[@aria-describedby]', 'aria-describedby') as $value) {
            foreach (preg_split('/\s+/', trim($value)) ?: [] as $target) {
                Dom::element($xpath, sprintf('//*[@id="%s"]', $target), 'Dangling reference: ' . $target);
            }
        }
    }

    /**
     * Criterion 10: the native constraints are present as a convenience, and
     * they are demonstrably not what decides anything — the application does
     * not accept a submission at all yet.
     */
    public function testNativeConstraintsAreHintsAndNotTheBoundary(): void
    {
        $xpath = self::page();

        foreach (self::FIELDS as $field) {
            $control = self::control($xpath, $field);

            self::assertTrue($control->hasAttribute('required'), $field . ' should hint that it is needed');
            self::assertSame('true', $control->getAttribute('aria-required'), $field);
        }

        // The form is not marked novalidate — the hints are allowed to run —
        // but the server is where a submission is actually decided. A POST
        // that skipped the browser entirely reaches the same code, and is
        // refused by it: nothing in this document is what decided that.
        self::assertFalse(self::form($xpath)->hasAttribute('novalidate'));

        $posted = self::application()->handle(Request::create('POST', '/fr/contact'));

        self::assertSame(403, $posted->status(), 'POST is answered by the server, not by the markup');
        self::assertFalse($posted->isRedirect(), 'An unproven submission must not look accepted');
    }

    // ----------------------------------------------------------- honesty

    /**
     * Criterion 11: a real POST action, and a claim no larger than the truth.
     *
     * The form now does something, so the sentence that said it did not has
     * been replaced rather than merely softened. What replaced it is bounded on
     * purpose: the application receives and stores a message, and it does not
     * forward, email or acknowledge one. A page that says "I'll get back to
     * you" is promising something no code here performs.
     */
    public function testTheFormPostsToContactAndClaimsOnlyStorage(): void
    {
        $xpath = self::page();
        $form = self::form($xpath);

        self::assertSame('post', strtolower($form->getAttribute('method')));
        self::assertSame('/fr/contact', $form->getAttribute('action'));

        $text = mb_strtolower(Dom::textOf(Dom::element($xpath, '//main')));

        foreach ([
            'will reach me',
            'reaches me',
            'i will get back',
            'i will reply',
            'i will respond',
            'thanks for your message',
            'delivered',
            'sent to me',
            'emailed to me',
            'forwarded to me',
        ] as $claim) {
            self::assertStringNotContainsString($claim, $text, 'The page must not claim delivery: ' . $claim);
        }

        // The superseded sentence must be gone, not merely edited around it.
        self::assertStringNotContainsString('does not send anything', $text);

        // It says what is true instead, and says it before the form: the form
        // is described by that statement.
        self::assertSame('contact-status', $form->getAttribute('aria-describedby'));

        $status = Dom::textOf(Dom::element($xpath, '//main//*[@id="contact-status"]'));

        self::assertStringContainsString('enregistré sur ce site', $status);
        self::assertStringContainsString("Rien n'est transmis ailleurs automatiquement", $status);

        // On a page nobody has submitted from, there is no outcome to report.
        self::assertSame(0, Dom::query($xpath, '//main//*[@id="contact-notice"]')->length);
    }

    /**
     * Criterion 12: alternatives are the canonical profile links and nothing
     * else. No invented address of any kind appears on the page.
     */
    public function testAlternativesComeOnlyFromCanonicalProfileLinks(): void
    {
        $xpath = self::page();
        $links = self::corpus()->profile()->links();

        self::assertNotEmpty($links, 'The canonical profile documents no alternative to offer');

        self::assertSame(
            array_map(static fn ($link): string => $link->url(), $links),
            Dom::attributes($xpath, '//main//section[@aria-labelledby="other-ways"]//a', 'href'),
            'The alternatives are exactly the canonical profile links, in corpus order'
        );

        foreach ($links as $link) {
            $anchor = Dom::element($xpath, sprintf('//main//a[@href="%s"]', $link->url()));

            self::assertSame($link->label(), Dom::textOf($anchor));
            self::assertSame('noopener noreferrer', $anchor->getAttribute('rel'));
        }

        foreach (Dom::attributes($xpath, '//main//a', 'href') as $href) {
            self::assertStringStartsNotWith('mailto:', $href, 'No email address the corpus does not carry');
            self::assertStringStartsNotWith('tel:', $href, 'No phone number the corpus does not carry');
        }

        // And no address written as plain text either.
        $text = Dom::textOf(Dom::element($xpath, '//main'));

        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i', $text);
        self::assertDoesNotMatchRegularExpression('/\+?\d[\d ().-]{7,}\d/', $text);
    }

    // ------------------------------------------------- keyboard and no-JS

    /**
     * Criterion 13: with every script removed the form is complete, and every
     * interactive thing on the page is natively operable by keyboard.
     */
    public function testTheFormAndAlternativesWorkWithoutJavaScript(): void
    {
        $noJs = Dom::withoutScripts(self::html());

        self::assertStringNotContainsString('javascript:', $noJs);
        self::assertStringNotContainsString('<noscript', $noJs);

        $xpath = Dom::of($noJs);

        // The submit control is a real button in a real form, not a scripted
        // handler hung off something inert.
        $submit = Dom::element($xpath, '//main//form//button[@type="submit"]');
        self::assertNotSame('', Dom::textOf($submit));

        foreach (self::FIELDS as $field) {
            self::control($xpath, $field);
        }

        self::assertGreaterThan(0, Dom::query($xpath, '//main//section[@aria-labelledby="other-ways"]//a[@href]')->length);

        // Nothing on the page depends on a script, and nothing rearranges the
        // tab order.
        foreach (Dom::query($xpath, '//main//*') as $element) {
            foreach (['onclick', 'onsubmit', 'onchange', 'oninput', 'onfocus'] as $handler) {
                self::assertFalse($element->hasAttribute($handler), 'Inline handler on ' . $element->nodeName);
            }

            $tabindex = $element->getAttribute('tabindex');

            if ($tabindex !== '') {
                self::assertLessThanOrEqual(0, (int) $tabindex, 'A positive tabindex reorders the keyboard path');
            }
        }

        foreach (Dom::query($xpath, '//main//a') as $anchor) {
            self::assertNotSame('', $anchor->getAttribute('href'), 'An anchor without href is not focusable');
        }
    }
}
