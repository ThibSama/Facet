<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\I18n;

use Facet\Contact\ContactValidator;
use Facet\Content\ExperienceKind;
use Facet\Content\ProjectStatus;
use Facet\Content\SkillCategory;
use Facet\I18n\Locale;
use Facet\I18n\MissingTranslationException;
use Facet\I18n\Translations;
use Facet\I18n\Translator;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The missing-translation policy, asserted rather than described.
 *
 * The policy has three parts and each is checked here: every declared string
 * exists in both languages, every key a template asks for is declared, and a
 * key that is not declared raises rather than reaching a visitor. There is no
 * runtime fallback to test, because there is none: a French page cannot grow an
 * English heading, and no visitor can be shown `home.selectedWork`.
 */
final class TranslationCompletenessTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * The shape of the catalog is what makes key parity structural: one entry
     * holds both languages, so "the French catalog has a key the English one
     * does not" is not a state this file can be in. What is still worth
     * asserting is that neither half was left blank.
     */
    public function testEveryEntryDeclaresBothLanguagesAndNeitherIsEmpty(): void
    {
        $catalog = Translations::all();

        self::assertNotSame([], $catalog);

        foreach ($catalog as $key => $entry) {
            self::assertSame(
                ['fr', 'en'],
                array_keys($entry),
                $key . ' must declare exactly the supported languages, in order'
            );

            foreach (Locale::supported() as $locale) {
                self::assertNotSame('', trim($entry[$locale->value]), $key . ' is empty in ' . $locale->value);
            }
        }
    }

    /**
     * The two languages must not be accidentally identical everywhere. A few
     * entries legitimately are — "Contact", "Menu", "Technologies", "Concepts",
     * "Score" — because the word is the same in both; a catalog where *most*
     * entries matched would mean one language had been pasted over the other.
     */
    public function testTheTwoLanguagesAreGenuinelyDifferentCatalogs(): void
    {
        $catalog = Translations::all();
        $identical = 0;

        foreach ($catalog as $entry) {
            if ($entry['fr'] === $entry['en']) {
                $identical++;
            }
        }

        self::assertLessThan(
            count($catalog) / 4,
            $identical,
            'Most entries must differ between the two languages'
        );
    }

    /**
     * Every key the running code asks for is declared.
     *
     * The keys are read out of the sources themselves rather than listed here,
     * so a template that introduces a string and forgets the catalog entry is
     * caught by this test rather than by a 500 in front of a visitor.
     */
    public function testEveryKeyTheApplicationAsksForIsDeclared(): void
    {
        $used = self::keysUsedInSources();

        self::assertNotSame([], $used, 'The scan must actually find the keys the site uses');

        foreach ($used as $key => $where) {
            self::assertTrue(
                Translations::has($key),
                sprintf('"%s" is asked for in %s but the catalog does not declare it', $key, $where)
            );
        }
    }

    /**
     * The keys built at runtime from a stored vocabulary rather than written
     * out in a template: a status, a skill category, an experience kind, and
     * every reason the contact validator can return. Each one is a machine
     * value the shell has to print as a word, so each one needs its word in
     * both languages.
     */
    public function testEveryCanonicalVocabularyHasADisplayNameInBothLanguages(): void
    {
        $keys = [];

        foreach (ProjectStatus::cases() as $status) {
            if ($status->isSubstantiated()) {
                $keys[] = 'content.status.' . $status->value;
            }
        }

        foreach (SkillCategory::cases() as $category) {
            $keys[] = 'content.skillCategory.' . $category->value;
        }

        foreach (ExperienceKind::cases() as $kind) {
            $keys[] = 'content.experienceKind.' . $kind->value;
        }

        foreach (ContactValidator::REASONS as $reason) {
            $keys[] = 'contact.error.' . $reason;
        }

        foreach ($keys as $key) {
            self::assertTrue(Translations::has($key), $key);

            foreach (Locale::supported() as $locale) {
                self::assertNotSame('', (new Translator($locale))->text($key));
            }
        }
    }

    /**
     * No placeholder is ever left visible.
     *
     * A `{name}` that reaches a page is the same class of defect as a raw key:
     * the interface showing its own implementation. Every entry that declares a
     * placeholder declares it in both languages, so a call site that fills one
     * fills the other.
     */
    public function testPlaceholdersAreDeclaredIdenticallyInBothLanguages(): void
    {
        foreach (Translations::all() as $key => $entry) {
            $french = self::placeholdersIn($entry['fr']);
            $english = self::placeholdersIn($entry['en']);

            self::assertSame($french, $english, $key . ' must take the same placeholders in both languages');
        }
    }

    public function testSubstitutionFillsEveryPlaceholderItIsGiven(): void
    {
        $translator = new Translator(Locale::En);

        self::assertSame(
            'A name can be at most 120 characters.',
            $translator->text('contact.error.name.tooLong', ['max' => 120])
        );

        self::assertStringNotContainsString('{', $translator->text('seo.about.title', ['name' => 'Someone']));
    }

    /**
     * A key the catalog does not declare raises. It is never printed as itself
     * and never quietly answered in the other language — the two failure modes
     * this policy exists to make impossible.
     */
    public function testAnUndeclaredKeyRaisesRatherThanReachingAVisitor(): void
    {
        $this->expectException(MissingTranslationException::class);
        $this->expectExceptionMessageMatches('/not.a.declared.key/');

        (new Translator(Locale::Fr))->text('not.a.declared.key');
    }

    /**
     * Every key literal passed to `Translator::text()` anywhere in the sources
     * or the skins, mapped to the file it was found in.
     *
     * @return array<string, string>
     */
    private static function keysUsedInSources(): array
    {
        $found = [];

        foreach (['src', 'resources/skins'] as $relative) {
            $directory = self::root() . '/' . $relative;

            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                // Only literal keys. A key composed at runtime — the canonical
                // vocabularies — is covered by its own test above, from the
                // enum rather than from a regular expression.
                // The trailing `,` or `)` is what makes this a *whole* key:
                // `->text('contact.error.' . $reason)` is a key composed at
                // runtime and is covered by its own test, from the enum rather
                // than from a regular expression.
                preg_match_all(
                    "/->text\(\s*'([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)*)'\s*[,)]/",
                    $contents,
                    $matches
                );

                foreach ($matches[1] as $key) {
                    $found[$key] = $relative . '/' . $file->getFilename();
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function placeholdersIn(string $text): array
    {
        preg_match_all('/\{([a-zA-Z]+)\}/', $text, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
