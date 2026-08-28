#!/usr/bin/env python3
"""Dependency-free Firefox/WebDriver visual and layout audit."""

from __future__ import annotations

import argparse
import base64
import json
import re
import time
from pathlib import Path
from urllib import request


def webdriver(method: str, path: str, payload: object | None = None) -> object:
    data = None if payload is None else json.dumps(payload).encode()
    req = request.Request(
        f"http://127.0.0.1:4444{path}",
        data=data,
        headers={"Content-Type": "application/json"},
        method=method,
    )
    with request.urlopen(req, timeout=60) as response:
        return json.load(response)["value"]


def execute(session: str, script: str, args: list[object] | None = None) -> object:
    return webdriver(
        "POST",
        f"/session/{session}/execute/sync",
        {"script": script, "args": args or []},
    )


def element_id(session: str, selector: str) -> str:
    value = webdriver(
        "POST",
        f"/session/{session}/element",
        {"using": "css selector", "value": selector},
    )
    return value["element-6066-11e4-a52e-4f735466cecf"]


HERO_LIFECYCLE = """
/*
 * Runtime proof of the hero's lifecycle ownership, run against the real
 * served document and the real built modules.
 *
 * The page is re-parsed inside a same-origin iframe that is instrumented
 * *before* a single one of its scripts exists: an about:blank frame inherits
 * this origin, so its window can be patched and only then written to. That is
 * what makes the counts below exact rather than approximate — every listener
 * the skin registers, and every WebGL program it deletes, passes through a
 * counter installed before the module ran.
 */
const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
const [html, phase] = [arguments[0], arguments[1]];

return (async () => {

const frame = document.createElement('iframe');
frame.style.cssText = 'display:block;border:0;width:1200px;height:800px';
document.body.replaceChildren(frame);

const view = frame.contentWindow;
const doc = frame.contentDocument;

/* Listeners are tracked by identity: a listener registered twice is two. */
const registered = {window: [], motion: []};
const track = (book, type, fn, added) => {
    if (added) {
        book.push([type, fn]);
        return;
    }
    const at = book.findIndex((entry) => entry[0] === type && entry[1] === fn);
    if (at >= 0) book.splice(at, 1);
};
const count = (book, type) => book.filter((entry) => entry[0] === type).length;

const windowAdd = view.addEventListener.bind(view);
const windowRemove = view.removeEventListener.bind(view);
view.addEventListener = function (type, fn, opts) {
    track(registered.window, type, fn, true);
    /*
     * A `once` listener removes itself without going through
     * removeEventListener, so the book is corrected from the dispatch
     * instead. Otherwise a correct teardown would read as a leak.
     */
    if (opts !== null && typeof opts === 'object' && opts.once) {
        windowAdd(type, () => track(registered.window, type, fn, false), {once: true});
    }
    return windowAdd(type, fn, opts);
};
view.removeEventListener = function (type, fn, opts) {
    track(registered.window, type, fn, false);
    return windowRemove(type, fn, opts);
};

const queryAdd = view.MediaQueryList.prototype.addEventListener;
const queryRemove = view.MediaQueryList.prototype.removeEventListener;
view.MediaQueryList.prototype.addEventListener = function (type, fn, opts) {
    if (this.media === REDUCED_MOTION) track(registered.motion, type, fn, true);
    return queryAdd.call(this, type, fn, opts);
};
view.MediaQueryList.prototype.removeEventListener = function (type, fn, opts) {
    if (this.media === REDUCED_MOTION) track(registered.motion, type, fn, false);
    return queryRemove.call(this, type, fn, opts);
};

/* The query list the skin retains, so a change can be delivered to it. */
let motionQuery = null;
const matchMedia = view.matchMedia.bind(view);
view.matchMedia = (query) => {
    const result = matchMedia(query);
    if (query === REDUCED_MOTION) motionQuery = result;
    return result;
};

/* One deleteProgram call is one destroy: the counter for idempotence. */
let destroys = 0;
const deleteProgram = view.WebGL2RenderingContext.prototype.deleteProgram;
view.WebGL2RenderingContext.prototype.deleteProgram = function (program) {
    destroys += 1;
    return deleteProgram.call(this, program);
};

const slot = () => doc.querySelector('[data-facet-hero-visual]');
const state = () => {
    const node = slot();
    return {
        hero: node ? node.dataset.facetHero || null : null,
        canvases: doc.querySelectorAll('[data-facet-hero-visual] canvas').length,
        pagehideListeners: count(registered.window, 'pagehide'),
        motionListeners: count(registered.motion, 'change'),
        destroys,
        fallback: node !== null && node.getAttribute('aria-hidden') === 'true',
    };
};

const settle = () => new Promise((resolve) => view.setTimeout(resolve, 250));
const pagehide = () => view.dispatchEvent(new view.Event('pagehide'));
const motion = (matches) => motionQuery !== null && motionQuery.dispatchEvent(
    new view.MediaQueryListEvent('change', {media: REDUCED_MOTION, matches})
);

const baseline = state();

doc.open();
doc.write(html);
doc.close();

/* The effect is scheduled on idle, so mounting is awaited, never assumed. */
const deadline = Date.now() + 8000;
while (state().hero !== 'live' && Date.now() < deadline) {
    await settle();
}

const mounted = state();
const steps = [];

if (phase === 'pagehide') {
    pagehide();
    await settle();
    steps.push(['after pagehide', state()]);

    /* Repeat signals, of both kinds, on an already-released hero. */
    pagehide();
    motion(true);
    await settle();
    steps.push(['after repeated teardown signals', state()]);
} else {
    /* A query that did not match is not a request to stop. */
    motion(false);
    await settle();
    steps.push(['after non-matching motion change', state()]);

    motion(true);
    await settle();
    steps.push(['after reduced-motion change', state()]);

    /* If pagehide were still registered, this would destroy a second time. */
    pagehide();
    motion(true);
    await settle();
    steps.push(['after repeated teardown signals', state()]);
}

frame.remove();

return {phase, baseline, mounted, steps, motionQueryCaptured: motionQuery !== null};
})();
"""


def hero_lifecycle(session: str, base_url: str) -> tuple[list[dict[str, object]], list[str]]:
    """Mounts and tears the hero down twice, counting listeners and destroys."""
    webdriver("POST", f"/session/{session}/window/rect", {"width": 1440, "height": 1000})
    webdriver("POST", f"/session/{session}/url", {"url": base_url + "/"})
    html = execute(session, "return fetch(arguments[0]).then((response) => response.text());", [base_url + "/"])

    report: list[dict[str, object]] = []
    failures: list[str] = []

    for phase in ("pagehide", "reduced-motion"):
        result = execute(session, HERO_LIFECYCLE, [html, phase])
        report.append(result)
        label = f"hero lifecycle ({phase})"
        baseline, mounted = result["baseline"], result["mounted"]

        if baseline["pagehideListeners"] or baseline["motionListeners"] or baseline["destroys"]:
            failures.append(f"{label}: instrumentation did not start from an empty baseline")
        if mounted["hero"] != "live":
            failures.append(f"{label}: the hero never mounted, so teardown proves nothing")
        if mounted["canvases"] != 1 or mounted["destroys"] != 0:
            failures.append(f"{label}: a mounted hero must own exactly one live canvas")
        if mounted["pagehideListeners"] != 1 or mounted["motionListeners"] != 1:
            failures.append(
                f"{label}: expected one pagehide and one reduced-motion listener, got "
                f"{mounted['pagehideListeners']} and {mounted['motionListeners']}"
            )
        if not result["motionQueryCaptured"]:
            failures.append(f"{label}: the skin never queried reduced motion after mounting")

        for name, step in result["steps"]:
            held = name == "after non-matching motion change"
            expected_destroys = 0 if held else 1
            if step["destroys"] != expected_destroys:
                failures.append(
                    f"{label} {name}: expected {expected_destroys} destroy(s), got {step['destroys']}"
                )
            if held:
                if step["hero"] != "live" or step["canvases"] != 1:
                    failures.append(f"{label} {name}: a non-matching query must not tear the hero down")
                continue
            if step["pagehideListeners"] or step["motionListeners"]:
                failures.append(
                    f"{label} {name}: listeners outlived the hero "
                    f"({step['pagehideListeners']} pagehide, {step['motionListeners']} reduced-motion)"
                )
            if step["canvases"] != 0 or step["hero"] != "static" or not step["fallback"]:
                failures.append(f"{label} {name}: the static fallback was not restored intact")

    return report, failures


