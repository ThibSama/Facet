<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Content;

use Facet\Content\Experience;
use Facet\Content\Profile;
use Facet\Content\Project;
use Facet\Content\Skill;
use Facet\Content\TextualEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Guards the shape of the four shared content structures.
 *
 * These are the types every future skin consumes. If a presentation concern
 * ever leaks into them, skins stop being interchangeable — so the absence of
 * such fields is asserted, not merely intended.
 */
final class SharedStructureTest extends TestCase
{
    /**
     * Tokens that betray a presentation concern living in shared content.
     *
     * @var list<string>
     */
    private const SKIN_TOKENS = [
        'skin', 'theme', 'css', 'class', 'style', 'color', 'colour', 'palette',
        'layout', 'template', 'variant', 'animation', 'transition', 'markup',
        'html', 'render', 'view', 'width', 'height', 'font', 'icon', 'gradient',
        'darkmode', 'breakpoint', 'zindex', 'sprite',
    ];

    /**
     * @return array<string, array{class-string}>
     */
    public static function sharedStructures(): array
    {
        return [
            'Profile' => [Profile::class],
            'Project' => [Project::class],
            'Skill' => [Skill::class],
            'Experience' => [Experience::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('sharedStructures')]
    public function testHasNoSkinSpecificProperty(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertNameIsSkinNeutral($property->getName(), $class . '::$' . $property->getName());
        }

        self::assertNotEmpty($reflection->getProperties(), $class . ' must actually carry data');
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('sharedStructures')]
    public function testHasNoSkinSpecificAccessor(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $this->assertNameIsSkinNeutral($method->getName(), $class . '::' . $method->getName() . '()');
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('sharedStructures')]
    public function testIsImmutable(string $class): void
    {
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->isFinal(), $class . ' must be final');

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue(
                $property->isPrivate(),
                $class . '::$' . $property->getName() . ' must be private'
            );
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/^(set|add|remove|with|append)[A-Z]/',
                $method->getName(),
                $class . '::' . $method->getName() . '() suggests mutation'
            );
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('sharedStructures')]
    public function testIsTraversableAsPlainText(string $class): void
    {
        self::assertTrue(
            (new ReflectionClass($class))->implementsInterface(TextualEntry::class),
            $class . ' must be walkable as plain text'
        );
    }

    public function testProjectCoversTheRequiredEditorialPayload(): void
    {
        $reflection = new ReflectionClass(Project::class);

        // summary, context, role, stack, results/status, links and optional media.
        $required = [
            'slug', 'name', 'summary', 'context', 'role',
            'technologies', 'concepts', 'status', 'outcomes',
            'period', 'links', 'media',
        ];

        $properties = array_map(
            static fn (ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties()
        );

        foreach ($required as $field) {
            self::assertContains($field, $properties, 'Project must carry ' . $field);
            self::assertTrue($reflection->hasMethod($field), 'Project must expose ' . $field . '()');
        }
    }

    public function testProjectKeepsTechnologiesAndConceptsSeparate(): void
    {
        $reflection = new ReflectionClass(Project::class);

        self::assertTrue($reflection->hasMethod('technologies'));
        self::assertTrue($reflection->hasMethod('concepts'));

        foreach (['technologies', 'concepts'] as $accessor) {
            $type = $reflection->getMethod($accessor)->getReturnType();

            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame('array', $type->getName(), $accessor . '() must return a list');
        }
    }

    private function assertNameIsSkinNeutral(string $name, string $where): void
    {
        $normalised = strtolower(str_replace(['_', '-'], '', $name));

        foreach (self::SKIN_TOKENS as $token) {
            self::assertStringNotContainsString(
                $token,
                $normalised,
                sprintf('%s leaks the presentation concern "%s" into shared content', $where, $token)
            );
        }
    }
}
