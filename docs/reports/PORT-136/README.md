# PORT-136 — visual evidence

The recomposed home page, photographed against a production build. These are
for human review: PORT-136 is explicitly a visual change, and a person has to
look at it before it can be accepted. They are **not** a gate — the automated
coverage is `tests/Smoke/HomeCompositionTest.php`,
`tests/Smoke/InteractiveProjectCardsTest.php`,
`tests/Smoke/SkillRibbonTest.php`, `tests/Smoke/SignatureHeroTest.php`,
`tests/Smoke/SectionTransitionTest.php` and `tests/E2E/`, and none of these
pictures stands in for any of them.

| File | Viewport | Theme | What it shows |
|---|---|---|---|
| `desktop-dark-full.png` | 1512×950, full page | dark | the whole rhythm in one frame |
| `desktop-dark-hero.png` | 1512×950 | dark | the first screen |
| `desktop-dark-hero-live.png` | 1512×950 | dark | the hero with the facet field running |
| `desktop-dark-work.png` | 1512 wide | dark | the three work panels |
| `desktop-dark-skills.png` | 1512 wide | dark | the skills band |
| `desktop-dark-journey.png` | 1512 wide | dark | the milestone grid |
| `desktop-dark-finale.png` | 1512 wide | dark | the inked finale |
| `desktop-light-full.png` | 1512×950, full page | light | the whole rhythm, light |
| `desktop-light-hero.png` | 1512×950 | light | the first screen, light |
| `desktop-light-hero-live.png` | 1512×950 | light | the facet field, light |
| `desktop-light-skills.png` | 1512 wide | light | the band **recessed** rather than raised |
| `desktop-light-finale.png` | 1512 wide | light | the plate both themes share |
| `laptop-dark-hero.png` | 1280×850 | dark | the hero one size down |
| `tablet-dark-full.png` | 834×1112, full page | dark | one column of milestones, banner lead panel |
| `mobile-dark-full.png` | iPhone 13, full page | dark | the whole page on a phone |
| `mobile-light-hero.png` | iPhone 13 | light | the phone's first screen |

## How to read them

Two things about the section shots. They are taken with **reduced motion
emulated**, so two runs of the script produce the same bytes — which means the
skill ribbons are photographed as the plain wrapping list the server sent, not
mid-travel, and the sections are not caught part-way through their entry. And a
section taller than the viewport is framed by its own landmark, so it is
cropped to what fits rather than stitched.

The two `-hero-live` shots are the exception and are taken with motion on,
because the facet field is the thing they exist to show — and because they are
the evidence that the renderer's new fragment budget is invisible.

## Reproducing them

```sh
npm run build

# a server on the production build
APP_ENV=production APP_NAME=Facet APP_KEY=shots-key APP_LOCALE=en \
  php -d variables_order=EGPCS -S 127.0.0.1:8765 -t public public/index.php

node tools/home-shots.mjs docs/reports/PORT-136 http://127.0.0.1:8765
```

The theme is stamped into `localStorage` before the first paint rather than
switched afterwards, so no shot catches a transition.

## Measured, not photographed

Numbers a picture cannot carry, taken from the built page in a real browser at
seven viewports from 320px up.

**No horizontal overflow anywhere.** `scrollWidth - clientWidth` is `0` at
1920, 1512, 1280, 1024, 834, 390 and 320 CSS pixels wide. The elements that
*do* extend past the right edge are the ribbon tracks and their chips, inside
the live ribbon's own `overflow: hidden` — that is the strip running off the
screen, which is the section's whole point.

**The journey stopped dominating the page.** Section heights at 1512×950:
hero 752, work 1483, skills 553, journey 899, finale 408. On a phone the
journey is 1523 of 5107.

**Every ribbon is covered, including the sparse ones.** Clones made by the
runtime at 1280 wide: language 2, framework 2, database 4, tooling 1,
certification 3. The two-name `certification` category fills its track exactly
as the eleven-name `tooling` one does.

**Reduced motion leaves the document.** 0 live ribbons, 0 clones, hero
`static`, 0 staged sections, all 28 canonical skills visible.

**Keyboard order**, tabbing from the top:

> Skip to content › Facet › Dark theme › Home › Projects › About › Contact ›
> View all projects › Get in touch › See every project › Kushim › Scora ›
> Biogazen › Contact me › (footer)

Every stop has a visible ring and a real box, and each project panel is exactly
one stop — the stretched overlay never becomes a second target.

**The deferred chunks stay deferred.** Loading `/` requests the hero chunk and
never the Satoshi Run chunk. Five rapid clicks on the Facet mark launch the run
(`data-facet-run-state="running"`), the chunk is requested only then, and
Escape closes it.

**The hero's fragment budget holds.** Backing store, at every size that renders
one: 1512×950 → 352×440; 1280×720 → 352×440; 834×1112 → 595×260; 390×844 → no
canvas, the slot is not rendered below 40rem. 154,880 fragments, whatever the
composition asks the slot to look like.
