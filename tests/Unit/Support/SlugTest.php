<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Support;

use Facet\Support\InvalidSlugException;
use Facet\Support\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function validSlugs(): array
    {
        return [
            'single word' => ['kushim'],
            'hyphenated' => ['eszter-gyori'],
            'with digits' => ['portfolio-2024'],
            'digits only' => ['2024'],
            'minimum length' => ['ab'],
            'maximum length' => [str_repeat('a', Slug::MAX_LENGTH)],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedSlugs(): array
    {
        return [
            'empty' => ['', 'it is empty'],
            'too short' => ['a', 'it is shorter than 2 characters'],
            'too long' => [str_repeat('a', Slug::MAX_LENGTH + 1), 'it is longer than 64 characters'],
            'leading space' => [' kushim', 'it has leading or trailing whitespace'],
            'trailing space' => ['kushim ', 'it has leading or trailing whitespace'],
            'uppercase' => ['Kushim', 'it contains uppercase characters'],
            'leading hyphen' => ['-kushim', 'it starts or ends with a hyphen'],
            'trailing hyphen' => ['kushim-', 'it starts or ends with a hyphen'],
            'double hyphen' => ['kushim--api', 'it contains consecutive hyphens'],
            'underscore' => ['kushim_api', 'it contains characters outside a-z, 0-9 and hyphen'],
            'slash' => ['kushim/api', 'it contains characters outside a-z, 0-9 and hyphen'],
            'accented' => ['héros', 'it contains characters outside a-z, 0-9 and hyphen'],
            'space inside' => ['kushim api', 'it contains characters outside a-z, 0-9 and hyphen'],
        ];
    }

    #[DataProvider('validSlugs')]
    public function testAcceptsCanonicalSlug(string $candidate): void
    {
        self::assertTrue(Slug::isValid($candidate));
        self::assertSame($candidate, Slug::fromString($candidate)->value());
    }

    #[DataProvider('malformedSlugs')]
    public function testRejectsMalformedSlug(string $candidate, string $expectedReason): void
    {
        self::assertFalse(Slug::isValid($candidate));
        self::assertSame($expectedReason, Slug::rejectionReason($candidate));
    }

    #[DataProvider('malformedSlugs')]
    public function testMalformedSlugThrowsDeterministically(string $candidate, string $expectedReason): void
    {
        $first = null;
        $second = null;

        try {
            Slug::fromString($candidate);
        } catch (InvalidSlugException $e) {
            $first = $e->getMessage();
        }

        try {
            Slug::fromString($candidate);
        } catch (InvalidSlugException $e) {
            $second = $e->getMessage();
        }

        self::assertNotNull($first, 'A malformed slug must throw');
        self::assertSame($first, $second, 'The same input must always produce the same message');
        self::assertStringContainsString($expectedReason, (string) $first);
    }

    public function testSlugIsStableAndComparable(): void
    {
        $a = Slug::fromString('kushim');
        $b = Slug::fromString('kushim');
        $c = Slug::fromString('facet');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
        self::assertSame('kushim', (string) $a);
    }

    public function testGrammarIsDescribedInFailures(): void
    {
        $this->expectExceptionMessageMatches('/lowercase letters, digits and single hyphens/');
        Slug::fromString('NOPE');
    }
}
