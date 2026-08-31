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
    public const VERSION = '2.0.0';

    public const HOME = 'home';
    public const PROJECTS_INDEX = 'projects.index';
    public const PROJECTS_SHOW = 'projects.show';
    public const ABOUT = 'about';
    public const CONTACT = 'contact';

    /**
     * The unprefixed entry routes.
     *
     * Since PORT-137 the canonical public URL always names its language, so `/`
     * and `/projects` are not pages: they are the addresses a link written
     * before the split, a bookmark, or somebody typing the domain still
     * arrives at. Each resolves a preferred language and redirects to the
     * canonical localized URL, so there is exactly one indexable spelling of
     * every page and no unprefixed duplicate of it.
     *
     * They accept GET only. Locale negotiation belongs to a safe request:
     * redirecting a POST to a language the submitter did not choose would move
     * a submission between two URLs, and the contact form is posted to the
     * localized route the page it was rendered on already names.
     */
    public const HOME_ENTRY = 'entry.home';
    public const PROJECTS_INDEX_ENTRY = 'entry.projects.index';
    public const PROJECTS_SHOW_ENTRY = 'entry.projects.show';
    public const ABOUT_ENTRY = 'entry.about';
    public const CONTACT_ENTRY = 'entry.contact';
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const ADMIN_DASHBOARD = 'admin.dashboard';
    public const ADMIN_MESSAGES = 'admin.messages';
    public const CLIENT_AREA = 'client';
    public const SITEMAP = 'technical.sitemap';
    public const ROBOTS = 'technical.robots';

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
     * The public routes that carry a language, keyed by their entry route.
     *
     * One map rather than a rule spelled out in three places: the redirect
     * handler, the language switch and the sitemap all read the pairing from
     * here, so a route cannot acquire a localized form without acquiring its
     * unprefixed entry and its counterpart at the same time.
     *
     * @var array<string, string>
     */
    private const LOCALIZED_BY_ENTRY = [
        self::HOME_ENTRY => self::HOME,
        self::PROJECTS_INDEX_ENTRY => self::PROJECTS_INDEX,
        self::PROJECTS_SHOW_ENTRY => self::PROJECTS_SHOW,
        self::ABOUT_ENTRY => self::ABOUT,
        self::CONTACT_ENTRY => self::CONTACT,
    ];

    /**
     * The canonical localized route an unprefixed entry route leads to, or null
     * when the route is not an entry route.
     */
    public static function localizedFor(string $entryName): ?string
    {
        return self::LOCALIZED_BY_ENTRY[$entryName] ?? null;
    }

    public static function isEntry(string $name): bool
    {
        return isset(self::LOCALIZED_BY_ENTRY[$name]);
    }

    /**
     * Every route that renders a localized public page.
     *
     * @return list<string>
     */
    public static function localizedNames(): array
    {
        return array_values(self::LOCALIZED_BY_ENTRY);
    }

    /**
     * @return array<string, RouteDefinition>
     */
    private static function build(): array
    {
        $definitions = [
            RouteDefinition::define(
                self::HOME,
                '/{locale}',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.home',
                [RouteParameter::locale()]
            ),
            RouteDefinition::define(
                self::PROJECTS_INDEX,
                '/{locale}/projects',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.projects.index',
                [RouteParameter::locale()]
            ),
            RouteDefinition::define(
                self::PROJECTS_SHOW,
                '/{locale}/projects/{slug}',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.projects.show',
                [RouteParameter::locale(), RouteParameter::slug()]
            ),
            RouteDefinition::define(
                self::ABOUT,
                '/{locale}/about',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'page.about',
                [RouteParameter::locale()]
            ),
            RouteDefinition::define(
                self::CONTACT,
                '/{locale}/contact',
                [HttpMethod::Get, HttpMethod::Post],
                Visibility::Public,
                DataSource::MessageStore,
                'page.contact',
                [RouteParameter::locale()]
            ),
            RouteDefinition::define(
                self::HOME_ENTRY,
                '/',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::None,
                'redirect.locale'
            ),
            RouteDefinition::define(
                self::PROJECTS_INDEX_ENTRY,
                '/projects',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::None,
                'redirect.locale'
            ),
            RouteDefinition::define(
                self::PROJECTS_SHOW_ENTRY,
                '/projects/{slug}',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::None,
                'redirect.locale',
                [RouteParameter::slug()]
            ),
            RouteDefinition::define(
                self::ABOUT_ENTRY,
                '/about',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::None,
                'redirect.locale'
            ),
            RouteDefinition::define(
                self::CONTACT_ENTRY,
                '/contact',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::None,
                'redirect.locale'
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
            RouteDefinition::define(
                self::SITEMAP,
                '/sitemap.xml',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::ContentCorpus,
                'technical.sitemap'
            ),
            RouteDefinition::define(
                self::ROBOTS,
                '/robots.txt',
                [HttpMethod::Get],
                Visibility::Public,
                DataSource::None,
                'technical.robots'
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
