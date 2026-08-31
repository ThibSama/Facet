/**
 * Light/dark control for the shared shell.
 *
 * Three things live here: which theme a visitor who has never chosen one
 * should get, how a choice is remembered, and what the document does while it
 * changes from one theme to the other.
 *
 * **What it stores** is a single browser-local preference: one key in
 * localStorage, holding the word "light" or "dark". No cookie, so it is never
 * sent to the server; nothing about the visitor beyond which of two
 * stylesheets they prefer. Every read and write is guarded, because storage
 * throws outright in some privacy modes and a theme switch is not worth
 * breaking a page over.
 *
 * **What it defaults to** is the visitor's own clock. A portfolio read at
 * eleven at night should not open white, and the browser already knows the
 * local hour — no geolocation, no sunrise table, no request. `07:00` to
 * `20:00` is day and the rest is night, and that rule is written twice on
 * purpose: once here and once in the pre-paint bootstrap, which has to reach
 * the same answer before this module has loaded or the page would resolve the
 * theme twice and flash in between. The two implementations are held to each
 * other by tests rather than by a shared import, because the bootstrap is an
 * inline script that cannot import anything.
 *
 * A stored choice always wins. The clock is what happens in its absence, not
 * a thing that overrules it at eight the next morning.
 */
const STORAGE_KEY = 'facet.theme';

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

/**
 * Marks the document for the duration of a manual theme change, and nothing
 * else: it is never set during boot, and it is always removed afterwards.
 * The stylesheet uses it to run the fallback crossfade where the View
 * Transition API is missing; the tests use it to observe that a switch is a
 * transition rather than a jump.
 */
const TRANSITION_ATTRIBUTE = 'data-facet-theme-shift';

/**
 * How long the document takes to cross between themes. Matches the duration
 * the stylesheet gives both mechanisms; the extra frames on the cleanup timer
 * are slack, so a busy main thread ends the transition late rather than
 * cutting it short.
 */
const TRANSITION_MS = 320;
const TRANSITION_CLEANUP_MS = TRANSITION_MS + 120;

/** The first hour of the day half, inclusive. */
const DAY_STARTS_AT = 7;

/** The first hour of the night half, inclusive. */
const NIGHT_STARTS_AT = 20;

type Theme = 'light' | 'dark';

function isTheme(value: unknown): value is Theme {
  return value === 'light' || value === 'dark';
}

/** The stored choice, or null when there is none — or none can be read. */
function storedTheme(): Theme | null {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);

    return isTheme(stored) ? stored : null;
  } catch {
    return null;
  }
}

function storeTheme(theme: Theme): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, theme);
  } catch {
    // Unwritable storage costs the visitor persistence, not the page.
  }
}

/**
 * The theme the visitor's own clock asks for: day from 07:00 to 19:59, night
 * from 20:00 to 06:59. An hour that is not an hour — a stubbed `Date`, a
 * runtime with no clock — falls back to light rather than guessing.
 */
function timeTheme(now: Date = new Date()): Theme {
  const hour = now.getHours();

  if (!Number.isFinite(hour) || hour < 0 || hour > 23) {
    return 'light';
  }

  return hour >= DAY_STARTS_AT && hour < NIGHT_STARTS_AT ? 'light' : 'dark';
}

/**
 * The theme a visitor should be shown right now: their choice if they made
 * one, and otherwise the hour where they are. This is the whole resolution
 * contract, and the inline bootstrap is a copy of it.
 */
function resolvedTheme(): Theme {
  return storedTheme() ?? timeTheme();
}

/**
 * What the document is actually showing: the theme the pre-paint bootstrap
 * stamped on <html>, and — if something prevented it from running at all —
 * whatever the same rule resolves to now.
 */
function activeTheme(root: Document = document): Theme {
  const declared = root.documentElement.getAttribute('data-theme');

  return isTheme(declared) ? declared : resolvedTheme();
}

function applyTheme(theme: Theme, root: Document = document): void {
  root.documentElement.setAttribute('data-theme', theme);
}

function prefersReducedMotion(): boolean {
  return (
    typeof window.matchMedia === 'function' && window.matchMedia(REDUCED_MOTION_QUERY).matches
  );
}

/**
 * Which switch the cleanup belongs to. A visitor who presses the control four
 * times in a second starts four transitions, and only the last one is allowed
 * to decide when the document has stopped transitioning — otherwise the first
 * one's timer strips the attribute out from under the fourth.
 */
