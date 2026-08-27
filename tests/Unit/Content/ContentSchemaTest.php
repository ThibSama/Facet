<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Content;

use Facet\Content\ContentSchema;
use Facet\Content\Exception\InvalidContentException;
use Facet\Content\Exception\UnsupportedSchemaVersionException;
use PHPUnit\Framework\TestCase;

final class ContentSchemaTest extends TestCase
{
    public function testDeclaresASemanticVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', ContentSchema::VERSION);
    }

    public function testAcceptsItsOwnVersion(): void
    {
        ContentSchema::assertSupported(ContentSchema::VERSION, 'test');
        $this->addToAssertionCount(1);
    }

    public function testAcceptsAdditiveMinorAndPatchVersions(): void
    {
        $major = explode('.', ContentSchema::VERSION)[0];

        ContentSchema::assertSupported($major . '.9.4', 'test');
        $this->addToAssertionCount(1);
    }

    public function testRejectsADifferentMajorVersion(): void
    {
        $major = (int) explode('.', ContentSchema::VERSION)[0];

        $this->expectException(UnsupportedSchemaVersionException::class);
        $this->expectExceptionMessageMatches('/declares schema version/');

        ContentSchema::assertSupported(($major + 1) . '.0.0', 'test');
    }

    public function testRejectsAMissingDeclaration(): void
    {
        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/missing required key "schemaVersion"/');

        ContentSchema::assertSupported(null, 'test');
    }

    public function testRejectsANonSemanticDeclaration(): void
    {
        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/is not a semantic version/');

        ContentSchema::assertSupported('v1', 'test');
    }

    public function testRejectsANonStringDeclaration(): void
    {
        $this->expectException(InvalidContentException::class);

        ContentSchema::assertSupported(1, 'test');
    }
}
