<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use PHPUnit\Framework\Assert;

/**
 * Reads a served page the way a visitor's browser would.
 *
 * The project's rendering tests assert against a parsed document rather than
 * against substrings, because a substring match cannot tell "the summary is on
 * the card" from "the summary is somewhere in the HTML". Everything here is
 * deliberately assertion-light: it parses, queries and normalises, and leaves
 * every expectation to the test that asked.
 */
final class Dom
{
    /**
     * The same document with every script element removed — what a visitor
     * without JavaScript actually receives.
     */
    public static function withoutScripts(string $html): string
    {
        $stripped = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);
        Assert::assertIsString($stripped);

        $stripped = preg_replace('#<script\b[^>]*>#si', '', $stripped);
        Assert::assertIsString($stripped);

        return $stripped;
    }

    public static function of(string $html): DOMXPath
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        Assert::assertTrue($loaded, 'The rendered document must parse as HTML');

        return new DOMXPath($document);
    }

    /**
     * @return DOMNodeList<DOMElement>
     */
    public static function query(DOMXPath $xpath, string $expression): DOMNodeList
    {
        $result = $xpath->query($expression);
        Assert::assertNotFalse($result, 'Invalid XPath: ' . $expression);

        /** @var DOMNodeList<DOMElement> $result */
        return $result;
    }

    /**
     * The single element an expression must match.
     */
    public static function element(DOMXPath $xpath, string $expression, ?string $message = null): DOMElement
    {
        $nodes = self::query($xpath, $expression);
        Assert::assertCount(1, $nodes, $message ?? ('Expected exactly one match for ' . $expression));

        $node = $nodes->item(0);
        Assert::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    /**
     * Whitespace-collapsed text, the way it reads on screen.
     */
    public static function normalise(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value);
        Assert::assertIsString($collapsed);

        return trim($collapsed);
    }

    public static function textOf(DOMElement $element): string
    {
        return self::normalise($element->textContent);
    }

    /**
     * Every value of one attribute, in document order.
     *
     * @return list<string>
     */
    public static function attributes(DOMXPath $xpath, string $expression, string $attribute): array
    {
        $values = [];

        foreach (self::query($xpath, $expression) as $element) {
            $values[] = $element->getAttribute($attribute);
        }

        return $values;
    }
}
