<?php

declare(strict_types=1);

namespace Facet\Seo;

use Facet\Content\Corpus;
use Facet\Content\Project;
use Facet\Http\Request;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;

/** Builds factual metadata from the route contract and canonical corpus. */
final class SeoMetadataFactory
{
    public function __construct(private ?SiteUrl $siteUrl, private string $appName)
    {
    }

    public function forRoute(
        RouteDefinition $route,
        Request $request,
        Corpus $corpus,
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
                'Projets — ' . $profile->name(),
                'Les projets de ' . $profile->name() . ', présentés à partir de leurs informations vérifiées.',
                'website',
            ],
            RouteCatalog::PROJECTS_SHOW => [
                ($project?->name() ?? 'Projet') . ' — Projet de ' . $profile->name(),
                $project?->summary(),
                'article',
            ],
            RouteCatalog::ABOUT => [
                'À propos de ' . $profile->name(),
                $profile->headline() . ' en ' . $profile->location() . '. ' . $profile->summary(),
                'profile',
            ],
            RouteCatalog::CONTACT => [
                'Contacter ' . $profile->name(),
                'Formulaire de contact de ' . $profile->name() . ' et liens publics issus de son profil.',
                'website',
            ],
            default => [$this->fallbackTitle($route), null, 'website'],
        };

        $canonical = $indexable && $this->siteUrl !== null
            ? $this->siteUrl->absolute($route->toPath($project === null ? [] : ['slug' => $project->slug()->value()]))
            : null;

        return new SeoMetadata(
            $title,
            $description,
            $canonical,
            $indexable,
            $type,
            $canonical === null ? [] : $this->structuredData($route, $corpus, $project, $canonical, $title, $description)
        );
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
        ?string $description
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
                    'url' => $this->siteUrl?->absolute('/projects/' . $item->slug()->value()),
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