CARD_PROBE = """
/*
 * What a card promises, measured on the served page rather than asserted from
 * its source: that a point anywhere on the card hit-tests to the one canonical
 * link, and that lifting a card moves nothing but the card.
 *
 * The geometry of a neighbour is read before and during the lift. A transform
 * is a compositor operation and must leave every other box exactly where it
 * was; if the lift were implemented with margins or top offsets, these two
 * readings would differ and the trace below would say so.
 */
const grid = document.querySelector('[data-facet-card-grid]');
const cards = [...document.querySelectorAll('.facet-card')];
const first = cards[0];
const last = cards[cards.length - 1];
const footer = document.querySelector('.facet-footer');

const box = (node) => {
    const rect = node.getBoundingClientRect();
    return [Math.round(rect.left), Math.round(rect.top), Math.round(rect.width), Math.round(rect.height)];
};

/* Points a finger or a cursor would plausibly land on, all off the text. */
/*
 * The card's corners are rounded and its overflow is clipped, so a point six
 * pixels into the literal corner of the bounding box is *outside* the card a
 * reader can see. The probes are inset by the corner radius instead, which is
 * the difference between asking "does the whole card answer" and asking "does
 * a pixel nobody would call part of the card answer".
 */
const rect = first.getBoundingClientRect();
const radius = Math.ceil(Number.parseFloat(getComputedStyle(first).borderTopLeftRadius) || 0) + 2;
const probes = {
    'top-left': [rect.left + radius, rect.top + radius],
    'top-right': [rect.right - radius, rect.top + radius],
    'bottom-left': [rect.left + radius, rect.bottom - radius],
    'bottom-right': [rect.right - radius, rect.bottom - radius],
    'left-edge': [rect.left + 3, rect.top + rect.height / 2],
    'right-edge': [rect.right - 3, rect.top + rect.height / 2],
    'top-edge': [rect.left + rect.width / 2, rect.top + 3],
    'bottom-edge': [rect.left + rect.width / 2, rect.bottom - 3],
    centre: [rect.left + rect.width / 2, rect.top + rect.height / 2],
};

const link = first.querySelector('a.facet-card__link');
const hits = {};
for (const [name, [x, y]] of Object.entries(probes)) {
    const found = document.elementFromPoint(x, y);
    hits[name] = found === null ? null : (found === link || link.contains(found) ? 'link' : found.tagName.toLowerCase() + '.' + (found.className || ''));
}

return {
    cards: cards.length,
    grid: grid !== null,
    href: link.getAttribute('href'),
    hits,
    neighbourBefore: box(last),
    footerBefore: footer === null ? null : box(footer),
    tabIndexes: cards.map((card) => card.querySelectorAll('a, button, input, select, textarea, [tabindex]').length),
    probePoints: probes,
};
"""

CARD_SETTLE = """
/*
 * Read while the pointer is still resting on the first card, and only after
 * the transitions have had time to finish. A card that is measured mid-fade
 * reports the state it is leaving, not the state it reached.
 */
return (async () => {
await new Promise((resolve) => setTimeout(resolve, 600));

const cards = [...document.querySelectorAll('.facet-card')];
const first = cards[0];
const last = cards[cards.length - 1];
const footer = document.querySelector('.facet-footer');
const box = (node) => {
    const rect = node.getBoundingClientRect();
    return [Math.round(rect.left), Math.round(rect.top), Math.round(rect.width), Math.round(rect.height)];
};

return {
    transform: getComputedStyle(first).transform,
    lightOpacity: getComputedStyle(first, '::before').opacity,
    origin: [first.style.getPropertyValue('--facet-card-dx'), first.style.getPropertyValue('--facet-card-dy')],
    promoted: first.dataset.facetCard || '',
    neighbourDuring: box(last),
    footerDuring: footer === null ? null : box(footer),
};
})();
"""

CARD_TRACE = """
/*
 * The grid's cost under a pointer that never stops moving.
 *
 * WebDriver's own action pacing cannot answer this: it delivers a handful of
 * events per second and the frame rate it produces measures the harness. So
 * the storm is dispatched in the page, at the rate a fast pointer really
 * produces — eight moves per animation frame, for two seconds — against the
 * real listeners the skin registered.
 *
 * Two numbers matter. `handlerMs` is the main-thread time the skin's own
 * `pointermove` handler costs per event, which is what a forced reflow would
 * make impossible to hide: reading a card's geometry per move would put a
 * layout flush inside every one of these. `frameP95` is what the reader
 * actually perceives while the light follows their cursor.
 */
return (async () => {
const grid = document.querySelector('[data-facet-card-grid]');
const cards = [...document.querySelectorAll('.facet-card')];
const targets = cards.map((card) => {
    const rect = card.getBoundingClientRect();
    return {node: card.querySelector('.facet-media, p, h2, h3') || card, rect};
});

const deltas = [];
let handlerTotal = 0;
let dispatched = 0;
let previous = 0;
let ticks = 0;
let crossings = 0;
let crossingTotal = 0;
let resting = null;

await new Promise((resolve) => {
    const tick = (now) => {
        if (previous !== 0) deltas.push(now - previous);
        previous = now;
        ticks += 1;

        /*
         * A pointer travels *across* a card before it reaches the next one.
         * Cycling cards on every event would model a cursor that teleports,
         * and would charge every single move the one-off cost of arriving on
         * a new card — so the storm dwells for three frames, twenty-four
         * moves, before moving on. `crossings` counts the arrivals, and
         * `handlerMsWorst` prices them, so the expensive case is reported
         * rather than averaged away.
         */
        const target = targets[Math.floor(ticks / 3) % targets.length];
        const arriving = target !== resting;

        for (let i = 0; i < 8; i += 1) {
            const step = ((dispatched + i) % 24) / 24;
            const event = new PointerEvent('pointermove', {
                bubbles: true,
                pointerType: 'mouse',
                clientX: target.rect.left + 4 + step * (target.rect.width - 8),
                clientY: target.rect.top + 4 + step * (target.rect.height - 8),
            });
            const before = performance.now();
            target.node.dispatchEvent(event);
            const cost = performance.now() - before;
            handlerTotal += cost;

            if (arriving && i === 0) {
                crossingTotal += cost;
                crossings += 1;
            }
        }

        resting = target;
        dispatched += 8;

        if (ticks >= 120) {
            resolve();
            return;
        }

        requestAnimationFrame(tick);
    };

    requestAnimationFrame(tick);
});

const samples = deltas.slice(5).sort((a, b) => a - b);
const at = (q) => samples.length === 0 ? null : Math.round(samples[Math.min(samples.length - 1, Math.floor(samples.length * q))] * 10) / 10;

return {
    grid: grid !== null,
    frames: samples.length,
    dispatched,
    fps: samples.length === 0 ? null : Math.round((1000 / (samples.reduce((a, b) => a + b, 0) / samples.length)) * 10) / 10,
    frameP95: at(0.95),
    frameMax: samples.length === 0 ? null : Math.round(samples[samples.length - 1] * 10) / 10,
    handlerMs: Math.round((handlerTotal / dispatched) * 10000) / 10000,
    crossings,
    handlerMsWorst: crossings === 0 ? null : Math.round((crossingTotal / crossings) * 10000) / 10000,
};
})();
"""

CARD_KEYBOARD = """
/* Focus the card's link the way a keyboard would reach it. */
const card = document.querySelectorAll('.facet-card')[arguments[0]];
const link = card.querySelector('a.facet-card__link');
link.focus();
return {
    focused: document.activeElement === link,
    reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
};
"""

CARD_KEYBOARD_SETTLED = """
/* The same card once its transitions have run: what the reader ends up seeing. */
const card = document.querySelectorAll('.facet-card')[arguments[0]];
const link = card.querySelector('a.facet-card__link');
return {
    transform: getComputedStyle(card).transform,
    lightOpacity: getComputedStyle(card, '::before').opacity,
    outline: getComputedStyle(link).outlineStyle,
    stillFocused: document.activeElement === link,
};
"""


