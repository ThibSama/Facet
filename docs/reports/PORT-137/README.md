# PORT-137 — bilingual public site: evidence

Produced by `node tools/i18n-shots.mjs docs/reports/PORT-137 <base-url>` against
a production build served by the real entrypoint. Every shot is deterministic:
the theme is written to `localStorage` before the first paint rather than
switched afterwards, so nothing is caught mid-transition, and reduced motion is
emulated so the skills ribbons stand still.

## The document to read first

**[`TRANSLATIONS.md`](TRANSLATIONS.md)** — every substantive line of prose,
French beside English, with where it appears. That is the artefact the human
linguistic review turns on. The question it exists to answer is not whether the
English reads well but whether any line of it claims something the French line
did not.

## Screenshots

### Desktop, 1512×950

| File | What it shows |
| --- | --- |
| `desktop-dark-fr-home.png` | French home, dark, full page |
| `desktop-dark-en-home.png` | English home, dark, full page |
| `desktop-light-fr-home.png` | French home, light, full page |
| `desktop-light-en-home.png` | English home, light, full page |
| `desktop-dark-fr-projects.png` / `desktop-dark-en-projects.png` | The catalogue in both languages |
| `desktop-dark-fr-about.png` / `desktop-dark-en-about.png` | The About page in both languages |
| `desktop-dark-fr-contact.png` / `desktop-dark-en-contact.png` | The contact form in both languages |
| `desktop-dark-fr-project.png` / `desktop-dark-en-project.png` | One case study (Kushim) in both languages |

### The header and the switch

| File | What it shows |
| --- | --- |
| `header-dark-fr.png`, `header-dark-en.png` | The full header row, dark |
| `header-light-fr.png`, `header-light-en.png` | The full header row, light |
| `switch-dark-fr.png`, `switch-dark-en.png` | The switch alone, dark, in each state |
| `switch-light-fr.png`, `switch-light-en.png` | The switch alone, light, in each state |

### Mobile, 390×844

| File | What it shows |
| --- | --- |
| `mobile-header-fr.png`, `mobile-header-en.png` | Brand, switch, Menu and theme control on one row |
| `mobile-header-fr-open.png`, `mobile-header-en-open.png` | The same, with the collapsed navigation open |

## Measurements

**[`measurements.txt`](measurements.txt)** — the header's geometry at 320, 390,
412, 768, 834, 1024, 1280, 1512 and 1920, in both languages. This is what the
placement decision turned on, and it is measurement rather than judgement:

- the header row **fits at every width in both languages** — it never overflows;
- **no two header controls' boxes intersect** at any width in either language;
- both switch links are **41×44px**, the same 44px square the collapse control
  and the theme toggle are given;
- the **document never scrolls sideways** at any width in either language.

That last line is the one worth knowing the history of. It was `YES` on the home
route at first, and chasing it found a real regression rather than a measurement
artefact: a large part of the accepted PORT-136 home composition — the skills
band, the ribbons, the chips, the hero visual, and the finale's inked plate and
its clipping — was scoped in the stylesheet to `body[data-route='/']`. The home
page is now `/fr` and `/en`, so **none of those rules were applying**. The finale
had lost its plate and its `overflow: hidden`, which is what was pushing the
document 27px wide at 390.

The fix was to stop styling against the URL. The layout now also emits
`data-facet-route`, carrying the *route's name* from the canonical catalog
(`home`, `projects.show`, `admin.messages`), and the skin keys off that. A URL is
a spelling — and since PORT-137 every public page has two of them — while a route
name never moves. See `docs/decisions/PORT-137-bilingual-public-site.md` §9.

## Browser evidence

`playwright-three-engines.json` is the machine-readable report of the full suite
across Chromium, Firefox and WebKit, run with `retries: 0` and one worker. It
includes `tests/E2E/locale.spec.ts`, which is where the parts of PORT-137 that
only a browser can prove are asserted: that following the switch really
navigates, that a preference set by one visit is sent back on the next, that the
header holds four controls at 320, 390 and 412 without overlap, and that the
whole of it works with JavaScript disabled.

## What is asserted elsewhere

Screenshots cannot prove behaviour, and nothing here is offered as if they
could. The `<html lang>`, the canonical and `hreflang` block, the absence of
opposite-language chrome, the entry-route redirects, the preference cookie, the
contact flow in both languages and the no-JavaScript contract are all asserted
in `tests/Smoke/BilingualPublicSiteTest.php`, `tests/Unit/I18n/` and
`tests/E2E/locale.spec.ts`.
