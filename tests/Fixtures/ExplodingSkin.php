<?php

declare(strict_types=1);

namespace Facet\Tests\Fixtures;

use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRegistry;

/**
 * Registries built around templates that fail on purpose.
 *
 * Disclosure safety is only convincing if something actually throws inside the
 * render path carrying values that must never surface. These fixtures are that
 * something: one skin whose page view explodes, and one whose error view
 * explodes as well.
 */
final class ExplodingSkin
{
    /** Not a real credential — it exists to be searched for in a response body. */
    public const FAKE_SECRET = 'sk_live_FACET_FAKE_SECRET_9f3a';

    /** Not a real path — same purpose. */
    public const FAKE_PATH = '/srv/facet/config/production-secrets.env';

    public const EXPLODING = 'exploding';

    public const EXPLODING_ERROR = 'exploding-error';

    public static function registry(string $id = self::EXPLODING): SkinRegistry
    {
        return SkinRegistry::create([self::skin($id)], $id);
    }

    public static function skin(string $id = self::EXPLODING): SkinDefinition
    {
        return SkinDefinition::define(
            $id,
            $id,
            'tests/Fixtures/skins/' . $id . '/views',
            ['tests/Fixtures/skins/' . $id . '/skin.ts']
        );
    }
}
