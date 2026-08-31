# PORT-138 corrective — visual evidence

Produced by `tools/shell-corrective-shots.mjs`, Chromium, against a production
build served by PHP's built-in server. Reduced motion is emulated for every
settled shot so a moving ribbon cannot photograph differently between runs; the
theme is written to `localStorage` before the first paint rather than switched
afterwards, so no still frame can catch a transition it was not meant to catch.

Regenerate with:

```bash
npm run build
php -d variables_order=EGPCS -S 127.0.0.1:8765 -t public public/index.php &
node tools/shell-corrective-shots.mjs docs/reports/PORT-138/corrective http://127.0.0.1:8765
```

## F1 — the cloud

| file | what to look at |
| --- | --- |
| `capsule-light-idle.png` | the control at its real size — where the silhouette has to work |
| `capsule-light-magnified.png` | the same drawing at a 48px root: one mass, unequal lobes, a flat base on the capsule's lower edge, one faint puff behind |
| `capsule-light-hover.png` | hover, unchanged from the approved behaviour |
| `capsule-dark-idle.png`, `capsule-dark-magnified.png`, `capsule-dark-hover.png` | the night side, for regression: sun, moon, craters and stars are untouched |

## F4 — the header and the route list

| file | what to look at |
| --- | --- |
| `header-desktop-light.png`, `header-desktop-dark.png` | the whole header at 1512, both themes |
| `nav-light-idle.png`, `nav-dark-idle.png` | at rest: the current route marked, the other three quiet |
| `nav-light-hover.png`, `nav-dark-hover.png` | Projects hovered — weaker than the current route on purpose |
| `nav-light-focus.png`, `nav-dark-focus.png` | keyboard focus: the same mark, plus the ring |
| `header-mobile-light.png`, `header-mobile-dark.png` | iPhone 13, collapsed |
| `header-mobile-light-open.png`, `header-mobile-dark-open.png` | the menu open, unchanged from the approved treatment |

## F2 — the page crossing

A screenshot cannot prove a transition, and a full-page capture takes longer
than the 320ms it would be trying to photograph, so the strip is **not** six
snapshots of a live animation. The press starts the real transitions, every
animation on the document is paused in the same task, and each frame is the
document with `currentTime` set to a stated fraction. What is shown is what the
engine would have painted at that moment; the filename says which moment.

```
transition-light-to-dark-paused-at-000ms.png   the theme has changed, nothing has moved yet
transition-light-to-dark-paused-at-080ms.png
transition-light-to-dark-paused-at-160ms.png   halfway: readable throughout, no flash of either end
transition-light-to-dark-paused-at-240ms.png
transition-light-to-dark-paused-at-320ms.png   settled
```

…and the same five for `dark-to-light`, plus
`transition-*-settled.png` for the finished header.

**Live measurement** (`measurements.txt`, regenerated with the shots, sampling
the resolved ground colour and the body's ink every frame in real time):

```
light → dark: ground moved ~310ms, ink ~120ms, transition mark present ~200ms
dark → light: ground moved ~260ms, ink ~115ms, transition mark present ~210ms
```

The numbers move a little run to run — they are wall-clock samples on a real
machine, not a specification. What the tests assert instead is the property
that has to hold: the mark appears, the mark is removed, exactly one theme
survives, and none of it happens under reduced motion or at boot.

## Not re-shot here

The approved PORT-138 evidence is unchanged and stays in the parent directory:
the toggle's day/night states, the responsive header strip, Skills, Journey and
the page end. Nothing in this pass touched them.
