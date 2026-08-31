# PORT-136 — corrective pass, visual evidence

The four findings human review returned against the approved PORT-136
composition, photographed after correction against a production build. For
human review only: these are not a gate, and none of them stands in for the
automated coverage listed in `../README.md`.

Regenerate with a server holding a production build:

    node tools/home-corrective-shots.mjs docs/reports/PORT-136/corrective http://127.0.0.1:8765

| File | Viewport | Theme | What it shows |
|---|---|---|---|
| `desktop-dark-skills.png` | 1512 wide | dark | **F1** — the band behind the pills is now one unbroken surface |
| `desktop-light-skills.png` | 1512 wide | light | **F1** — the same, where the rail was most obvious |
| `desktop-light-journey-to-finale.png` | 1512×950 | light | **F2** — the journey → 04 seam, and the desktop footer still carrying its nav |
| `desktop-light-finale.png` | 1512 wide | light | **F2** — 04 as violet paper: light-mode, not a dark section |
| `desktop-dark-finale.png` | 1512 wide | dark | **F2** — unchanged; byte-identical to the accepted frame |
| `mobile-light-header.png` | 390×844 | light | **F4** — the icon-only Menu control |
| `mobile-dark-header.png` | 390×844 | dark | **F4** — the same, dark |
| `mobile-light-footer.png` | 390×844 | light | **F3** — the brand alone, no duplicate navigation |
| `mobile-dark-footer.png` | 390×844 | dark | **F3** — the same, dark |
| `mobile-light-nav-open.png` | 390×844 | light | **F4** — the menu still opens, from the icon-only control |
| `mobile-dark-nav-open.png` | 390×844 | dark | **F4** — the same, dark |

## Two things to know when reading these

**The skills frames are taken with motion on.** `tools/home-shots.mjs` emulates
reduced motion for every frame, which is what makes its output byte-stable —
and is also why it could never have shown F1: under reduced motion the centre
light is switched off entirely, so the rail was invisible to it. The frames
here run against a live ribbon, so the pills sit in a different place each run.
The surface behind them, which is what they are evidence of, does not move.

**The mobile size is the reviewer's.** 390×844 at 3×, the iPhone 14 viewport
the findings were raised at, rather than the iPhone 13 profile the main suite
uses.
