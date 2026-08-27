<?php

declare(strict_types=1);

namespace Facet\Tests\Content;

use Facet\Content\Corpus;
use Facet\Content\CorpusLoader;
use Facet\Content\TextualEntry;
use PHPUnit\Framework\TestCase;

/**
 * Walks every entry of the shipped corpus as plain text.
 *
 * This is the proof that the corpus is renderer-agnostic: if any entry needed
 * markup, a template or a skin to be meaningful, it would show up here.
 */
final class CorpusTextTraversalTest extends TestCase
{
    private static ?Corpus $corpus = null;

    private static function corpus(): Corpus
    {
        return self::$corpus ??= CorpusLoader::default()->load();
    }

    public function testEveryEntryYieldsPlainTextFragments(): void
    {
        $entries = self::corpus()->entries();

        self::assertNotEmpty($entries);

        foreach ($entries as $entry) {
            self::assertInstanceOf(TextualEntry::class, $entry);
            self::assertNotEmpty(
                $entry->textFragments(),
                get_class($entry) . ' must yield at least one text fragment'
            );
        }
    }

    public function testTraversalCoversEveryEntryInTheCorpus(): void
    {
        $corpus = self::corpus();

        // profile + projects + skills + experiences, nothing skipped.
        $expected = 1
            + count($corpus->projects())
            + count($corpus->skills())
            + count($corpus->experiences());

        self::assertCount($expected, $corpus->entries());
    }

    public function testNoFragmentContainsMarkup(): void
    {
        foreach (self::corpus()->textFragments() as $fragment) {
            self::assertDoesNotMatchRegularExpression(
                '/<[a-z\/!][^>]*>/i',
                $fragment,
                'Canonical content must be plain text, never markup: ' . $fragment
            );
            self::assertDoesNotMatchRegularExpression(
                '/&(?:[a-z]+|#\d+);/i',
                $fragment,
                'Canonical content must not carry HTML entities: ' . $fragment
            );
        }
    }

    public function testEveryFragmentIsValidNonEmptyUtf8(): void
    {
        $fragments = self::corpus()->textFragments();

        self::assertNotEmpty($fragments);

        foreach ($fragments as $fragment) {
            self::assertNotSame('', trim($fragment));
            self::assertTrue(mb_check_encoding($fragment, 'UTF-8'), 'Fragment must be valid UTF-8');
            self::assertSame(trim($fragment), $fragment, 'Fragment must not carry stray whitespace');
        }
    }

    public function testCorpusContainsNoPlaceholderCopy(): void
    {
        $banned = [
            'lorem ipsum', 'dolor sit amet', 'consectetur adipiscing',
            'todo', 'tbd', 'fixme', 'xxx', 'placeholder text',
            'coming soon', 'à compléter', 'a completer',
        ];

        foreach (self::corpus()->textFragments() as $fragment) {
            $normalised = mb_strtolower($fragment);

            foreach ($banned as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $normalised,
                    'Placeholder copy found in the canonical corpus: ' . $fragment
                );
            }
        }
    }

    public function testTraversalIsStable(): void
    {
        $first = self::corpus()->textFragments();
        $second = CorpusLoader::default()->load()->textFragments();

        self::assertSame($first, $second, 'Corpus traversal order must be deterministic');
    }
}
