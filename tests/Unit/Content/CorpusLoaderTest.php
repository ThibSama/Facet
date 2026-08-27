<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Content;

use Facet\Content\ContentSchema;
use Facet\Content\CorpusLoader;
use Facet\Content\Exception\DuplicateSlugException;
use Facet\Content\Exception\InvalidContentException;
use Facet\Content\Exception\UnsupportedSchemaVersionException;
use PHPUnit\Framework\TestCase;

/**
 * Enforcement at the boundary: the loader is where untrusted stored content
 * becomes typed content, so every rejection is proven here.
 */
final class CorpusLoaderTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/facet-content-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0777, true));
        $this->directory = $directory;

        $this->write('profile.json', ['profile' => self::validProfile()]);
        $this->write('projects.json', ['projects' => [self::validProject('alpha')]]);
        $this->write('skills.json', ['skills' => [self::validSkill('beta')]]);
        $this->write('experiences.json', ['experiences' => [self::validExperience('gamma')]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function write(string $filename, array $document, ?string $schemaVersion = null): void
    {
        $payload = array_merge(['schemaVersion' => $schemaVersion ?? ContentSchema::VERSION], $document);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($json);
        file_put_contents($this->directory . '/' . $filename, $json);
    }

    private function writeRaw(string $filename, string $contents): void
    {
        file_put_contents($this->directory . '/' . $filename, $contents);
    }

    private function loader(): CorpusLoader
    {
        return new CorpusLoader($this->directory);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validProfile(): array
    {
        return [
            'name' => 'Fixture Person',
            'headline' => 'Fixture headline',
            'location' => 'Fixture location',
            'summary' => 'Fixture summary.',
            'focusAreas' => ['fixture area'],
            'links' => [],
            'portrait' => ['source' => null, 'description' => 'Fixture portrait.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validProject(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Fixture Project',
            'summary' => 'Fixture summary.',
            'context' => 'Fixture context.',
            'role' => 'Fixture role.',
            'technologies' => ['Fixture Tech'],
            'concepts' => ['fixture concept'],
            'status' => 'completed',
            'outcomes' => ['Fixture outcome.'],
            'period' => ['start' => '2024', 'end' => '2024'],
            'links' => [],
            'media' => ['source' => null, 'description' => 'Fixture media.'],
            'featured' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validSkill(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Fixture Skill',
            'category' => 'tooling',
            'summary' => 'Fixture skill summary.',
            'links' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validExperience(string $slug): array
    {
        return [
            'slug' => $slug,
            'kind' => 'education',
            'title' => 'Fixture Title',
            'organisation' => 'Fixture Organisation',
            'location' => 'Fixture Location',
            'period' => ['start' => '2020', 'end' => '2021'],
            'summary' => 'Fixture summary.',
            'highlights' => [],
            'links' => [],
        ];
    }

    public function testLoadsAValidFixtureCorpus(): void
    {
        $corpus = $this->loader()->load();

        self::assertSame('Fixture Person', $corpus->profile()->name());
        self::assertCount(1, $corpus->projects());
        self::assertCount(1, $corpus->skills());
        self::assertCount(1, $corpus->experiences());
    }

    public function testDuplicateSlugsFailAtLoad(): void
    {
        $this->write('projects.json', [
            'projects' => [self::validProject('clash'), self::validProject('clash')],
        ]);

        $this->expectException(DuplicateSlugException::class);
        $this->expectExceptionMessageMatches('/Duplicate slug "clash"/');

        $this->loader()->load();
    }

    public function testMalformedSlugFailsAtLoadWithItsReason(): void
    {
        $this->write('projects.json', ['projects' => [self::validProject('Not A Slug')]]);

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/contains uppercase characters/');

        $this->loader()->load();
    }

    public function testMalformedSlugFailureIsDeterministic(): void
    {
        $this->write('projects.json', ['projects' => [self::validProject('bad--slug')]]);

        $messages = [];

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->loader()->load();
            } catch (InvalidContentException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(3, $messages);
        self::assertCount(1, array_unique($messages));
        self::assertStringContainsString('consecutive hyphens', $messages[0]);
    }

    public function testMissingFileFails(): void
    {
        unlink($this->directory . '/projects.json');

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/missing or unreadable/');

        $this->loader()->load();
    }

    public function testInvalidJsonFails(): void
    {
        $this->writeRaw('skills.json', '{ not json');

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/does not contain a JSON object/');

        $this->loader()->load();
    }

    public function testWrongSchemaMajorFails(): void
    {
        $major = (int) explode('.', ContentSchema::VERSION)[0];
        $this->write('profile.json', ['profile' => self::validProfile()], ($major + 1) . '.0.0');

        $this->expectException(UnsupportedSchemaVersionException::class);

        $this->loader()->load();
    }

    public function testMissingRequiredKeyFails(): void
    {
        $project = self::validProject('alpha');
        unset($project['context']);
        $this->write('projects.json', ['projects' => [$project]]);

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/missing required key "context"/');

        $this->loader()->load();
    }

    public function testUnknownEnumValueFailsWithTheAllowedSet(): void
    {
        $project = self::validProject('alpha');
        $project['status'] = 'shipped';
        $this->write('projects.json', ['projects' => [$project]]);

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/expected one of: in-progress, completed, archived/');

        $this->loader()->load();
    }

    public function testInvalidLinkFails(): void
    {
        $project = self::validProject('alpha');
        $project['links'] = [['label' => 'Broken', 'url' => '/relative', 'type' => 'repository']];
        $this->write('projects.json', ['projects' => [$project]]);

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/not an absolute http\(s\) URL/');

        $this->loader()->load();
    }

    public function testProjectPeriodMayBeNull(): void
    {
        $project = self::validProject('alpha');
        $project['period'] = null;
        $this->write('projects.json', ['projects' => [$project]]);

        $projects = $this->loader()->load()->projects();

        self::assertNull($projects[0]->period(), 'A project with no substantiated date is valid');
    }

    public function testMediaSourceMayBeNullButDescriptionMayNot(): void
    {
        $project = self::validProject('alpha');
        $project['media'] = ['source' => null, 'description' => ''];
        $this->write('projects.json', ['projects' => [$project]]);

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/must be a non-empty string/');

        $this->loader()->load();
    }

    public function testWrongTypeFails(): void
    {
        $project = self::validProject('alpha');
        $project['technologies'] = 'PHP';
        $this->write('projects.json', ['projects' => [$project]]);

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/must be an array/');

        $this->loader()->load();
    }

    public function testFeaturedMustBeBoolean(): void
    {
        $project = self::validProject('alpha');
        $project['featured'] = 'yes';
        $this->write('projects.json', ['projects' => [$project]]);

        $this->expectException(InvalidContentException::class);
        $this->expectExceptionMessageMatches('/must be a boolean/');

        $this->loader()->load();
    }
}
