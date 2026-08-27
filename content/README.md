# Canonical content

This directory is the single source of truth for Facet's public content.

- It is **versioned in Git**, not stored in a database.
- It is **presentation-neutral**: no skin, template, colour or layout hint lives
  here. Skins consume the typed structures in `Facet\Content`, never these files
  directly.
- Every file declares the `schemaVersion` it was written against. Copy and media
  may change freely within a major version; changing the *shape* of content
  requires bumping `Facet\Content\ContentSchema::VERSION`.

| File               | Contains                                        |
| ------------------ | ----------------------------------------------- |
| `profile.json`     | The single identity record                      |
| `projects.json`    | Portfolio projects, resolved by `/projects/{slug}` |
| `skills.json`      | Technologies, tools and certifications          |
| `experiences.json` | Education, professional and volunteer entries    |

## Rules

1. **Only verifiable facts.** No invented employment, metrics, client outcomes
   or dates. When a fact cannot be substantiated, it is omitted rather than
   softened.
2. **Slugs are public URL identity.** They are lowercase, hyphenated and stable
   once published — see `Facet\Support\Slug`. Renaming one is a breaking change.
3. **Media is optional.** Set `media.source` to `null` when no final image
   exists; `description` is always required and the entry falls back to
   `Facet\Content\Media::FALLBACK_REFERENCE`. The site builds and renders with
   no images at all.
4. **Links must be absolute `http(s)` URLs that resolve.** A dead link is worse
   than no link: omit it instead.
5. **Repository metadata is not editorial provenance.** Commit dates, detected
   languages and repository URLs may substantiate `period`, `technologies` and
   `links`. They may never substantiate a lifecycle claim: an inactive
   repository, an old last-push date, a superseding project or a creation date
   are silence, not evidence that a project was *completed* or *archived*. When
   no canonical source states where a project stands, `status` is
   `"unspecified"` — the declared absence of a claim, never a softer way of
   saying "finished".
6. **The project list is an editorial shortlist, not an archive.** `projects.json`
   ships exactly the projects that are meant to be public right now — currently
   Kushim, Scora, Biogazen, Eszter and Math L'home. It is deliberately not an
   exhaustive career history: study work, the previous portfolio and Facet
   itself are absent by decision, not by oversight. The set is pinned in
   `tests/Content/CanonicalCorpusTest.php`, so adding or removing an entry is an
   explicit editorial decision that has to be made in both places.

Editing copy here never requires a routing or backend change.