let switchToken = 0;

/**
 * Changes the theme the way a person changes it: visibly, over about a third
 * of a second, rather than as one repaint from near-white to near-black.
 *
 * The mechanism is deliberately the plain one. The document is marked for the
 * length of the change and the stylesheet gives everything under that mark a
 * short colour transition, so the page crossfades rather than cuts. What this
 * module contributes is only the mark, the theme, and the guarantee that the
 * mark comes off.
 *
 * The View Transition API was tried here and is not used, for a reason worth
 * writing down. It produced the better crossfade in Chromium and behaved
 * badly in WebKit: `finished` never settled and the document stayed
 * uninteractive long enough to swallow presses, which is a worse defect than
 * the one being fixed. Half the engines animating one way and half the other
 * is also two behaviours to reason about rather than one, and this transition
 * is not important enough to be worth that. If WebKit's implementation
 * settles down, the swap is confined to this function.
 *
 * Under `prefers-reduced-motion: reduce` there is no mechanism at all: the
 * theme is applied, and that is the entire animation.
 */
function switchTheme(theme: Theme, root: Document = document, onApplied?: () => void): void {
  const apply = (): void => {
    applyTheme(theme, root);
    onApplied?.();
  };

  if (prefersReducedMotion()) {
    apply();

    return;
  }

  const token = ++switchToken;
  const element = root.documentElement;

  const settle = (): void => {
    if (token === switchToken) {
      element.removeAttribute(TRANSITION_ATTRIBUTE);
    }
  };

  /*
   * The mark goes on, the browser is made to look at it, and only then does
   * the theme change.
   *
   * The middle step is not a superstition. A transition runs between two
   * computed styles, and the earlier of them has to already declare the
   * transition — so marking the document and repainting it in the same frame
   * produces exactly the jump this code exists to remove. Reading a layout
   * property forces the style to be resolved with the mark in place, which is
   * the cheapest way to make the before-change style real. One forced reflow
   * per press, and never again.
   */
  element.setAttribute(TRANSITION_ATTRIBUTE, '');
  void element.offsetWidth;
  apply();

  // And it always comes off. The timer is the whole cleanup: no listener to
  // miss, nothing to unbind, and a press that arrives mid-transition simply
  // takes ownership of the mark from the one before it.
  window.setTimeout(settle, TRANSITION_CLEANUP_MS);
}

/**
 * Reveals and wires the theme control the shell rendered hidden.
 *
 * It is a toggle button, so its accessible state is `aria-pressed`: pressed
 * means the dark theme is the one currently in effect. The label stays fixed —
 * a button whose name changes as you press it is far harder to follow.
 */
function enhanceThemeControls(root: Document = document): void {
  const controls = Array.from(
    root.querySelectorAll<HTMLButtonElement>('[data-facet-theme-toggle]')
  );

  if (controls.length === 0) {
    return;
  }

  /*
   * The theme in effect, held here rather than re-read from the document on
   * every press.
   *
   * Under the View Transition path the attribute is written a frame after the
   * press, inside the callback the browser calls; a second press in that frame
   * that asked the document what theme it was in would be told the old one and
   * would cancel the first rather than continue past it. Four quick presses
   * have to be four switches, so what a press advances is this value, and the
   * document follows it.
   */
  let current = activeTheme(root);

  // Normally a no-op: the bootstrap has already stamped this exact value. It
  // matters only when the inline script could not run, and it is written
  // without a transition because the document is being corrected, not
  // switched.
  applyTheme(current, root);

  const sync = (): void => {
    const pressed = current === 'dark' ? 'true' : 'false';

    for (const control of controls) {
      control.setAttribute('aria-pressed', pressed);
    }
  };

  for (const control of controls) {
    control.hidden = false;
    control.addEventListener('click', () => {
      current = current === 'dark' ? 'light' : 'dark';

      // The choice is recorded the moment it is made: a visitor who closes the
      // tab mid-transition still chose.
      storeTheme(current);
      switchTheme(current, root, sync);
    });
  }

  sync();
}

export {
  STORAGE_KEY,
  TRANSITION_ATTRIBUTE,
  activeTheme,
  applyTheme,
  enhanceThemeControls,
  resolvedTheme,
  storedTheme,
  switchTheme,
  timeTheme,
};
export type { Theme };
