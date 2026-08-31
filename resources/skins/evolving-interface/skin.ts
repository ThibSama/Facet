import './skin.css';
import { mountCards } from './cards';
import { mountRibbon } from './ribbons';
import { mountReveal } from './reveal';

/**
 * evolving-interface — skin runtime.
 *
 * It marks the document with the skin that rendered it, so the isolation
 * contract is observable in the DOM; it decides whether the hero's signature
 * visual is worth running, loading it only when the answer is yes; and it
 * hands the card grid its pointer-reactive light. Like the shared runtime it
 * decorates server-rendered markup and never produces it, and every one of
 * those decorations is additive: the document is complete before any of them
 * run and stays complete if none of them do.
 *
 * Everything expensive lives behind a dynamic import. A page with no hero slot
 * — every route but the home page — resolves the guard and stops, so it never
 * fetches the shader module at all.
 */
const SKIN_ID = 'evolving-interface';

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

/**
 * A fine pointer that can actually hover. Coarse pointers are excluded because
 * there is nothing for them to track: a tap has one position and no path.
 */
const FINE_POINTER = '(hover: hover) and (pointer: fine)';

/*
 * The skin's single reduced-motion query list, opened once and held for the
 * life of the document. It is retained rather than re-queried at teardown
 * because a listener whose reference is not held cannot be removed, and it is
 * the *only* list for this query so that a change event has exactly one place
 * to arrive.
 */
const motion = typeof window.matchMedia === 'function' ? window.matchMedia(REDUCED_MOTION) : null;

/**
 * The slot's own record of what happened to it, and the guard against a second
 * initialisation: anything already stamped is left alone.
 *
 * - `static` — the fallback is what you see, deliberately. Reduced motion, a
 *   low-tier device, no WebGL2, or an error.
 * - `pending` — the module is being fetched.
 * - `live`  — the effect is mounted; this is what fades the canvas in.
 */
type HeroState = 'static' | 'pending' | 'live';

function markSkin(root: Document = document): void {
  root.documentElement.dataset.facetSkin = SKIN_ID;
}

/**
 * Whether the visitor has asked for less movement.
 *
 * It reads the one query list the skin retains rather than opening another.
 * Two lists for the same query answer identically, so the difference is not
 * the answer — it is that a change delivered to one of them is invisible to
 * the other, and "which list is the live one" is exactly the sort of question
 * a teardown must never have to ask.
 */
function prefersReducedMotion(): boolean {
  return motion !== null && motion.matches;
}

/**
 * A coarse, deliberately pessimistic low-tier signal.
 *
 * Neither figure describes a GPU, and neither is meant to: the question is not
 * "how fast is this device" but "is there any reason to think an animated
 * effect is a bad idea here". Four cores or less, or 4 GB or less of reported
 * memory, is reason enough — the cost of being wrong is a static hero that
 * already looks finished. `deviceMemory` is Chromium-only, so its absence must
 * never be read as a low-tier answer.
 */
function isLowTier(): boolean {
  const cores = navigator.hardwareConcurrency;

  if (typeof cores === 'number' && cores > 0 && cores <= 4) {
    return true;
  }

  const memory = (navigator as Navigator & { deviceMemory?: number }).deviceMemory;

  return typeof memory === 'number' && memory <= 4;
}

function setState(slot: HTMLElement, state: HeroState): void {
  slot.dataset.facetHero = state;
}

/**
 * The skin's teardown register.
 *
 * Every enhancement this file mounts pushes one function here, and the page
 * owns exactly one `pagehide` listener and exactly one reduced-motion listener
 * no matter how many of them mounted. That is not only tidiness: a per-effect
 * listener would make "how many listeners does a mounted page hold" a function
 * of which effects happened to qualify, and an invariant nobody can state is
 * an invariant nobody can test. One and one is testable, and
 * `tools/firefox-audit.py --hero-lifecycle` tests it in a real browser.
 *
 * Teardown is deterministic rather than left to collection: a bfcache restore
 * would otherwise resume work nobody is watching, and Firefox fires `pagehide`
 * where `unload` would have cost the page its cache entry. Turning reduced
 * motion on mid-visit is a request to stop, so it is honoured the same way —
 * every effect is undone and the server-rendered document is what remains.
 */
const teardowns: Array<() => void> = [];

let released = false;

const onMotionChange = (event: MediaQueryListEvent): void => {
  if (event.matches) {
    release();
  }
};

/**
 * Undoes every mounted effect exactly once.
 *
 * `released` makes the path idempotent: whichever signal arrives first, and
 * however often either repeats, each effect is destroyed exactly once and the
 * listeners that could deliver a second signal are gone.
 */
