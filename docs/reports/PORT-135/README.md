# PORT-135 — visual evidence

Satoshi Run after the direction correction, photographed against a production
build. These are for human review: PORT-135 is explicitly a visual change, and
a person has to look at it before it can be accepted. They are **not** a gate —
the automated coverage is `tests/E2E/satoshi-run.spec.ts`, and none of these
pictures stands in for it.

| File | Viewport | Theme | State |
|---|---|---|---|
| `desktop-dark-running.png` | 1440×900 | dark | mid-run, opening obstacle in frame |
| `desktop-dark-jump.png` | 1440×900 | dark | airborne, clearing that obstacle |
| `desktop-dark-duck.png` | 1440×900 | dark | ducked |
| `desktop-dark-over.png` | 1440×900 | dark | game over |
| `desktop-light-running.png` | 1440×900 | light | mid-run |
| `desktop-light-jump.png` | 1440×900 | light | airborne |
| `mobile-dark.png` | 412×915, coarse pointer | dark | mid-run, touch pads |
| `mobile-light.png` | 412×915, coarse pointer | light | mid-run, touch pads |

## Reproducing them

Two things make the set repeatable. The run seeds itself from `Date.now`, which
is pinned in the page, so every capture opens on the same lane. And the loop is
stopped before the shutter — the game already stops itself when the document is
hidden, so telling the page it is hidden freezes the lane on its last frame and
the picture is taken at the position it was synchronised to, rather than a
second of play later, which is what a 2880-pixel screenshot costs.

Positions are reached by waiting on the **score**, not on a clock: score is
distance × 1.6 and it is on screen, so waiting for a score is waiting for a
place on the lane. The time between the gesture and the first frame is not the
same twice; the distance to the first obstacle always is.

```sh
npm run build

# a server on the production build, against the disposable test schema
php -d variables_order=EGPCS -S 127.0.0.1:8765 -t public public/index.php

node tools/satoshi-run-shots.mjs docs/reports/PORT-135 http://127.0.0.1:8765
```

The game is opened the only way it can be opened: five rapid clicks on the
Facet mark in the top-left corner.
