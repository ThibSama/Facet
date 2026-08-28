<?php

declare(strict_types=1);

namespace Facet\Routing;

use InvalidArgumentException;

/**
 * The canonical, versioned list of Facet routes.
 *
 * This is the contract every future dispatcher, navigation builder and sitemap
 * generator reads from. Adding, renaming or re-scoping a route is a deliberate
 * change to this file and is caught by the routing tests.
 */
final class RouteCatalog
{
    /**
     * Bumped whenever the route contract changes in a way consumers must react
     * to (a route added, removed, renamed, or its visibility changed).
     */
    public const VERSION = '1.2.0';

    public const HOME = 'home';
    public const PROJECTS_INDEX = 'projects.index';
    public const PROJECTS_SHOW = 'projects.show';
    public const ABOUT = 'about';
    public const CONTACT = 'contact';
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const ADMIN_DASHBOARD = 'admin.dashboard';
    public const ADMIN_MESSAGES = 'admin.messages';
    public const CLIENT_AREA = 'client';

    /** @var array<string, RouteDefinition>|null */
    private static ?array $routes = null;

    /**
     * @return array<string, RouteDefinition> keyed by route name
     */
    public static function all(): array
    {
        if (self::$routes === null) {
            self::$routes = self::build();
        }

        return self::$routes;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /**
     * @throws InvalidArgumentException when no such route is declared
     */
    public static function get(string $name): RouteDefinition
    {
        $routes = self::all();

        if (!isset($routes[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown route "%s". Declared routes: %s.',
                $name,
                implode(', ', array_keys($routes))
            ));
        }

        return $routes[$name];
    }

    /**
     * @return list<RouteDefinition>
     */
    public static function withVisibility(Visibility $visibility): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (RouteDefinition $route): bool => $route->visibility() === $visibility
        ));
    }

    /**
     * Routes a crawler or unauthenticated visitor may reach.
     *
     * @return list<RouteDefinition>
     */
    public static function publiclyReachable(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (RouteDefinition $route): bool => $route->visibility()->isPubliclyReachable()
        ));
    }

    /**
     * @return array<string, RouteDefinition>
     */
    private static function build(): array
    {
        $definitions = [
            RouteDefinition::define(
                self::HOME,
                '/',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.home'
            ),
            RouteDefinition::define(
                self::PROJECTS_INDEX,
                '/projects',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.projects.index'
            ),
            RouteDefinition::define(
                self::PROJECTS_SHOW,
                '/projects/{slug}',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.projects.show',
                [RouteParameter::slug()]
            ),
            RouteDefinition::define(
                self::ABOUT,
                '/about',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.about'
            ),
            RouteDefinition::define(
                self::CONTACT,
                '/contact',
                [HttpMethod::Get, HttpMethod::Post],
                Visibility::Public,
                DataSource::MessageStore,
                'page.contact'
            ),
            RouteDefinition::define(
                self::LOGIN,
                '/login',
                [HttpMethod::Get, HttpMethod::Post],
                Visibility::Guest,
                DataSource::AuthSession,
                'page.login'
            ),
            RouteDefinition::define(
                self::LOGOUT,
                '/logout',
                // POST only, and that is a security property rather than a
                // stylistic one. A GET /logout can be triggered by any image
                // tag on any page on the internet, so it is a cross-site
                // request that logs a person out — harmless-sounding, and still
                // an action performed without intent. As a POST it goes through
                // the same central CSRF check as every other private mutation.
                [HttpMethod::Post],
                Visibility::Authenticated,
                DataSource::AuthSession,
                'page.logout'
            ),
            RouteDefinition::define(
                self::ADMIN_DASHBOARD,
                '/admin',
                [HttpMethod::Get],
                Visibility::Admin,
                DataSource::ContentCorpus,
                'page.admin.dashboard'
            ),
            RouteDefinition::define(
                self::ADMIN_MESSAGES,
                '/admin/messages',
                [HttpMethod::Get, HttpMethod::Post],
                Visibility::Admin,
                DataSource::MessageStore,
                'page.admin.messages'
            ),
            RouteDefinition::define(
                self::CLIENT_AREA,
                '/client',
                [HttpMethod::Get],
                Visibility::Client,
                DataSource::AuthSession,
                'page.client'
            ),
        ];

        $routes = [];

        foreach ($definitions as $definition) {
            if (isset($routes[$definition->name()])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate route name "%s" in the catalog.',
                    $definition->name()
                ));
            }

            $routes[$definition->name()] = $definition;
        }

        return $routes;
    }
}
