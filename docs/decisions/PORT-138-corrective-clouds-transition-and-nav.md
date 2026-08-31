# PORT-138 corrective — the clouds, the crossing, the clock, and the route list

A bounded second pass over the shell PORT-138 built, after a human review that
approved the direction. Nothing approved was reopened: the toggle's concept,
proportions, sky, sun, moon, craters, stars, internal movement and timing, the
header's dimensions, the Skills fade, the homepage composition and Satoshi Run
are all exactly as they were reviewed. Four things changed, and this document
says what and why.

- [F1 — the cloud](#f1--the-cloud)
- [F2 — the page crossing](#f2--the-page-crossing)
- [F3 — the clock decides the default](#f3--the-clock-decides-the-default)
- [F4 — the route list](#f4--the-route-list)
- [The footer invariant, restated](#the-footer-invariant-restated)

---

## F1 — the cloud

**Symptom.** At 52 pixels the day side read as *circle, circle, circle*. The
construction was visible, which in a control this polished is the one thing it
could not afford.

**Change.** Still CSS, still one span, still layered radial gradients — no
asset, no SVG, no change to the moon, the stars or the scene's architecture.
What changed is the drawing:

| before | after |
| --- | --- |
| four discs, near-equal, in a row | five layers, no two the same size |
| symmetrical placement | an off-centre crown and a shoulder lobe overlapping the main one by more than half its width |
| all white | white lobes over a blue-white base and one faint, cooler puff behind, up and to the right |
| hard 47%/50% stops | two- and three-stop fades on every layer |
| discs floating in the sky | a wide, shallow ellipse joining the lobes along a flat bottom, clipped by the capsule's lower edge |

One technical note worth keeping. Every layer is sized `closest-side`. The
default sizing puts a radial gradient's far end at the corner of its own box,
so a stop that fades out at ninety per cent is still opaque where the box's
edges cut it — which is how the first attempt at the base produced a visible
rectangle with a soft middle. Ending each shape at the nearest side lets it
finish inside its own box.

Departure behaviour is untouched: the mass still falls out of the capsule as
the night arrives, on the same duration and the same easing.

---

## F2 — the page crossing

**Symptom, and the blocking one.** The capsule animated beautifully and the
document behind it went from near-white to near-black between two frames.

**What is now there.** A manual switch marks the document with
`data-facet-theme-shift` for the length of the change, and the mark is removed
on a timer. It is set only by the theme module, only for a switch a visitor
asked for, never at boot, and it never appears in the served HTML.

**Two mechanisms, because one of them was not enough.**

The shared layer gives everything under the mark a 320ms colour transition —
`background-color`, `color`, `border-color`, `box-shadow` and the rest — with
`!important`, because for those frames it has to outrank every component rule
in the shell and the skin. It steps around the theme control's own subtree:
replacing the capsule's transition list mid-switch would teleport the body
across the track instead of travelling it, which is the approved animation
undone by its own crossfade.

That alone still left the largest surface on the page jumping, and the reason
is worth writing down. **This skin's ground is not a background colour; it is a
pair of gradients**, and a gradient between two unregistered custom properties
changes in one frame however long you give it. So the skin now registers its
palette with `@property … syntax: '<color>'` and transitions the *tokens* on
the root. Every gradient, every `color-mix`, every token-tinted shadow crosses
because the token crosses. Where `@property` is unsupported the tokens change
in one frame and every element-level colour still transitions — strictly better
than before, and never broken.

One implementation detail is load-bearing: the mark is applied, a layout
property is read to force the style to resolve *with the mark in place*, and
only then does the theme change. A transition runs between two computed styles
and the earlier one has to already declare the transition; marking and
repainting in the same frame produces exactly the jump this code removes. One
forced reflow per press.

**The View Transition API was tried and is not used.** It gave the better
crossfade in Chromium and behaved badly in WebKit — `finished` never settled
and the document stayed uninteractive long enough to swallow presses, which is
a worse defect than the one being fixed. Two engines animating one way and one
the other is also two behaviours to reason about instead of one. The swap is
confined to `switchTheme` if WebKit's implementation settles down.

**Measured**, on Chromium, by sampling the resolved ground colour every frame:
260–310ms of movement per direction, with the mark present ~200–270ms. The
capsule's own 360ms animation still finishes last, which is what makes the
control lead the page rather than trail it.

**Reduced motion.** The module applies the theme directly and never sets the
mark, and both stylesheets' rules sit inside
`@media (prefers-reduced-motion: no-preference)`. Two locks, no motion, same
final state.

---

## F3 — the clock decides the default

**Product rule.** A visitor who has never chosen a theme gets the one their own
clock asks for.

```
hour >= 7 && hour < 20  →  light
otherwise               →  dark
```

Read with `new Date().getHours()` and nothing else. No geolocation, no address
lookup, no sunrise table, no season, no network request of any kind.

**Precedence.**

1. the explicit stored choice, if there is one;
2. otherwise the local hour;
3. otherwise light, if the hour is somehow not an hour.

A choice always wins. Dark chosen at 18:00 is still dark at 18:05, tomorrow
morning, and at noon a year later — until the visitor changes it. The storage
contract is unchanged: the same single `facet.theme` key, the same two values,
no cookie, nothing sent to the server.

**The system preference no longer decides.** It used to, when nothing was
stored; the product rule replaces it, and the module's `matchMedia` listener
for system changes went with it — it existed only to re-sync a state the system
no longer owns. `prefers-color-scheme` still rules one case and one only: a
visitor with **no JavaScript**, where the clock cannot be read at all and the
operating system's preference is the best answer available.

**One rule, two implementations, no flash.** The pre-paint inline bootstrap and
`resources/js/theme.ts` resolve the theme identically — same key, same
boundaries, same fallback — because they run milliseconds apart on the same
page and any disagreement between them would be visible. They cannot share
code (one is an inline script in the head), so a test holds them to each other.
Initial resolution is never animated: at 07:30 the page opens light, at 22:30
it opens dark, and neither is a transition.

---

## F4 — the route list

**Symptom.** Four tinted pills — a grey ground on hover, a lilac ground on the
current route. Not wrong, but the default any framework hands you, and the one
part of the header that had not been looked at.

**Change.** One grammar, three strengths: a tapered accent line under the word,
drawn on `::after`.

- **at rest** — no line at all. Muted ink, and the header stays as quiet as it
  was;
- **hover and focus** — the line scales out from the centre to 82%, at 0.62
  opacity;
- **current route** — full width, full opacity, and the word in accent.

Tapered rather than flat, because a rule with hard ends under one item in a row
of four is a tab. Inset from the link's box, so it belongs to the word rather
than to the padding. No ground, no shadow, no glass, nothing that grows the
header; the 44px target and the type are unchanged, and so are the
destinations, the `aria-current` the server states, the keyboard order and the
focus ring.

The collapsed mobile menu keeps exactly the treatment it was reviewed with: a
full-width line under a block link is a border, not a mark, so there the mark
stands down and the ground states carry it. Nothing else about the mobile menu
was touched.

**Reduced motion**: the line is drawn at full width in every state and only its
presence differs, so the three states stay distinguishable with nothing
travelling.

---

## The footer invariant, restated

Unchanged by this pass, and repeated here because it is the kind of thing that
gets lost:

> When real legal, privacy, or other footer-specific links are introduced, the
> footer must return as a compact responsive footer on **both desktop and
> mobile**, and must not be a repeat of the primary navigation.

The partial, the SSR structure and the ability to render it are intact; the
enhanced page hides it with one declaration and nothing here makes that harder
to undo.

---

## Out of scope, and untouched

PORT-137 internationalisation — no locale switch, no dictionaries, no `/fr` or
`/en`, no `hreflang`. Satoshi Run, the hero, Journey, Contact, the project
cards and the Skills ribbon were not touched. The theme control's approved
animation — its duration, easing, travel, hover and reduced-motion behaviour —
is exactly as it was reviewed.
