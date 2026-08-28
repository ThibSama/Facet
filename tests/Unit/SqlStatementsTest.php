<?php

declare(strict_types=1);

namespace Facet\Tests\Unit;

use Facet\Database\Migration\SqlStatements;
use PHPUnit\Framework\TestCase;

final class SqlStatementsTest extends TestCase
{
    public function testSplitsOnStatementTerminators(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            SqlStatements::split('SELECT 1; SELECT 2;')
        );
    }

    public function testKeepsATrailingStatementWithoutASemicolon(): void
    {
        self::assertSame(['SELECT 1'], SqlStatements::split('SELECT 1'));
    }

    public function testDropsLineComments(): void
    {
        $sql = "-- a leading note\nSELECT 1; # trailing note\nSELECT 2;";

        self::assertSame(['SELECT 1', 'SELECT 2'], SqlStatements::split($sql));
    }

    public function testDropsBlockComments(): void
    {
        self::assertSame(['SELECT 1'], SqlStatements::split('/* note; with a semicolon */ SELECT 1;'));
    }

    public function testDoesNotSplitInsideAStringLiteral(): void
    {
        // The case a naive explode(';') gets wrong.
        self::assertSame(
            ["INSERT INTO t VALUES ('a;b')"],
            SqlStatements::split("INSERT INTO t VALUES ('a;b');")
        );
    }

    public function testDoesNotSplitInsideAQuotedIdentifier(): void
    {
        self::assertSame(
            ['SELECT `weird;name` FROM t'],
            SqlStatements::split('SELECT `weird;name` FROM t;')
        );
    }

    public function testHandlesDoubledQuoteEscapes(): void
    {
        self::assertSame(
            ["SELECT 'it''s; fine'"],
            SqlStatements::split("SELECT 'it''s; fine';")
        );
    }

    public function testHandlesBackslashEscapes(): void
    {
        self::assertSame(
            ["SELECT 'a\\';b'"],
            SqlStatements::split("SELECT 'a\\';b';")
        );
    }

    public function testIgnoresEmptyStatements(): void
    {
        self::assertSame(['SELECT 1'], SqlStatements::split(";;\n SELECT 1; ;\n"));
    }

    public function testACommentOnlyFileYieldsNothing(): void
    {
        self::assertSame([], SqlStatements::split("-- nothing here\n/* nor here */\n"));
    }

    public function testRealMigrationsSplitIntoOneStatementEach(): void
    {
        $directory = dirname(__DIR__, 2) . '/database/migrations';

        foreach ((array) glob($directory . '/*.sql') as $file) {
            self::assertIsString($file);
            $sql = (string) file_get_contents($file);

            $statements = SqlStatements::split($sql);

            self::assertCount(1, $statements, basename($file) . ' holds exactly one statement');
            self::assertStringStartsWith('CREATE TABLE', $statements[0]);
        }
    }
}
