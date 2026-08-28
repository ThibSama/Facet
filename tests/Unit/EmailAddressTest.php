<?php

declare(strict_types=1);

namespace Facet\Tests\Unit;

use Facet\Support\EmailAddress;
use Facet\Support\InvalidEmailAddressException;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testNormalisationTrimsAndLowercases(): void
    {
        self::assertSame('ada@example.com', EmailAddress::normalise('  Ada@Example.COM '));
    }

    public function testNormalisationIsIdempotent(): void
    {
        $once = EmailAddress::normalise('  Ada@Example.COM ');

        self::assertSame($once, EmailAddress::normalise($once));
    }

    public function testAddressesDifferingOnlyByCaseNormaliseIdentically(): void
    {
        // This is the property the UNIQUE index depends on.
        self::assertSame(
            EmailAddress::normalise('ADA@EXAMPLE.COM'),
            EmailAddress::normalise('ada@example.com')
        );
    }

    public function testCanonicalRejectsAnEmptyAddress(): void
    {
        $this->expectException(InvalidEmailAddressException::class);
        EmailAddress::canonical('   ');
    }

    public function testCanonicalRejectsAMalformedAddress(): void
    {
        $this->expectException(InvalidEmailAddressException::class);
        EmailAddress::canonical('not-an-address');
    }

    public function testCanonicalRejectsAnOverlongAddress(): void
    {
        $long = str_repeat('a', 250) . '@example.com';

        $this->expectException(InvalidEmailAddressException::class);
        EmailAddress::canonical($long);
    }

    public function testCanonicalAcceptsAndNormalisesAValidAddress(): void
    {
        self::assertSame('ada.lovelace+work@example.co.uk', EmailAddress::canonical(' Ada.Lovelace+Work@Example.co.uk '));
    }

    public function testIsValidMirrorsCanonical(): void
    {
        self::assertTrue(EmailAddress::isValid('ada@example.com'));
        self::assertFalse(EmailAddress::isValid('nope'));
    }

    public function testMaxLengthMatchesTheColumnWidth(): void
    {
        self::assertSame(254, EmailAddress::MAX_LENGTH);
    }
}