const release = (): void => {
  if (released) {
    return;
  }

  released = true;
  window.removeEventListener('pagehide', release);
  motion?.removeEventListener('change', onMotionChange);

  for (const teardown of teardowns.splice(0)) {
    teardown();
  }
};

/**
 * Registers one effect's teardown, arming the page's two lifecycle listeners
 * the first time anything does.
 */
function onRelease(teardown: () => void): void {
  if (released) {
    teardown();

    return;
  }

  if (teardowns.length === 0) {
    window.addEventListener('pagehide', release);
    motion?.addEventListener('change', onMotionChange);
  }

  teardowns.push(teardown);
}

/**
 * Loads and mounts the signature visual, if it is wanted and if it works.
 *
 * Every failure path ends the same way — the slot is marked `static` and the
 * server-rendered fallback is left exactly as it was. Nothing is logged: a
 * decorative effect declining to run is not a fault, and the console belongs
 * to the person debugging the page.
 */
async function enhanceHero(root: Document = document): Promise<void> {
  const slot = root.querySelector<HTMLElement>('[data-facet-hero-visual]');

  if (slot === null || slot.dataset.facetHero !== undefined) {
    return;
  }

  /*
   * A slot with no box is not on screen: below 40rem the hero visual is not
   * rendered at all, and a narrow viewport is exactly where an unused network
   * request is worth the least. Checked before the import, so the shader is
   * never fetched for something nobody can see.
   */
  if (slot.getClientRects().length === 0) {
    setState(slot, 'static');

    return;
  }

  if (prefersReducedMotion() || isLowTier()) {
    setState(slot, 'static');

    return;
  }

  setState(slot, 'pending');

  try {
    const { mountHero } = await import('./hero');
    const handle = mountHero(slot);

    if (handle === null) {
      setState(slot, 'static');

      return;
    }

    setState(slot, 'live');

    /*
     * The hero hands its teardown to the skin's register rather than owning a
     * listener of its own. Destroying the effect and restoring the slot's
     * accepted static state are one step, so there is no order in which the
     * canvas is gone but the slot still claims to be live.
     */
    onRelease((): void => {
      handle.destroy();
      setState(slot, 'static');
    });
  } catch {
    setState(slot, 'static');
  }
}

/**
 * How long a click sequence stays alive, in milliseconds.
 *
 * It is the gap between two clicks rather than the length of the whole burst,
 * so a determined but unhurried player is not timed out halfway. Half a second
 * is comfortably above a fast double-click's ~120 ms and comfortably below the
 * pause a person makes between two separate decisions to click something.
 */
const RUN_CLICK_WINDOW = 500;

/** How many of them, in a row, are the gesture. */
const RUN_CLICK_COUNT = 5;

/**
 * Arms the Satoshi Run easter egg on the brand link.
 *
 * The game has no button, no route and no menu item: five rapid clicks on the
 * Facet mark in the top-left corner start it, and nothing else does. That is
 * the whole discovery design, and it is deliberately not advertised — an
 * easter egg that announces itself is a feature with a bad label.
 *
 * Nothing is imported here. The module, its stylesheet and its renderer are
 * fetched inside the gesture's own handler, so a visitor who never performs it
 * never requests the chunk, never allocates a context and never pays a frame.
 *
 * ## What this is allowed to cost the link
 *
 * The brand is a real home link and stays one: it is an `<a href>` in the
 * server-rendered document, it is focusable, Enter follows it, middle-click
 * and modifier-click open it where the visitor asked, and with JavaScript off
 * every one of those still works. Three rules keep the gesture from taking any
 * of that away.
 *
 * - **A click that would go somewhere is never touched.** Only a click whose
 *   destination is the page already on screen is a candidate — the brand on
 *   the home page, pointing at the home page. On every other route the link is
 *   not merely untouched, it is not even counted: the first click leaves, and
 *   nothing here runs again.
 * - **Only a pointer counts.** A `click` with no pointer press behind it came
 *   from the keyboard, and the keyboard is never the gesture. Enter on the
 *   focused mark navigates — reload included, on the home page — the first
 *   time and every time.
 * - **Nothing waits on a timer that a page load can win.** An earlier draft
 *   held the reload for the length of the window and performed it if no second
 *   click arrived, so that even a lone click kept its navigation exactly. It
 *   worked, and it was wrong: a timer racing a page load means a machine under
 *   load can reload the document out from under a gesture the visitor is
 *   halfway through, and a page that reloads while you are clicking it is a
 *   worse answer than any this was protecting.
 *
 * So the one thing the gesture does cost is this: on the home page, clicking
 * the mark with a pointer no longer reloads the home page. That navigation is
 * a request to be shown the page you are already looking at, it is the only
 * one in the document that can be described that way, and every other way of
 * asking for it — the reload button, F5, Ctrl-R, Enter on the mark itself —
 * still works. It is a deliberate trade and not an oversight.
 */
