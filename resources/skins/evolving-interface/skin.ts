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
  whenIdle(() => void enhanceHero(root));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => enhance());
} else {
  enhance();
}

export {
  SKIN_ID,
  enhance,
  enhanceCards,
  enhanceHero,
  enhanceReveal,
  enhanceRibbons,
  isLowTier,
  markSkin,
  prefersReducedMotion,
};
