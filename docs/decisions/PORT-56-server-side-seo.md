# PORT-56 — server-side SEO policy

Facet emits all metadata, structured data, the sitemap and robots policy from
PHP. None of them depends on client-side JavaScript.

## Canonical origin

`APP_URL` is the only canonical-origin authority. It must be an absolute HTTP
or HTTPS URL without credentials, query or fragment. A configured base path is
preserved and joined to canonical RouteCatalog paths without duplicate slashes.
`Host` and `X-Forwarded-*` are never consulted.

Production rejects localhost, loopback and private IP origins. Public HTML
continues to render when `APP_URL` is missing or invalid, but canonical, social
URL and JSON-LD tags are omitted. The URL-dependent `/sitemap.xml` and
`/robots.txt` endpoints fail closed instead of publishing an invented origin.

## Image fallback

The repository contains no verified, suitable generic social-sharing image and
canonical project media may be absent. Facet therefore omits `og:image` and
`twitter:image`; it never promotes a skin placeholder or fabricates project
media. Those tags may be added only when canonical content or a reviewed shared
asset supplies a real image.

## Structured-data claims

Home emits `WebSite` and `Person`; About emits `Person`; the project index emits
`CollectionPage` with an `ItemList`; Contact emits `WebPage`; each project emits
the conservative `CreativeWork` type. Person values come from `profile.json`.
Project schema contains only name, summary, canonical URL and non-empty
technology values from the canonical project record. Dates, outcomes, clients,
authors, images and absent technologies are not inferred.

## Crawl policy

The sitemap expands the five indexable RouteCatalog sections with Corpus
project slugs: home, projects index, every project detail, about and contact.
Technical, guest, authenticated, private, mutation and query-variant URLs are
excluded. `robots.txt` stays permissive so crawlers can fetch assets and observe
explicit `noindex` directives; it references the absolute sitemap URL.
