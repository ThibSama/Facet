<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Content;

use Facet\Content\Exception\InvalidContentException;
use Facet\Content\Link;
use Facet\Content\LinkType;
use Facet\Content\Media;
use Facet\Content\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectTest extends TestCase
{
    public function testMediaWithoutSourceResolvesToAFallback(): void
    {
        $media = Media::pending('A screenshot is not available yet.');

        self::assertFalse($media->hasSource());
        self::assertTrue($media->isFallback());
        self::assertNull($media->source());
        self::assertSame(Media::FALLBACK_REFERENCE, $media->reference());
        self::assertSame('A screenshot is not available yet.', $media->description());
    }

    public function testMediaWithSourceKeepsIt(): void
    {
        $media = Media::create('projects/kushim/overview', 'Kushim overview');

        self::assertTrue($media->hasSource());
        self::assertFalse($media->isFallback());
        self::assertSame('projects/kushim/overview', $media->reference());
    }

    public function testMediaAlwaysRequiresATextualDescription(): void
    {
        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/textual description is required/');

        Media::create(null, '   ');
    }

    public function testMediaSourceMustBeNullOrMeaningful(): void
    {
        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/must be null or non-empty/');

        Media::create('  ', 'description');
    }

    public function testMediaFallbackReferenceIsSkinIndependent(): void
    {
        // Not a path, not an extension: a skin maps it to its own asset.
        self::assertStringNotContainsString('/', Media::FALLBACK_REFERENCE);
        self::assertStringNotContainsString('.', Media::FALLBACK_REFERENCE);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'relative' => ['/projects/kushim'],
            'no scheme' => ['github.com/ThibSama'],
            'javascript' => ['javascript:alert(1)'],
            'mailto' => ['mailto:someone@example.com'],
            'ftp' => ['ftp://example.com/file'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function testLinkRejectsNonAbsoluteHttpUrls(string $url): void
    {
        self::assertFalse(Link::isAbsoluteHttpUrl($url));

        $this->expectException(InvalidContentException::class);
        Link::create('Label', $url, LinkType::Website);
    }

    public function testLinkAcceptsAbsoluteHttpUrls(): void
    {
        $link = Link::create('Repository', 'https://github.com/ThibSama/Facet', LinkType::Repository);

        self::assertSame('Repository', $link->label());
        self::assertSame('https://github.com/ThibSama/Facet', $link->url());
        self::assertSame(LinkType::Repository, $link->type());
    }

    public function testLinkRejectsAnEmptyLabel(): void
    {
        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/label must not be empty/');

        Link::create('  ', 'https://example.com/', LinkType::Website);
    }

    public function testPeriodAcceptsYearAndYearMonth(): void
    {
        self::assertSame('2024', Period::create('2024', null)->start());
        self::assertSame('2026-06', Period::create('2026-06', '2026-08')->start());
        self::assertSame('2026-08', Period::create('2026-06', '2026-08')->end());
        self::assertTrue(Period::ongoingFrom('2025')->isOngoing());
        self::assertSame(2025, Period::ongoingFrom('2025')->startYear());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBounds(): array
    {
        return [
            'day precision' => ['2024-03-18'],
            'month 13' => ['2024-13'],
            'month 00' => ['2024-00'],
            'two digits' => ['24'],
            'words' => ['spring 2024'],
        ];
    }

    #[DataProvider('malformedBounds')]
    public function testPeriodRejectsMalformedBounds(string $bound): void
    {
        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/must be YYYY or YYYY-MM/');

        Period::create($bound, null);
    }

    public function testPeriodRejectsAReversedRange(): void
    {
        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/precedes start/');

        Period::create('2026', '2024');
    }
}
