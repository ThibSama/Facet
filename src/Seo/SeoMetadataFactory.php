<?php

declare(strict_types=1);

namespace Facet\Seo;

use Facet\Content\Corpus;
use Facet\Content\Project;
use Facet\Http\Request;
use Facet\I18n\Locale;
use Facet\I18n\LocalizedRoutes;
use Facet\I18n\Translator;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;

/** Builds factual metadata from the route contract and canonical corpus. */
final class SeoMetadataFactory
{
    public function __construct(private ?SiteUrl $siteUrl, private string $appName)
    {
    }

    /**
     * The metadata for one rendered page, in the language it is rendered in.
     *
     * Titles and descriptions are chrome composed around canonical facts: the
     * sentence patterns come from the translation catalog and the values inside
     * them come from the corpus, so a localized description restates the same
     * facts in another language rather than making a different claim.
     *
     * The canonical URL is the page's own localized URL and never the
     * unprefixed entry route, which exists only to redirect. Alternates are
     * emitted for both languages plus `x-default`, and only for a public page
     * that actually has a counterpart.
     *
     * @param array<string, string> $parameters the parameters the router matched
     */
    public function forRoute(
        RouteDefinition $route,
        Request $request,
        Corpus $corpus,
        Locale $locale,
        Translator $translator,
        array $parameters = [],
        ?Project $project = null
    ): SeoMetadata {
        $profile = $corpus->profile();
        $indexable = $request->isMethod(HttpMethod::Get)
            && SeoRoutePolicy::isIndexable($route);

        [$title, $description, $type] = match ($route->name()) {
            RouteCatalog::HOME => [
                $profile->name() . ' — ' . $profile->headline(),
                $profile->summary(),
                'website',
            ],
            RouteCatalog::PROJECTS_INDEX => [
                $translator->text('seo.projects.title', ['name' => $profile->name()]),
                $translator->text('seo.projects.description', ['name' => $profile->name()]),
                'website',
            ],
            RouteCatalog::PROJECTS_SHOW => [
                $translator->text('seo.project.title', [
                    'project' => $project?->name() ?? $translator->text('seo.project.fallbackName'),
                    'name' => $profile->name(),
                ]),
                $project?->summary(),
                'article',
            ],
            RouteCatalog::ABOUT => [
                $translator->text('seo.about.title', ['name' => $profile->name()]),
                $translator->text('seo.about.description', [
                    'headline' => $profile->headline(),
                    'location' => $profile->location(),
                    'summary' => $profile->summary(),
                ]),
                'profile',
            ],
            RouteCatalog::CONTACT => [
                $translator->text('seo.contact.title', ['name' => $profile->name()]),
                $translator->text('seo.contact.description', ['name' => $profile->name()]),
                'website',
            ],
            default => [$this->fallbackTitle($route), null, 'website'],
        };

        // Route parameters other than the language: the same page in the other
        // language is the same project, so the slug travels and the locale does
        // not.
        $shared = $parameters;
        unset($shared['locale']);

        $canonical = $indexable && $this->siteUrl !== null
            ? $this->siteUrl->absolute(LocalizedRoutes::path($route->name(), $locale, $shared))
            : null;

        return new SeoMetadata(
            $title,
            $description,
            $canonical,
            $indexable,
            $type,
            $canonical === null ? [] : $this->structuredData($route, $corpus, $project, $canonical, $title, $description, $locale),
            $locale,
            $canonical === null ? [] : $this->alternates($route, $shared)
        );
    }

    /**
     * Both languages of this page, plus the language-neutral default.
     *
     * @param array<string, string> $shared route parameters other than the locale
     *
     * @return array<string, string>
     */
    private function alternates(RouteDefinition $route, array $shared): array
    {
        if ($this->siteUrl === null || !in_array($route->name(), RouteCatalog::localizedNames(), true)) {
            return [];
        }

        $alternates = [];

        foreach (Locale::supported() as $candidate) {
            $alternates[$candidate->value] = $this->siteUrl->absolute(
                LocalizedRoutes::path($route->name(), $candidate, $shared)
            );
        }

        // `x-default` is the French URL, built the same way rather than read
        // back out of the map: French is the language the corpus is written in
        // and the language an unprefixed entry falls back to, so it is the
        // deterministic answer to "this page, language unspecified".
        $alternates['x-default'] = $this->siteUrl->absolute(
            LocalizedRoutes::path($route->name(), Locale::default(), $shared)
        );

        return $alternates;
    }

    private function fallbackTitle(RouteDefinition $route): string
    {
        return match ($route->name()) {
            RouteCatalog::LOGIN => 'Sign in — ' . $this->appName,
            RouteCatalog::ADMIN_DASHBOARD => 'Admin — ' . $this->appName,
            RouteCatalog::ADMIN_MESSAGES => 'Messages — ' . $this->appName,
            RouteCatalog::CLIENT_AREA => 'Client area — ' . $this->appName,
            default => $this->appName,
        };
    }

    /** @return list<array<string, mixed>> */
    private function structuredData(
        RouteDefinition $route,
        Corpus $corpus,
        ?Project $project,
        string $canonical,
        string $title,
        ?string $description,
        Locale $locale
    ): array {
        $page = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'description' => $description,
            'url' => $canonical,
        ], static fn (mixed $value): bool => $value !== null);

        if ($route->name() === RouteCatalog::HOME) {
            return [[
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $this->appName,
                'url' => $canonical,
                'description' => $description,
            ], $this->person($corpus, $canonical)];
        }

        if ($route->name() === RouteCatalog::ABOUT) {
            return [$this->person($corpus, $canonical)];
        }

        if ($route->name() === RouteCatalog::PROJECTS_INDEX) {
            $items = [];

            foreach ($corpus->projects() as $position => $item) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $position + 1,
                    'name' => $item->name(),
                    'url' => $this->siteUrl?->absolute(LocalizedRoutes::path(
                        RouteCatalog::PROJECTS_SHOW,
                        $locale,
                        ['slug' => $item->slug()->value()]
                    )),
                ];
            }

            $page['@type'] = 'CollectionPage';
            $page['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => $items];

            return [$page];
        }

        if ($route->name() === RouteCatalog::PROJECTS_SHOW && $project !== null) {
            $work = [
                '@context' => 'https://schema.org',
                '@type' => 'CreativeWork',
                'name' => $project->name(),
                'description' => $project->summary(),
                'url' => $canonical,
            ];

            if ($project->technologies() !== []) {
                $work['keywords'] = $project->technologies();
            }

            return [$work];
        }

        return [$page];
    }

    /** @return array<string, mixed> */
    private function person(Corpus $corpus, string $url): array
    {
        $profile = $corpus->profile();
        $sameAs = array_map(
            static fn (\Facet\Content\Link $link): string => $link->url(),
            $profile->links()
        );

        $person = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $profile->name(),
            'description' => $profile->summary(),
            'jobTitle' => $profile->headline(),
            'homeLocation' => $profile->location(),
            'url' => $url,
        ];

        if ($sameAs !== []) {
            $person['sameAs'] = $sameAs;
        }

        return $person;
    }
}