def card_interaction(
    session: str,
    base_url: str,
    reduced_motion: bool,
    pointer: str,
) -> tuple[dict[str, object], list[str]]:
    """Hit-tests the card, sweeps a pointer across the grid and reads the lift.

    `pointer` is the device class the run stands in for. A fine pointer is
    expected to light and lift a card it rests on; a coarse one is expected to
    do neither, and to reach exactly the same URL anyway.
    """
    label = f"cards ({pointer} pointer{', reduced-motion' if reduced_motion else ''})"
    hovers = pointer == "fine" and not reduced_motion
    failures: list[str] = []

    webdriver("POST", f"/session/{session}/window/rect", {"width": 1440, "height": 1000})
    webdriver("POST", f"/session/{session}/url", {"url": base_url + "/projects"})
    execute(session, "return new Promise((resolve) => setTimeout(resolve, 500));")

    probe = execute(session, CARD_PROBE)

    if probe["cards"] < 2:
        failures.append(f"{label}: the catalogue must render more than one card for this gate to mean anything")
    if not probe["grid"]:
        failures.append(f"{label}: the grid carries no runtime hook")

    for name, hit in probe["hits"].items():
        if hit != "link":
            failures.append(f"{label}: a pointer at the {name} of a card reached {hit}, not the canonical link")

    for count in probe["tabIndexes"]:
        if count != 1:
            failures.append(f"{label}: a card exposes {count} focusable elements; one link means one tab stop")

    # A real pointer sweep across the whole grid, in the browser's own event queue.
    origin = probe["probePoints"]["centre"]
    moves: list[dict[str, object]] = [
        {"type": "pointerMove", "duration": 0, "x": int(origin[0]), "y": int(origin[1])}
    ]
    for step in range(1, 41):
        moves.append({
            "type": "pointerMove",
            "duration": 16,
            "x": int(origin[0] + step * 9),
            "y": int(origin[1] + (step % 12) * 4),
        })

    # The sweep ends where it started, so the state that is read afterwards is
    # the state of a card the pointer is actually resting on.
    moves.append({"type": "pointerMove", "duration": 120, "x": int(origin[0]), "y": int(origin[1])})

    webdriver("POST", f"/session/{session}/actions", {"actions": [{
        "type": "pointer",
        "id": "mouse",
        "parameters": {"pointerType": "mouse"},
        "actions": moves,
    }]})

    settled = execute(session, CARD_SETTLE)
    trace = execute(session, CARD_TRACE)

    if settled["neighbourDuring"] != probe["neighbourBefore"]:
        failures.append(
            f"{label}: hovering a card moved a neighbour "
            f"{probe['neighbourBefore']} -> {settled['neighbourDuring']}"
        )
    if settled["footerDuring"] != probe["footerBefore"]:
        failures.append(f"{label}: hovering a card moved the page below the grid")
    if trace["frames"] < 60:
        failures.append(f"{label}: only {trace['frames']} frames sampled; the trace proves nothing")
    else:
        if trace["frameP95"] is not None and trace["frameP95"] > 34:
            failures.append(
                f"{label}: frame p95 {trace['frameP95']} ms under a moving pointer (budget 34 ms)"
            )
        if trace["handlerMs"] > 0.02:
            failures.append(
                f"{label}: {trace['handlerMs']} ms of main-thread work per pointer move; "
                "a per-move measurement is a forced reflow"
            )
        if trace["handlerMsWorst"] is not None and trace["handlerMsWorst"] > 0.5:
            failures.append(
                f"{label}: arriving on a card costs {trace['handlerMsWorst']} ms"
            )

    still = settled["transform"] in ("none", "matrix(1, 0, 0, 1, 0, 0)")

    if reduced_motion:
        if not still:
            failures.append(f"{label}: a card still travels under reduced motion ({settled['transform']})")
        if settled["origin"] != ["", ""]:
            failures.append(f"{label}: the pointer tracker mounted despite reduced motion")
    elif pointer == "coarse":
        # A finger leaves a sticky `:hover` behind on whatever it touched last.
        # Neither the lift nor the light may depend on it.
        if not still:
            failures.append(f"{label}: a coarse pointer lifted a card it is not resting on")
        if float(settled["lightOpacity"]) > 0.1:
            failures.append(f"{label}: a sticky hover left a card lit on a touch screen")
        if settled["origin"] != ["", ""]:
            failures.append(f"{label}: the pointer tracker mounted for a pointer with no path")
    else:
        if still:
            failures.append(f"{label}: a hovered card did not lift")
        if float(settled["lightOpacity"]) < 0.9:
            failures.append(f"{label}: a hovered card did not light")
        if settled["origin"] == ["", ""]:
            failures.append(f"{label}: the pointer tracker never moved the light")

    if reduced_motion and float(settled["lightOpacity"]) < 0.9 and pointer == "fine":
        failures.append(f"{label}: reduced motion withdrew the affordance as well as the movement")

    keyboard = execute(session, CARD_KEYBOARD, [1])
    execute(session, "return new Promise((resolve) => setTimeout(resolve, 600));")
    keyboard = {**keyboard, **execute(session, CARD_KEYBOARD_SETTLED, [1])}

    if not keyboard["focused"]:
        failures.append(f"{label}: the card's link is not focusable")
    if float(keyboard["lightOpacity"]) < 0.9:
        failures.append(f"{label}: keyboard focus does not light the card the way hover does")
    if not reduced_motion and keyboard["transform"] in ("none", "matrix(1, 0, 0, 1, 0, 0)"):
        failures.append(f"{label}: keyboard focus does not lift the card the way hover does")
    if hovers and settled["transform"] != keyboard["transform"]:
        failures.append(
            f"{label}: focus and hover are not the same treatment "
            f"({keyboard['transform']} against {settled['transform']})"
        )
    if reduced_motion and keyboard["transform"] not in ("none", "matrix(1, 0, 0, 1, 0, 0)"):
        failures.append(f"{label}: reduced motion must neutralise the focus lift too")
    if keyboard["outline"] == "none":
        failures.append(f"{label}: the focused link lost its focus ring")
    if keyboard["reducedMotion"] != reduced_motion:
        failures.append(f"{label}: the profile did not apply; reduced motion is {keyboard['reducedMotion']}")

    # A tap at the card's far corner, with a touch pointer, must navigate.
    corner = probe["probePoints"]["bottom-right"]
    webdriver("POST", f"/session/{session}/actions", {"actions": [{
        "type": "pointer",
        "id": "finger",
        "parameters": {"pointerType": "touch"},
        "actions": [
            {"type": "pointerMove", "duration": 0, "x": int(corner[0]), "y": int(corner[1])},
            {"type": "pointerDown", "button": 0},
            {"type": "pause", "duration": 40},
            {"type": "pointerUp", "button": 0},
        ],
    }]})
    # The tap navigates, so the page is polled rather than scripted: a script
    # injected into a document that is being replaced is a race, not a check.
    landed = ""
    for _ in range(40):
        landed = webdriver("GET", f"/session/{session}/url")
        if landed.endswith(probe["href"]):
            break
        time.sleep(0.1)

    if not landed.endswith(probe["href"]):
        failures.append(f"{label}: a tap at the card's corner landed on {landed}, not {probe['href']}")

    return {
        "profile": ("reduced-motion" if reduced_motion else "normal") + f" / {pointer} pointer",
        "pointer": pointer,
        "probe": probe,
        "settled": settled,
        "trace": trace,
        "keyboard": keyboard,
        "tapLandedOn": landed,
    }, failures


RIBBON_STATE = """
/*
 * One reading of every ribbon on the page, taken from the rendered document
 * rather than from the module that built it.
 *
 * `covered` is the seam test. A ribbon is seamless exactly when the strip
 * always overhangs the viewport at both ends: the moment the rightmost copy's
 * right edge crosses inside the ribbon's own right edge, a reader sees the
 * list end and restart. Sampling that over minutes is the only honest way to
 * say a loop has no jump — an animation that resets is one that stops
 * overhanging for a frame.
 */
return [...document.querySelectorAll('[data-facet-ribbon]')].map((ribbon) => {
    const track = ribbon.querySelector('[data-facet-ribbon-track]');
    const sets = [...track.children];
    const frame = ribbon.getBoundingClientRect();
    const rects = sets.map((set) => set.getBoundingClientRect());
    const style = getComputedStyle(track);
    const matrix = new DOMMatrixReadOnly(style.transform === 'none' ? '' : style.transform);

    return {
        direction: ribbon.dataset.facetRibbonDirection || null,
        live: ribbon.dataset.facetRibbon || null,
        held: 'facetRibbonHold' in ribbon.dataset,
        x: Math.round(matrix.m41 * 100) / 100,
        shift: Math.round(Number.parseFloat(ribbon.style.getPropertyValue('--facet-ribbon-shift')) * 100) / 100 || null,
        duration: style.animationDuration,
        iterations: style.animationIterationCount,
        playState: style.animationPlayState,
        sets: sets.length,
        clones: track.querySelectorAll('[data-facet-ribbon-clone]').length,
        focusableInClones: track.querySelectorAll('[data-facet-ribbon-clone] a, [data-facet-ribbon-clone] button, [data-facet-ribbon-clone] input, [data-facet-ribbon-clone] [tabindex]').length,
        unhiddenClones: [...track.querySelectorAll('[data-facet-ribbon-clone]')].filter((clone) => clone.getAttribute('aria-hidden') !== 'true').length,
        covered: rects.length > 0
            && Math.min(...rects.map((rect) => rect.left)) <= frame.left + 1
            && Math.max(...rects.map((rect) => rect.right)) >= frame.right - 1,
        overflowX: Math.round(track.scrollWidth - ribbon.clientWidth),
        wrapped: style.flexWrap,
        chips: ribbon.querySelectorAll('[data-facet-ribbon-set]:not([data-facet-ribbon-clone]) li').length,
    };
});
"""