/**
 * The run's chrome, in the language the server rendered the page in.
 *
 * The game is a deferred chunk and cannot ask anyone anything, so its labels
 * travel on the element the gesture is attached to. A missing, empty or
 * malformed attribute yields nothing at all, and the run falls back to its own
 * defaults: a game that will not read its labels is still a game.
 */
function runLabels(brand: HTMLAnchorElement): Record<string, string> | undefined {
  const raw = brand.dataset.facetRunLabels;

  if (raw === undefined || raw === '') {
    return undefined;
  }

  try {
    const parsed: unknown = JSON.parse(raw);

    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return undefined;
    }

    const labels: Record<string, string> = {};

    for (const [name, value] of Object.entries(parsed as Record<string, unknown>)) {
      if (typeof value === 'string' && value !== '') {
        labels[name] = value;
      }
    }

    return labels;
  } catch {
    return undefined;
  }
}

function enhanceRun(root: Document = document): void {
  const brand = root.querySelector<HTMLAnchorElement>('[data-facet-brand]');

  if (brand === null) {
    return;
  }

  /* One game at a time, and one import at a time: a second gesture while the
     chunk is still in flight must not start a second run behind the first. */
  let pending = false;
  let active: { destroy(): void } | null = null;

  let clicks = 0;
  let lastClick = 0;
  let expiry = 0;
  /** When a real pointer was last pressed on the brand. See `onPointerDown`. */
  let pressedAt = 0;

  /*
   * A closed run is not an active one.
   *
   * `active` is what refuses a second game, so it has to mean "a game is on
   * screen" rather than "a game was once started". The run reports its own
   * close — by the button, by Escape, or because the page is going away — and
   * this is where that report is spent: the handle is dropped, and the gesture
   * is available again. Without it the egg works exactly once per document,
   * which is not a game with a Close button, it is a game with a Quit button.
   */
  const forget = (): void => {
    active = null;
  };

  const launch = async (): Promise<void> => {
    if (pending || active !== null) {
      return;
    }

    pending = true;

    try {
      const { mountSatoshiRun } = await import('./satoshi-run/run');

      active = mountSatoshiRun({
        returnFocus: brand,
        reducedMotion: prefersReducedMotion(),
        onClose: forget,
        labels: runLabels(brand),
      });
    } catch {
      /* A game that will not load leaves the page exactly as it was. */
      active = null;
    } finally {
      pending = false;
    }
  };

  const cancelExpiry = (): void => {
    if (expiry !== 0) {
      window.clearTimeout(expiry);
      expiry = 0;
    }
  };

  /**
   * Whether this click is one the browser was going to spend on the page it is
   * already showing. Anything else — a modified click, a middle click, a link
   * pointing somewhere else — is not the gesture and is handed straight back
   * to the browser.
   */
  const isSelfNavigation = (event: MouseEvent): boolean => {
    if (event.defaultPrevented || event.button !== 0) {
      return false;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }

    return brand.pathname === window.location.pathname
      && brand.search === window.location.search
      && brand.host === window.location.host;
  };

  /**
   * The last real press, and the whole of how a click is told from a keypress.
   *
   * Enter on a focused link produces a `click`, and the obvious way to spot
   * one — `detail === 0`, the click that came from no press — is not portable:
   * Firefox reports 1 there, so a keyboard user tabbing to the mark and
   * activating it five times would launch a game they never asked for. A
   * pointer press is the fact the engines do agree on. A click with none
   * behind it came from the keyboard, and the keyboard is never the gesture.
   */
  const onPointerDown = (event: PointerEvent): void => {
    if (event.button !== 0 || !event.isPrimary) {
      return;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    pressedAt = event.timeStamp;
  };

  const onClick = (event: MouseEvent): void => {
    if (!isSelfNavigation(event)) {
      return;
    }

    /* No press behind it: a keyboard activation, which navigates untouched. */
    if (pressedAt === 0 || event.timeStamp - pressedAt > RUN_CLICK_WINDOW) {
      return;
    }

    /* A press is spent by the click it produced. Without this, an Enter that
       lands shortly after a click would inherit that click's press and be
       counted as one — the exact thing the check above exists to prevent. */
    pressedAt = 0;

    /*
     * A pointer click on the page the link already points at. It buys a tick
     * of the counter and nothing else; see the note above for what it costs.
     */
    event.preventDefault();
    cancelExpiry();

    const now = event.timeStamp;
    clicks = now - lastClick <= RUN_CLICK_WINDOW ? clicks + 1 : 1;
    lastClick = now;

    if (clicks >= RUN_CLICK_COUNT) {
      clicks = 0;

      void launch();

      return;
    }

    /*
     * The sequence expires on its own, so one click today and four tomorrow
     * are not a gesture. The timeout is a convenience rather than the rule —
     * the gap is checked against the clock on every click above, which is what
     * makes a late timer harmless.
     */
    expiry = window.setTimeout((): void => {
      expiry = 0;
      clicks = 0;
    }, RUN_CLICK_WINDOW);
  };

  brand.addEventListener('pointerdown', onPointerDown);
  brand.addEventListener('click', onClick);

  onRelease((): void => {
    brand.removeEventListener('pointerdown', onPointerDown);
    brand.removeEventListener('click', onClick);
    cancelExpiry();
    /* The run is torn down last: it gives focus back to the brand on the way
       out, and `destroy` is idempotent, so a run that already closed itself
       makes this a no-op. */
    active?.destroy();
    active = null;
  });
}

/**
 * The effect is an afterthought by design: it is scheduled for the first idle
 * moment after the document is usable, so it competes with nothing that
 * matters. The hero's text is server-rendered and is the LCP candidate — it is
 * painted long before this runs, and no code path here can delay it.
 */
function whenIdle(task: () => void): void {
  const idle = (window as Window & { requestIdleCallback?: (cb: () => void, options?: { timeout: number }) => number })
    .requestIdleCallback;

  if (typeof idle === 'function') {
    idle(task, { timeout: 1000 });

    return;
  }

  window.setTimeout(task, 200);
}

/**
 * Gives every card grid on the page its pointer-reactive light.
 *
 * Three conditions have to hold, and each of them describes a reader rather
 * than a device: there has to be a grid, the pointer has to be one that can
 * hover along a path, and motion has to be welcome. When any of them fails the
 * cards keep the full CSS treatment they were served with — the lift, the
 * accent border, the raised shadow and a light at a fixed origin — so
 * declining to run costs the card nothing but the tracking.
 */
function enhanceCards(root: Document = document): void {
  const grids = root.querySelectorAll<HTMLElement>('[data-facet-card-grid]');

  if (grids.length === 0 || prefersReducedMotion()) {
    return;
  }

  if (typeof window.matchMedia === 'function' && !window.matchMedia(FINE_POINTER).matches) {
    return;
  }

  for (const grid of grids) {
    const cards = mountCards(grid);

    onRelease((): void => cards.destroy());
  }
}

/**
 * Turns each server-rendered skill list into a continuous ribbon.
 *
 * Reduced motion is the only guard, and it is the right one: a ribbon is
 * motion and nothing else. Every other reader gets it, on a touch screen as
 * much as on a desktop, because the strip travels on its own and needs no
 * hover to be readable. A ribbon that declines to mount is simply the wrapping
 * list of chips the server sent — complete, and not missing anything.
 */
function enhanceRibbons(root: Document = document): void {
  if (prefersReducedMotion()) {
    return;
  }

  for (const ribbon of root.querySelectorAll<HTMLElement>('[data-facet-ribbon]')) {
    const mounted = mountRibbon(ribbon);

    if (mounted !== null) {
      onRelease((): void => mounted.destroy());
    }
  }
}

/**
 * Lets each section below the fold arrive rather than simply be there.
 *
 * The hero is excluded by name. It is the page's signature moment and it is
 * already on screen when this runs; fading it in would be an animation played
 * at the one reader who is guaranteed not to be waiting for it.
 */
function enhanceReveal(root: Document = document): void {
  if (prefersReducedMotion()) {
    return;
  }

  const sections = [...root.querySelectorAll<HTMLElement>('.facet-main > section:not(.facet-hero)')];
  const mounted = mountReveal(sections);

  if (mounted !== null) {
    onRelease((): void => mounted.destroy());
  }
}

function enhance(root: Document = document): void {
  markSkin(root);
  enhanceCards(root);
  enhanceRibbons(root);
  enhanceReveal(root);
  enhanceRun(root);
  whenIdle(() => void enhanceHero(root));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => enhance());
} else {
  enhance();
}

export {
  RUN_CLICK_COUNT,
  RUN_CLICK_WINDOW,
  SKIN_ID,
  enhance,
  enhanceCards,
  enhanceHero,
  enhanceReveal,
  enhanceRibbons,
  enhanceRun,
  isLowTier,
  markSkin,
  prefersReducedMotion,
};
