# Canonical content

This directory is the single source of truth for Facet's public content.

- It is **versioned in Git**, not stored in a database.
- It is **presentation-neutral**: no skin, template, colour or layout hint lives
  here. Skins consume the typed structures in `Facet\Content`, never these files
  directly.
- Every file declares the `schemaVersion` it was written against. Copy and media
  may change freely within a major version; changing the *shape* of content
  requires bumping `Facet\Content\ContentSchema::VERSION`.

| File                      | Contains                                              |
| ------------------------- | ----------------------------------------------------- |
| `profile.json`            | The single identity record                            |
| `projects.json`           | Portfolio projects, resolved by `/{locale}/projects/{slug}` |
| `skills.json`             | Technologies, tools and certifications                |
| `experiences.json`        | Education, professional and volunteer entries          |
| `translations/en.json`    | The English text of the four files above — prose only  |

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

## Languages

The four files above are written in **French**, and French is the corpus: there
is no `translations/fr.json`, because there is nothing for it to overlay.

`translations/en.json` is an overlay, not a second corpus. It carries prose and
only prose — summaries, contexts, roles, concepts, outcomes, highlights, media
descriptions and link labels — addressed by the canonical slug it translates.
Every *fact* stays in the file above it and is read identically in both
languages: slugs, names, technologies, dates, statuses, categories, kinds, link
URLs, media sources, `featured`, and the order of everything.

Three rules hold it together, and all three are enforced at load rather than
described here:

1. **The overlay is total.** Every entry and every localizable field must be
   present, or the corpus fails to load with the missing path named.
2. **Lists must match in length.** A translated `concepts`, `outcomes`,
   `highlights` or link-label list must have exactly as many items as the
   canonical one — which is what stops a translation from adding a claim the
   corpus does not make.
3. **A translation may not enrich.** It restates the same fact in another
   language. Adding an achievement, a metric, a client, a technology or a firmer
   commitment is the same editorial change as adding it to the French, and has
   to be made there first.

Proper nouns are not translated: project names, technology names, institutions,
places, and the official names of French qualifications and certifications. See
`docs/decisions/PORT-137-bilingual-public-site.md` §6.

Adding a project therefore means adding it in two places, deliberately — the
same shape as rule 6 above.

Editing copy here never requires a routing or backend change.