RIBBON_SEMANTICS = """
/*
 * What the page *says*, as opposed to what it shows.
 *
 * The walk skips every `aria-hidden` subtree, which is precisely the set of
 * things a screen reader will never announce and a copy is required to be. If
 * a skill appears twice in what comes back, the duplication stopped being
 * visual and became a claim.
 */
const names = arguments[0];
const main = document.querySelector('main');

const walk = (node) => {
    if (node.nodeType === Node.TEXT_NODE) return node.textContent;
    if (node.nodeType !== Node.ELEMENT_NODE) return '';
    if (node.getAttribute('aria-hidden') === 'true') return '';
    if (node.hasAttribute('hidden')) return '';
    let text = '';
    for (const child of node.childNodes) text += walk(child) + '\\n';
    return text;
};

const spoken = walk(main);
const counts = {};
for (const name of names) {
    counts[name] = spoken.split('\\n').filter((line) => line.trim() === name).length;
}

return {
    counts,
    visibleChips: [...document.querySelectorAll('[data-facet-ribbon] li')].length,
    spokenChips: [...document.querySelectorAll('[data-facet-ribbon] li')].filter((chip) => chip.closest('[aria-hidden="true"]') === null).length,
};
"""


# How much of the distance a ribbon's own declared pace implies must actually
# be covered. Below 1 because the watch samples over a wire and a browser is
# allowed to drop frames; a ribbon that stopped would miss this by an order of
# magnitude, not by a margin.
SPEED_TOLERANCE = 0.8


def ribbons(
    session: str,
    base_url: str,
    seconds: int,
    skills: list[str],
    reduced_motion: bool,
    no_js: bool,
) -> tuple[dict[str, object], list[str]]:
    """Watches every ribbon for `seconds`, then interrogates one of them."""
    label = "ribbons"
    if reduced_motion:
        label += " (reduced-motion)"
    if no_js:
        label += " (no-JS)"

    failures: list[str] = []

    webdriver("POST", f"/session/{session}/window/rect", {"width": 1440, "height": 1000})
    webdriver("POST", f"/session/{session}/url", {"url": base_url + "/"})

    if no_js:
        # With scripting off there is nothing to interrogate in the browser:
        # the document *is* the answer. So it is fetched and read directly,
        # which is also the strongest form of the claim — every canonical skill
        # exactly once, no copies, and no ribbon that thinks it is live.
        with request.urlopen(base_url + "/", timeout=30) as response:
            served = response.read().decode()

        served = re.sub(r"<script\b[^>]*>.*?</script>", "", served, flags=re.S)
        report: dict[str, object] = {
            "profile": "no-JS",
            "ribbons": len(re.findall(r"data-facet-ribbon(?![-=])", served)),
            "live": served.count("data-facet-ribbon=\"live\""),
            "clones": served.count("data-facet-ribbon-clone"),
            "hidden": served.count('aria-hidden="true"'),
            "chips": {name: served.count(">" + name + "<") for name in skills},
        }

        if report["ribbons"] < 2:
            failures.append(f"{label}: the server sent {report['ribbons']} ribbons")
        if report["live"] or report["clones"]:
            failures.append(f"{label}: the server rendered a scripted ribbon state")
        for name, count in report["chips"].items():
            if count != 1:
                failures.append(f"{label}: '{name}' appears {count} times in the served document")

        return report, failures

    execute(session, "return new Promise((resolve) => setTimeout(resolve, 900));")

    # A ribbon nobody can see is deliberately held, so the watch begins by
    # putting the skills section on screen — otherwise this would measure the
    # off-screen economy, not the loop.
    execute(session, """
        const section = document.querySelector('#skills');
        if (section !== null) section.scrollIntoView({block: 'start'});
        return new Promise((resolve) => setTimeout(resolve, 700));
    """)

    first = execute(session, RIBBON_STATE)
    semantics = execute(session, RIBBON_SEMANTICS, [skills])

    if len(first) < 2:
        failures.append(f"{label}: fewer than two ribbons rendered; short and long datasets cannot both be covered")

    # Every canonical skill is said exactly once, however many times it is drawn.
    for name, count in semantics["counts"].items():
        if count != 1:
            failures.append(f"{label}: '{name}' is announced {count} times")
    if semantics["spokenChips"] != len(skills):
        failures.append(
            f"{label}: {semantics['spokenChips']} chips are semantically present, "
            f"expected the {len(skills)} canonical skills"
        )

    static = reduced_motion or no_js

    for index, ribbon in enumerate(first):
        where = f"{label}: ribbon {index} ({ribbon['direction']})"

        if static:
            if ribbon["live"] is not None:
                failures.append(f"{where}: autoplay was mounted where it must not be")
            if ribbon["clones"] != 0:
                failures.append(f"{where}: {ribbon['clones']} copies exist with no motion to justify them")
            if ribbon["wrapped"] != "wrap":
                failures.append(f"{where}: the static ribbon does not wrap ({ribbon['wrapped']})")
            if ribbon["overflowX"] > 1:
                failures.append(f"{where}: the static ribbon overflows by {ribbon['overflowX']}px")
            continue

        if ribbon["live"] != "live":
            failures.append(f"{where}: never became live")
            continue
        if ribbon["clones"] < 1:
            failures.append(f"{where}: a loop with no repeat cannot be seamless")
        if ribbon["unhiddenClones"] != 0:
            failures.append(f"{where}: {ribbon['unhiddenClones']} copies are exposed to assistive technology")
        if ribbon["focusableInClones"] != 0:
            failures.append(f"{where}: a copy holds {ribbon['focusableInClones']} focusable elements")
        if ribbon["iterations"] != "infinite":
            failures.append(f"{where}: the loop is not infinite ({ribbon['iterations']})")
        if ribbon["playState"] != "running":
            failures.append(f"{where}: an untouched ribbon is not moving")

    # --- the keyboard path ----------------------------------------------
    #
    # A ribbon holds no controls, so the correct keyboard behaviour is that it
    # is not on the path at all: tabbing through the page must never land
    # inside one, and least of all inside a copy.
    keyboard = execute(session, """
        const stops = [];
        const focusable = [...document.querySelectorAll(
            'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )];
        for (const node of focusable) {
            /*
             * `preventScroll` matters: focusing every control on the page
             * would otherwise walk the viewport down to the footer, and a
             * ribbon that scrolled out of view pauses itself — correctly, and
             * ruinously for a watch that has not started yet.
             */
            node.focus({preventScroll: true});
            if (document.activeElement !== node) continue;
            const ribbon = node.closest('[data-facet-ribbon]');
            stops.push({
                inRibbon: ribbon !== null,
                inClone: node.closest('[data-facet-ribbon-clone]') !== null,
            });
        }
        document.activeElement.blur();
        return {stops: stops.length, inRibbon: stops.filter((s) => s.inRibbon).length, inClone: stops.filter((s) => s.inClone).length};
    """)

    # Whatever the sweep did to the viewport, the watch starts with the
    # ribbons on screen.
    execute(session, """
        const section = document.querySelector('#skills');
        if (section !== null) section.scrollIntoView({block: 'start'});
        return new Promise((resolve) => setTimeout(resolve, 500));
    """)

    if keyboard["stops"] < 5:
        failures.append(f"{label}: only {keyboard['stops']} tab stops found; the keyboard sweep proves nothing")
    if keyboard["inRibbon"] or keyboard["inClone"]:
        failures.append(
            f"{label}: {keyboard['inRibbon']} tab stops fall inside a ribbon "
            f"({keyboard['inClone']} of them inside a copy)"
        )

    # --- the long watch -------------------------------------------------
    #
    # Minutes, not seconds. A loop that resets does it once per cycle, and a
    # cycle here is tens of seconds; a short observation would simply miss it.
    samples: list[list[dict[str, object]]] = []
    breaks: list[str] = []
    deadline = time.monotonic() + seconds

    # Distance is accumulated per ribbon, wrapping when the strip passes the
    # end of a cycle. A ribbon that never resets *and* never moves would sail
    # through a coverage check, so the watch measures both.
    travelled = [0.0 for _ in first]
    running = [0 for _ in first]
    previous = [ribbon["x"] for ribbon in execute(session, RIBBON_STATE)]
    started = time.monotonic()

    while time.monotonic() < deadline:
        reading = execute(session, RIBBON_STATE)
        samples.append(reading)

        for index, ribbon in enumerate(reading):
            if static:
                continue
            if not ribbon["covered"]:
                breaks.append(f"ribbon {index} showed the end of its strip at sample {len(samples)}")
            if ribbon["shift"] is not None and not (-ribbon["shift"] - 1 <= ribbon["x"] <= 1):
                breaks.append(
                    f"ribbon {index} travelled to {ribbon['x']}px, outside one set of {ribbon['shift']}px"
                )
            if ribbon["duration"] != first[index]["duration"]:
                breaks.append(f"ribbon {index} changed pace mid-loop")

            # A reversed ribbon runs its keyframes backwards, so its
            # translation counts *up* towards zero. Distance is distance either
            # way; only the sign of the difference changes.
            step = (
                ribbon["x"] - previous[index]
                if ribbon["direction"] == "reverse"
                else previous[index] - ribbon["x"]
            )
            if step < 0:
                # The cycle wrapped: the remaining distance plus the new one.
                step += ribbon["shift"] or 0
            travelled[index] += step
            previous[index] = ribbon["x"]

            if ribbon["playState"] == "running":
                running[index] += 1

        time.sleep(0.5)

    failures.extend(sorted(set(breaks))[:10])

    if not static and samples:
        for index, ribbon in enumerate(first):
            # Nothing held it, so it should have run for essentially the whole
            # watch and covered the distance its own pace implies.
            share = running[index] / len(samples)
            watched = time.monotonic() - started
            expected = SPEED_TOLERANCE * watched * (ribbon["shift"] or 0) / float(ribbon["duration"].rstrip("s"))

            if share < 0.9:
                failures.append(
                    f"{label}: ribbon {index} was running for {share:.0%} of an untouched watch"
                )
            if travelled[index] < expected:
                failures.append(
                    f"{label}: ribbon {index} travelled {travelled[index]:.0f}px in {watched:.0f}s, "
                    f"expected at least {expected:.0f}px"
                )

    interaction: dict[str, object] = {}

    if not static:
        # --- yielding to a pointer, and resuming from where it stopped ----
        probe = execute(session, """
            const ribbon = document.querySelectorAll('[data-facet-ribbon]')[0];
            ribbon.scrollIntoView({block: 'center'});
            const box = ribbon.getBoundingClientRect();
            return [Math.round(box.left + box.width / 2), Math.round(box.top + box.height / 2)];
        """)

        def read_first() -> dict[str, object]:
            return execute(session, RIBBON_STATE)[0]

        def point(pointer_type: str, actions: list[dict[str, object]]) -> None:
            webdriver("POST", f"/session/{session}/actions", {"actions": [{
                "type": "pointer",
                "id": "probe",
                "parameters": {"pointerType": pointer_type},
                "actions": actions,
            }]})

        point("mouse", [{"type": "pointerMove", "duration": 0, "x": probe[0], "y": probe[1]},
                        {"type": "pause", "duration": 400}])
        paused = read_first()
        time.sleep(1.2)
        stillPaused = read_first()

        if paused["playState"] != "paused" or not paused["held"]:
            failures.append(f"{label}: a resting pointer did not yield the autoplay")
        if abs(stillPaused["x"] - paused["x"]) > 1:
            failures.append(
                f"{label}: a paused ribbon kept moving ({paused['x']} -> {stillPaused['x']})"
            )

        point("mouse", [{"type": "pointerMove", "duration": 0, "x": 5, "y": 5},
                        {"type": "pause", "duration": 400}])
        resumed = read_first()

        if resumed["playState"] != "running" or resumed["held"]:
            failures.append(f"{label}: the ribbon did not resume when the pointer left")
        # Resuming means continuing, not restarting: the strip must not have
        # snapped back to the start of the cycle.
        if abs(resumed["x"]) < abs(stillPaused["x"]) - resumed["shift"] / 2:
            failures.append(
                f"{label}: resuming jumped the strip ({stillPaused['x']} -> {resumed['x']})"
            )

        # --- a finger holds it, and lets it go ---------------------------
        point("touch", [{"type": "pointerMove", "duration": 0, "x": probe[0], "y": probe[1]},
                        {"type": "pointerDown", "button": 0},
                        {"type": "pause", "duration": 500}])
        touched = read_first()
        point("touch", [{"type": "pointerUp", "button": 0}, {"type": "pause", "duration": 400}])
        released = execute(session, """
            /* A finger leaves no pointer behind, so nothing may still hold. */
            const ribbon = document.querySelectorAll('[data-facet-ribbon]')[0];
            ribbon.dispatchEvent(new PointerEvent('pointerleave', {pointerType: 'touch'}));
            return null;
        """)
        del released
        time.sleep(0.4)
        after_touch = read_first()

        if touched["playState"] != "paused":
            failures.append(f"{label}: a held finger did not yield the autoplay")
        if after_touch["playState"] != "running":
            failures.append(f"{label}: the ribbon stayed held after the finger left")

        interaction = {
            "paused": paused,
            "stillPaused": stillPaused,
            "resumed": resumed,
            "touched": touched,
            "afterTouch": after_touch,
        }

    return {
        "profile": ("reduced-motion" if reduced_motion else "no-JS" if no_js else "normal"),
        "seconds": seconds,
        "samples": len(samples),
        "first": first,
        "last": samples[-1] if samples else None,
        "semantics": semantics,
        "keyboard": keyboard,
        "interaction": interaction,
        "coverageBreaks": sorted(set(breaks)),
        "travelledPx": [round(distance) for distance in travelled],
        "runningSamples": running,
    }, failures


