#!/usr/bin/env python3
"""Dependency-free Firefox/WebDriver visual and layout audit."""

from __future__ import annotations

import argparse
import base64
import json
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
    options = parser.parse_args()

    output = Path(options.output)
    output.mkdir(parents=True, exist_ok=True)
    firefox_options: dict[str, object] = {"args": ["-headless"]}
    if options.no_js:
        firefox_options["prefs"] = {"javascript.enabled": False}

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
