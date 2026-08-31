# PORT-136 — second corrective pass, visual evidence

The three findings the second human review left open against the approved
PORT-136 composition, photographed after correction against a production build.
For human review only: these are not a gate, and none of them stands in for the
automated coverage listed in `../README.md`.

Regenerate with a server holding a production build:

    node tools/home-corrective-2-shots.mjs docs/reports/PORT-136/corrective-2 http://127.0.0.1:8765

| File | Viewport | Theme | What it shows |
|---|---|---|---|
| `desktop-1920-light-skills-a.png` | 1920 wide | light | **F1** — the band as one continuous surface, ribbons live |
| `desktop-1920-light-skills-b.png` | 1920 wide | light | **F1** — the same, further along the travel |
| `desktop-1512-light-skills-a.png` | 1512 wide | light | **F1** — the width the finding was raised at |
| `desktop-1512-light-skills-b.png` | 1512 wide | light | **F1** — the same, further along the travel |
| `desktop-1280-light-skills-a.png` | 1280 wide | light | **F1** — the narrowest desktop width checked |
| `desktop-1280-light-skills-b.png` | 1280 wide | light | **F1** — the same, further along the travel |
| `desktop-light-skills-zoom.png` | 1512, 4× | light | **F1** — the artifact's own scale: no terminator under the pills |
| `desktop-dark-skills-zoom.png` | 1512, 4× | dark | **F1** — the same close-up, dark, unchanged |
| `desktop-dark-skills.png` | 1512 wide | dark | **F1** — dark band regression |
| `desktop-light-journey-to-finale.png` | 1512×950 | light | **F2** — the journey → 04 transition, and the desktop footer |
| `desktop-light-finale.png` | 1512 wide | light | **F2** — 04 as near-white paper, not a lavender plate |
| `desktop-dark-finale.png` | 1512 wide | dark | **F2** — byte-identical to the accepted frame |
| `mobile-light-finale.png` | 390×844 | light | **F2** — the same ending, narrow |
| `mobile-dark-finale.png` | 390×844 | dark | **F2** — the same, dark |
| `desktop-light-footer.png` | 1512 wide | light | **F3** — the approved desktop footer, untouched |
| `mobile-light-page-end.png` | 390×844 | light | **F3** — the page ends on 04: no footer, no strip, no brand |
| `mobile-dark-page-end.png` | 390×844 | dark | **F3** — the same, dark |

## Three things to know when reading these

**The skills frames are taken with motion on**, as in the first corrective
pass. F1 is a *moving* artifact, so the pills sit somewhere different in every
run; the surface behind them, which is what the frames are evidence of, does
not move. Two frames are taken at each width so a reviewer can see the same
surface at two points in the travel.

**The two 4× frames are the ones that matter for F1.** The residual seam was a
few levels of grey wide and terminated in a one-pixel-tall step; a 1× frame of
a whole section can show that a band reads as continuous, but not that a
hairline is gone. The close-ups are taken against the band's left edge, where
the pills enter.

**F3 is photographed as an absence.** The footer is `display: none` at this
width, so a frame of the element would be a frame of nothing. What the
`page-end` shots show is the bottom of the scrolled document: section 04, and
then the end of the page.