TRANSITION_PROBE = """
/*
 * What section entry is allowed to cost.
 *
 * The two questions that matter are not "does it look nice" but "did the page
 * change shape" and "is the scroll still the browser's". Both are measured
 * against the document itself: the height and every section's offset are
 * recorded before any reveal has happened, and compared with the same
 * measurements once everything has arrived.
 */
const sections = [...document.querySelectorAll('.facet-main > section')];
const marked = sections.filter((section) => 'facetRevealSection' in section.dataset);
const root = document.documentElement;

return {
    sections: sections.length,
    marked: marked.length,
    revealed: marked.filter((section) => section.dataset.facetReveal === 'in').length,
    /* A section already on screen must never have been hidden. */
    hiddenOnScreen: marked.filter((section) => {
        const box = section.getBoundingClientRect();
        return box.top < window.innerHeight && section.dataset.facetReveal !== 'in';
    }).length,
    scrollBehaviour: getComputedStyle(root).scrollBehavior,
    bodyScrollBehaviour: getComputedStyle(document.body).scrollBehavior,
    height: Math.round(root.scrollHeight),
    /*
     * Layout metrics, not rendered ones. `getBoundingClientRect` includes the
     * transform, so comparing it before and after would report the intended
     * ten-pixel rise as a layout shift — which is exactly the confusion this
     * gate exists to avoid. `offsetTop` and `offsetHeight` ignore transforms
     * and change only if the page really did reflow.
     */
    offsets: sections.map((section) => section.offsetTop),
    boxes: marked.map((section) => [section.offsetWidth, section.offsetHeight]),
    /* Kept for the record: where the sections were actually painted. */
    painted: marked.map((section) => Math.round(section.getBoundingClientRect().top + window.scrollY)),
};
"""

TRANSITION_SCROLL = """
/*
 * A scroll driven the way a reader drives one — in steps, with the browser
 * doing the scrolling — while animation frames are sampled underneath. A
 * scroll handler doing layout work would show up here as dropped frames; a
 * hijacked scroll would show up as a position that does not match the request.
 */
return (async () => {
const root = document.documentElement;
const deltas = [];
let previous = 0;
let running = true;

const tick = (now) => {
    if (!running) return;
    if (previous !== 0) deltas.push(now - previous);
    previous = now;
    requestAnimationFrame(tick);
};
requestAnimationFrame(tick);

const requests = [];
const step = 400;
window.scrollTo(0, 0);
await new Promise((resolve) => setTimeout(resolve, 200));

for (let y = 0; y <= root.scrollHeight - window.innerHeight; y += step) {
    const before = window.scrollY;
    window.scrollBy(0, step);
    await new Promise((resolve) => setTimeout(resolve, 90));
    requests.push([Math.round(before + step), Math.round(window.scrollY)]);
}

await new Promise((resolve) => setTimeout(resolve, 600));
running = false;

const samples = deltas.slice(5).sort((a, b) => a - b);
const at = (q) => samples.length === 0 ? null : Math.round(samples[Math.min(samples.length - 1, Math.floor(samples.length * q))] * 10) / 10;

/* Requests the browser could satisfy exactly; the last one clamps at the end. */
const honoured = requests.filter(([asked, got], index) =>
    Math.abs(asked - got) <= 2 || index === requests.length - 1
).length;

return {
    steps: requests.length,
    honoured,
    frames: samples.length,
    fps: samples.length === 0 ? null : Math.round((1000 / (samples.reduce((a, b) => a + b, 0) / samples.length)) * 10) / 10,
    frameP95: at(0.95),
    frameMax: samples.length === 0 ? null : Math.round(samples[samples.length - 1] * 10) / 10,
};
})();
"""


def transitions(
    session: str,
    base_url: str,
    routes: list[str],
    reduced_motion: bool,
) -> tuple[list[dict[str, object]], list[str]]:
    """Scrolls each route end to end and prices the section-entry transitions."""
    label = "transitions (reduced-motion)" if reduced_motion else "transitions"
    failures: list[str] = []
    report: list[dict[str, object]] = []

    webdriver("POST", f"/session/{session}/window/rect", {"width": 1440, "height": 900})

    for route in routes:
        webdriver("POST", f"/session/{session}/url", {"url": base_url + route})
        execute(session, "return new Promise((resolve) => setTimeout(resolve, 700));")

        before = execute(session, TRANSITION_PROBE)
        scrolled = execute(session, TRANSITION_SCROLL)
        after = execute(session, TRANSITION_PROBE)

        where = f"{label} {route}"
        report.append({"route": route, "before": before, "scroll": scrolled, "after": after})

        if before["scrollBehaviour"] != "auto" or before["bodyScrollBehaviour"] != "auto":
            failures.append(
                f"{where}: scrolling is not the browser's "
                f"({before['scrollBehaviour']} / {before['bodyScrollBehaviour']})"
            )
        if scrolled["honoured"] != scrolled["steps"]:
            failures.append(
                f"{where}: {scrolled['steps'] - scrolled['honoured']} of {scrolled['steps']} "
                "scroll requests were not honoured exactly"
            )
        if before["height"] != after["height"]:
            failures.append(
                f"{where}: the document changed height while revealing "
                f"({before['height']} -> {after['height']})"
            )
        if before["offsets"] != after["offsets"]:
            failures.append(f"{where}: revealing moved a section")
        if before["boxes"] != after["boxes"]:
            failures.append(f"{where}: a reveal resized a section's box")
        if before["hiddenOnScreen"]:
            failures.append(
                f"{where}: {before['hiddenOnScreen']} sections were hidden while already on screen"
            )
        if scrolled["frames"] < 30:
            failures.append(f"{where}: only {scrolled['frames']} frames sampled")
        elif scrolled["frameP95"] is not None and scrolled["frameP95"] > 34:
            failures.append(f"{where}: frame p95 {scrolled['frameP95']} ms while scrolling")

        if reduced_motion:
            if before["marked"] or after["marked"]:
                failures.append(f"{where}: sections were staged for entry under reduced motion")
        else:
            if after["marked"] and after["revealed"] != after["marked"]:
                failures.append(
                    f"{where}: {after['marked'] - after['revealed']} sections never arrived; "
                    "content that scrolling cannot reveal is content that is gone"
                )

    return report, failures


HERO_OFFSCREEN = """
/*
 * The claim in hero.ts is that a slot nobody can see stops asking for frames.
 * It is a claim about work that leaves no trace in the DOM, so the only way to
 * hold it to account is to count the frames themselves.
 *
 * The document is re-parsed in a same-origin iframe whose `requestAnimationFrame`
 * is wrapped while the frame is still `about:blank` — before any module exists
 * to call it. Nothing else in the skin runs a continuous loop: the ribbons are
 * a CSS animation on a promoted layer and the reveal is an IntersectionObserver,
 * so in a still frame with no pointer, essentially every counted frame is the
 * hero's.
 *
 * Then the frame is scrolled until the slot is well clear of the viewport, and
 * the same window is counted again. A hero that kept drawing would report two
 * comparable numbers; a hero that stood down reports a second one at rest.
 */
const html = arguments[0];

return (async () => {

const frame = document.createElement('iframe');
frame.style.cssText = 'display:block;border:0;width:1200px;height:900px';
document.body.replaceChildren(frame);

const view = frame.contentWindow;
const doc = frame.contentDocument;

let frames = 0;
const raf = view.requestAnimationFrame.bind(view);
view.requestAnimationFrame = (callback) => raf((time) => { frames += 1; return callback(time); });

doc.open();
doc.write(html);
doc.close();

/* Long enough for the idle-scheduled hero and its dynamic import. */
await new Promise((resolve) => view.setTimeout(resolve, 4000));

const slot = doc.querySelector('[data-facet-hero-visual]');
const sample = async (ms) => {
    const start = frames;
    await new Promise((resolve) => view.setTimeout(resolve, ms));
    return frames - start;
};

const onscreen = await sample(1000);

/* Far enough that no part of the slot, nor its observer margin, remains. */
view.scrollTo(0, doc.documentElement.scrollHeight);
await new Promise((resolve) => view.setTimeout(resolve, 600));

const offscreen = await sample(1000);
const box = slot === null ? null : slot.getBoundingClientRect();

/* Back on screen: standing down must be a pause, never a one-way exit. */
view.scrollTo(0, 0);
await new Promise((resolve) => view.setTimeout(resolve, 600));

const resumed = await sample(1000);

const result = {
    hero: slot === null ? null : slot.dataset.facetHero || null,
    canvases: doc.querySelectorAll('[data-facet-hero-visual] canvas').length,
    onscreen,
    offscreen,
    resumed,
    slotBottomWhileScrolled: box === null ? null : Math.round(box.bottom),
};

frame.remove();

return result;
})();
"""


def hero_offscreen(session: str, base_url: str) -> tuple[dict[str, object], list[str]]:
    """Prices the hero's animation loop on screen, off screen and back again."""
    failures: list[str] = []

    webdriver("POST", f"/session/{session}/window/rect", {"width": 1440, "height": 1000})
    webdriver("POST", f"/session/{session}/url", {"url": base_url + "/"})
    html = execute(
        session,
        "return fetch(arguments[0]).then((response) => response.text());",
        [base_url + "/"],
    )
    result = execute(session, HERO_OFFSCREEN, [html])

    where = "hero offscreen"

    if result["hero"] != "live" or result["canvases"] != 1:
        # Not a failure of the pause — a failure to have anything to pause.
        # Said plainly, because a silent skip here would read as a pass.
        failures.append(
            f"{where}: the hero never mounted (state '{result['hero']}', "
            f"{result['canvases']} canvases); the trace proves nothing"
        )

        return result, failures

    # A floor, not a frame-rate budget. Headless Firefox drives a nested iframe
    # at roughly half the rate a real window gets, and this gate is not the
    # place to judge that: 15 frames is simply enough of a loop that "0 while
    # off screen" is a decision and not an idle second.
    if result["onscreen"] < 15:
        failures.append(f"{where}: only {result['onscreen']} frames while visible; the trace proves nothing")
    elif result["slotBottomWhileScrolled"] is not None and result["slotBottomWhileScrolled"] > 0:
        failures.append(
            f"{where}: the slot was still on screen after scrolling "
            f"(bottom {result['slotBottomWhileScrolled']}px)"
        )
    else:
        # A tenth of the visible rate. Not zero: one trailing frame may already
        # be queued when the observer fires, and failing that would be failing
        # a correct implementation for the timing of a single callback.
        ceiling = max(2, result["onscreen"] // 10)

        if result["offscreen"] > ceiling:
            failures.append(
                f"{where}: {result['offscreen']} frames drawn off screen against "
                f"{result['onscreen']} on screen (ceiling {ceiling})"
            )

        if result["resumed"] < result["onscreen"] // 2:
            failures.append(
                f"{where}: only {result['resumed']} frames after scrolling back, "
                f"against {result['onscreen']} before; the pause did not lift"
            )

    return result, failures


CONSOLE_PROBE = """
/*
 * A clean console, proved the only way it can be: by owning the console
 * *before* the page's own scripts exist.
 *
 * The document is re-parsed inside a same-origin iframe that is instrumented
 * while it is still `about:blank`, so every warning, error and unhandled
 * rejection any module produces passes through a counter installed before that
 * module ran. Reading the browser's log after the fact would miss anything
 * logged before the harness attached, which is precisely the interesting part.
 */
const html = arguments[0];

return (async () => {

const frame = document.createElement('iframe');
frame.style.cssText = 'display:block;border:0;width:1200px;height:900px';
document.body.replaceChildren(frame);

const view = frame.contentWindow;
const doc = frame.contentDocument;
const noise = [];

for (const level of ['error', 'warn']) {
    const original = view.console[level].bind(view.console);
    view.console[level] = (...args) => {
        noise.push([level, args.map((arg) => String(arg)).join(' ').slice(0, 200)]);
        return original(...args);
    };
}

view.addEventListener('error', (event) => {
    noise.push(['uncaught', String(event.message || event.error).slice(0, 200)]);
});
view.addEventListener('unhandledrejection', (event) => {
    noise.push(['rejection', String(event.reason).slice(0, 200)]);
});

doc.open();
doc.write(html);
doc.close();

/* Long enough for the idle-scheduled hero, its dynamic import and a few frames. */
await new Promise((resolve) => view.setTimeout(resolve, 4000));

const result = {
    noise,
    hero: (() => {
        const slot = doc.querySelector('[data-facet-hero-visual]');
        return slot === null ? null : slot.dataset.facetHero || null;
    })(),
    canvases: doc.querySelectorAll('[data-facet-hero-visual] canvas').length,
    ribbonsLive: doc.querySelectorAll("[data-facet-ribbon='live']").length,
    ribbons: doc.querySelectorAll('[data-facet-ribbon]').length,
    staged: doc.querySelectorAll('[data-facet-reveal-section]').length,
    cards: doc.querySelectorAll('.facet-card').length,
    /*
     * Whether the card's light actually follows a pointer under this profile.
     *
     * The skin leaves no mark until something moves, so the only honest way to
     * ask is to move something: one synthetic `pointermove` at the centre of
     * the first card, delivered to the real listener the grid registered, and
     * then two frames — the handler asks for a frame and `paint` writes on it.
     * `tracked` is the attribute that write sets, so an empty string here is
     * the grid declining rather than the probe arriving too early.
     */
    tracked: await (async () => {
        const card = doc.querySelector('[data-facet-card-grid] .facet-card');

        if (card === null) {
            return null;
        }

        const rect = card.getBoundingClientRect();

        card.dispatchEvent(new view.PointerEvent('pointermove', {
            bubbles: true,
            clientX: rect.left + rect.width / 2,
            clientY: rect.top + rect.height / 2,
            pointerType: 'mouse',
        }));

        await new Promise((resolve) => view.requestAnimationFrame(() => view.requestAnimationFrame(resolve)));

        return card.dataset.facetCard || '';
    })(),
    text: doc.body.innerText.replace(/\\s+/g, ' ').trim().length,
};

frame.remove();

return result;
})();
"""


def console_and_fallback(
    session: str,
    base_url: str,
    routes: list[str],
    profile: str,
) -> tuple[list[dict[str, object]], list[str]]:
    """Runs every route with the console owned, and prices the chosen profile.

    `profile` names what the browser was configured to be for this run:
    `normal`, `no-webgl`, `low-tier`, `reduced-motion`, `pointer-fine` or
    `pointer-coarse`. The hero's expected resting state follows from it, and so
    does whether any enhancement is allowed to have mounted at all.

    The two families are not the same kind of thing, which is why they are
    named apart rather than lumped under "degraded". `no-webgl`, `low-tier` and
    `reduced-motion` are reasons to decline the signature visual, so the hero
    must come to rest on the accepted static fallback. A pointer is not: a
    coarse pointer says nothing about what the device can draw, only that there
    is no path to track, so the hero still runs and it is the *card light* that
    stands down. Asserting one rule for both would have let a real regression —
    a shader skipped on every tablet, or a light left tracking a finger — pass
    as the expected degradation.
    """
    failures: list[str] = []
    report: list[dict[str, object]] = []

    webdriver("POST", f"/session/{session}/window/rect", {"width": 1440, "height": 1000})

    for route in routes:
        webdriver("POST", f"/session/{session}/url", {"url": base_url + route})
        html = execute(
            session,
            "return fetch(arguments[0]).then((response) => response.text());",
            [base_url + route],
        )
        result = execute(session, CONSOLE_PROBE, [html])
        result["route"] = route
        result["profile"] = profile
        report.append(result)

        where = f"console ({profile}) {route}"

        for level, message in result["noise"]:
            failures.append(f"{where}: {level} — {message}")

        # A floor, not a length budget: this catches a page that rendered
        # nothing, and must not fail a legitimately terse one — an authorisation
        # refusal is a short page and a correct one.
        if result["text"] < 100:
            failures.append(f"{where}: the page rendered {result['text']} characters of text")

        # The profiles that are a reason to decline the signature visual. A
        # pointer profile is deliberately not one of them.
        declines_hero = profile in {"no-webgl", "low-tier", "reduced-motion"}

        if route == "/":
            if result["hero"] is None:
                failures.append(f"{where}: the home page lost its hero slot")
            elif declines_hero:
                if result["hero"] != "static":
                    failures.append(f"{where}: the hero settled on '{result['hero']}', not the static fallback")
                if result["canvases"] != 0:
                    failures.append(f"{where}: {result['canvases']} canvases survived the fallback")
            elif result["hero"] != "live":
                failures.append(f"{where}: the hero settled on '{result['hero']}' on a capable browser")

            if profile == "reduced-motion":
                if result["ribbonsLive"]:
                    failures.append(f"{where}: {result['ribbonsLive']} ribbons autoplayed under reduced motion")
                if result["staged"]:
                    failures.append(f"{where}: {result['staged']} sections were staged under reduced motion")
            elif result["ribbons"] and not result["ribbonsLive"]:
                failures.append(f"{where}: the skill ribbons never started")

        # The card light, where the profile actually says what to expect. The
        # default profile is silent on purpose: headless Firefox reports no
        # pointer capabilities at all, so `(hover: hover) and (pointer: fine)`
        # is false for reasons that describe the harness, not a reader.
        tracked = result["tracked"]

        if tracked is not None:
            if profile == "pointer-fine":
                if tracked != "tracked":
                    failures.append(f"{where}: the card light never followed a fine pointer")
            elif profile in {"pointer-coarse", "reduced-motion"}:
                if tracked:
                    failures.append(f"{where}: the card light tracked under '{profile}'")

    return report, failures


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8765")
    parser.add_argument("--output", required=True)
    parser.add_argument("--routes", nargs="+", default=["/"])
    parser.add_argument("--widths", nargs="+", type=int, default=[320, 768, 1440])
    parser.add_argument("--themes", nargs="+", choices=["light", "dark"], default=["light", "dark"])
    parser.add_argument("--no-js", action="store_true")
    parser.add_argument("--login-email")
    parser.add_argument("--login-password")
    parser.add_argument("--contact-states", action="store_true")
    parser.add_argument("--hero-lifecycle", action="store_true")
    parser.add_argument("--hero-offscreen", action="store_true")
    parser.add_argument("--card-interaction", action="store_true")
    parser.add_argument("--reduced-motion", action="store_true")
    parser.add_argument("--pointer", choices=["fine", "coarse", "default"], default="default")
    parser.add_argument("--ribbons", action="store_true")
    parser.add_argument("--ribbon-seconds", type=int, default=180)
    parser.add_argument("--transitions", action="store_true")
    parser.add_argument("--console", action="store_true")
    parser.add_argument("--no-webgl", action="store_true")
    parser.add_argument("--low-tier", action="store_true")
    options = parser.parse_args()

    output = Path(options.output)
    output.mkdir(parents=True, exist_ok=True)
    firefox_options: dict[str, object] = {"args": ["-headless"]}
    prefs: dict[str, object] = {}
    if options.no_js:
        prefs["javascript.enabled"] = False
    if options.reduced_motion:
        # Firefox's own reduced-motion switch: this is the real media query,
        # not a class the page was asked to pretend with.
        prefs["ui.prefersReducedMotion"] = 1
    if options.no_webgl:
        # The real refusal, not a stubbed one: Firefox declines to create any
        # WebGL context, exactly as it would on a machine with no usable driver.
        prefs["webgl.disabled"] = True
    if options.low_tier:
        # The skin's low-tier signal reads `navigator.hardwareConcurrency`.
        # Capping it is how a four-core machine is simulated on a twelve-core one.
        prefs["dom.maxHardwareConcurrency"] = 2
    if options.pointer != "default":
        # Headless Firefox reports *no* pointer capabilities at all, so
        # `(hover: hover)`, `(pointer: fine)` and `(pointer: coarse)` are all
        # false and any rule gated on one of them silently never applies. A
        # gate that did not say which device it was standing in for would be
        # testing a machine no reader owns. 6 is fine + hover (a mouse); 1 is
        # coarse without hover (a finger).
        capability = 6 if options.pointer == "fine" else 1
        prefs["ui.primaryPointerCapabilities"] = capability
        prefs["ui.allPointerCapabilities"] = capability
    if prefs:
        firefox_options["prefs"] = prefs

    value = webdriver("POST", "/session", {
        "capabilities": {"alwaysMatch": {
            "browserName": "firefox",
            "acceptInsecureCerts": True,
            "moz:firefoxOptions": firefox_options,
        }}
    })
    session = value["sessionId"]
    failures: list[str] = []
    report: list[dict[str, object]] = []

    try:
        if options.login_email and options.login_password:
            webdriver("POST", f"/session/{session}/url", {"url": options.base_url + "/login"})
            for selector, text in [
                ("#login-email", options.login_email),
                ("#login-password", options.login_password),
            ]:
                element = element_id(session, selector)
                webdriver("POST", f"/session/{session}/element/{element}/value", {"text": text, "value": list(text)})
            submit = element_id(session, "form[action='/login'] button[type='submit']")
            webdriver("POST", f"/session/{session}/element/{submit}/click", {})
            current = webdriver("GET", f"/session/{session}/url")
            if current.endswith("/login"):
                failures.append(f"login failed for {options.login_email}")

        if options.contact_states:
            webdriver("POST", f"/session/{session}/window/rect", {"width": 768, "height": 900})
            webdriver("POST", f"/session/{session}/url", {"url": options.base_url + "/contact"})
            execute(session, "localStorage.setItem('facet.theme','dark'); document.documentElement.dataset.theme='dark'; document.querySelectorAll('[required]').forEach((field) => field.removeAttribute('required'));")
            submit = element_id(session, "form[action='/contact'] button[type='submit']")
            webdriver("POST", f"/session/{session}/element/{submit}/click", {})
            execute(session, "document.querySelector(\"form[action='/contact'] button[type='submit']\").disabled = true;")
            execute(session, "return new Promise((resolve) => setTimeout(resolve, 450));")
            form_state = execute(session, """
                const button = document.querySelector("form[action='/contact'] button[type='submit']");
                const style = getComputedStyle(button);
                return {
                    invalid: document.querySelectorAll('[aria-invalid=true]').length,
                    errors: [...document.querySelectorAll('[data-facet-field-error]')].map((node) => node.textContent.trim()).filter(Boolean),
                    notice: document.querySelector('#contact-notice')?.textContent.trim() || null,
                    disabled: [style.color, style.backgroundColor, style.cursor],
                };
            """)
            image = webdriver("GET", f"/session/{session}/screenshot")
            (output / "contact-errors-768-dark.png").write_bytes(base64.b64decode(image))
            execute(session, "document.querySelector(\"form[action='/contact'] button[type='submit']\").scrollIntoView({block:'center'});")
            disabled_image = webdriver("GET", f"/session/{session}/screenshot")
            (output / "contact-disabled-768-dark.png").write_bytes(base64.b64decode(disabled_image))
            (output / "contact-form-state.json").write_text(json.dumps(form_state, indent=2) + "\n")
            if form_state["invalid"] != 4 or len(form_state["errors"]) != 4 or not form_state["notice"]:
                failures.append("contact error state did not expose all server-side validation feedback")
            if form_state["disabled"][2] != "not-allowed":
                failures.append("disabled control state is not visibly non-interactive")

        if options.card_interaction:
            cards, card_failures = card_interaction(
                session, options.base_url, options.reduced_motion, options.pointer
            )
            suffix = f"-{options.pointer}" + ("-reduced-motion" if options.reduced_motion else "")
            (output / f"cards{suffix}.json").write_text(json.dumps(cards, indent=2) + "\n")
            failures.extend(card_failures)

        if options.ribbons:
            names = json.loads(Path("content/skills.json").read_text())["skills"]
            watched, ribbon_failures = ribbons(
                session,
                options.base_url,
                options.ribbon_seconds,
                [skill["name"] for skill in names],
                options.reduced_motion,
                options.no_js,
            )
            suffix = "-reduced-motion" if options.reduced_motion else ("-nojs" if options.no_js else "")
            (output / f"ribbons{suffix}.json").write_text(json.dumps(watched, indent=2) + "\n")
            failures.extend(ribbon_failures)

        if options.transitions:
            moved, transition_failures = transitions(
                session, options.base_url, options.routes, options.reduced_motion
            )
            suffix = "-reduced-motion" if options.reduced_motion else ""
            (output / f"transitions{suffix}.json").write_text(json.dumps(moved, indent=2) + "\n")
            failures.extend(transition_failures)

        if options.console:
            profile = (
                "no-webgl" if options.no_webgl
                else "low-tier" if options.low_tier
                else "reduced-motion" if options.reduced_motion
                else f"pointer-{options.pointer}" if options.pointer != "default"
                else "normal"
            )
            logged, console_failures = console_and_fallback(
                session, options.base_url, options.routes, profile
            )
            (output / f"console-{profile}.json").write_text(json.dumps(logged, indent=2) + "\n")
            failures.extend(console_failures)

        if options.hero_offscreen:
            offscreen, offscreen_failures = hero_offscreen(session, options.base_url)
            (output / "hero-offscreen.json").write_text(json.dumps(offscreen, indent=2) + "\n")
            failures.extend(offscreen_failures)

        if options.hero_lifecycle:
            lifecycle, lifecycle_failures = hero_lifecycle(session, options.base_url)
            (output / "hero-lifecycle.json").write_text(json.dumps(lifecycle, indent=2) + "\n")
            failures.extend(lifecycle_failures)

        for width in options.widths:
            webdriver("POST", f"/session/{session}/window/rect", {"width": max(width, 500), "height": 900})
            for route in options.routes:
                slug = "home" if route == "/" else route.strip("/").replace("/", "-").replace("?", "-").replace("=", "-")
                for theme in options.themes:
                    webdriver("POST", f"/session/{session}/url", {"url": options.base_url + route})
                    if not options.no_js:
                        execute(session, "localStorage.setItem('facet.theme', arguments[0]); document.documentElement.dataset.theme = arguments[0];", [theme])
                        if width < 500:
                            execute(session, """
                                return new Promise((resolve) => {
                                    const frame = document.createElement('iframe');
                                    frame.id = 'facet-audit-frame';
                                    frame.src = arguments[0];
                                    frame.style.cssText = `display:block;border:0;width:${arguments[1]}px;height:900px`;
                                    document.body.replaceChildren(frame);
                                    frame.addEventListener('load', resolve, {once: true});
                                });
                            """, [options.base_url + route, width])
                        execute(session, "return new Promise((resolve) => setTimeout(resolve, 450));")
                    state = execute(session, """
                        const frame = document.querySelector('#facet-audit-frame');
                        const page = frame ? frame.contentDocument : document;
                        const pageWindow = frame ? frame.contentWindow : window;
                        const root = page.documentElement;
                        const styles = pageWindow.getComputedStyle(root);
                        const resources = pageWindow.performance.getEntriesByType('resource').map((entry) => entry.name);
                        const first = [...page.querySelectorAll('.facet-nav-toggle, .facet-nav__link, .facet-brand, .facet-theme-toggle')]
                            .find((element) => element.getClientRects().length > 0);
                        if (first) first.focus();
                        pageWindow.scrollTo(0, 0);
                        const focus = first ? pageWindow.getComputedStyle(first) : null;
                        const brand = page.querySelector('.facet-brand');
                        const toggle = page.querySelector('.facet-theme-toggle');
                        const navToggle = page.querySelector('.facet-nav-toggle');
                        const nav = page.querySelector('.facet-nav');
                        return {
                            title: page.title,
                            route: pageWindow.location.pathname,
                            statusText: page.body.innerText.slice(0, 120),
                            overflow: root.scrollWidth - root.clientWidth,
                            viewport: [pageWindow.innerWidth, pageWindow.innerHeight],
                            scroll: [pageWindow.scrollX, pageWindow.scrollY],
                            theme: root.dataset.theme || 'system',
                            ink: styles.getPropertyValue('--facet-ink').trim(),
                            surface: styles.getPropertyValue('--facet-surface').trim(),
                            fontLoaded: page.fonts.check('16px "Facet Sans"'),
                            resources,
                            focus: focus ? [focus.outlineStyle, focus.outlineWidth, focus.outlineColor] : null,
                            brand: brand ? [pageWindow.getComputedStyle(brand).color, pageWindow.getComputedStyle(brand).backgroundColor, pageWindow.getComputedStyle(brand).getPropertyValue('--facet-ink').trim(), pageWindow.getComputedStyle(brand).opacity] : null,
                            toggle: toggle ? [pageWindow.getComputedStyle(toggle).color, pageWindow.getComputedStyle(toggle).backgroundColor] : null,
                            hostileExecuted: pageWindow.pwned === true,
                            messageScriptCount: page.querySelectorAll('.facet-message script').length,
                            messageText: page.querySelector('.facet-message .whitespace-pre-wrap')?.textContent || null,
                            enhanced: root.dataset.facet || null,
                            navToggleDisplay: navToggle ? pageWindow.getComputedStyle(navToggle).display : null,
                            themeToggleDisplay: toggle ? pageWindow.getComputedStyle(toggle).display : null,
                            navDisplay: nav ? pageWindow.getComputedStyle(nav).display : null,
                        };
                    """)
                    local_resources = all(item.startswith(options.base_url) for item in state["resources"])
                    state["resourcesLocal"] = local_resources
                    state["width"] = width
                    state["requestedTheme"] = theme
                    report.append(state)
                    label = f"{slug}-{width}-{theme}{'-nojs' if options.no_js else ''}"
                    image = webdriver("GET", f"/session/{session}/screenshot")
                    (output / f"{label}.png").write_bytes(base64.b64decode(image))
                    if state["overflow"] > 0:
                        failures.append(f"{label}: horizontal overflow {state['overflow']}px")
                    if not state["title"]:
                        failures.append(f"{label}: missing title")
                    if not local_resources:
                        failures.append(f"{label}: non-local resource request")
                    if not options.no_js and not state["fontLoaded"]:
                        failures.append(f"{label}: Facet Sans not loaded")
                    if state["hostileExecuted"] or state["messageScriptCount"]:
                        failures.append(f"{label}: hostile inbox content became executable")
                    if options.no_js and (state["navToggleDisplay"] != "none" or state["themeToggleDisplay"] != "none" or state["navDisplay"] == "none"):
                        failures.append(f"{label}: no-JS navigation/control contract failed")
    finally:
        webdriver("DELETE", f"/session/{session}")

    (output / "report.json").write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps({"screenshots": len(report), "failures": failures}, indent=2))
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
