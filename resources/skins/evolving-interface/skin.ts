import './skin.css';

/**
 * evolving-interface — skin runtime.
 *
 * Two jobs, both decoration. It marks the document with the skin that rendered
 * it, so the isolation contract is observable in the DOM; and it decides
 * whether the hero's signature visual is worth running, loading it only when
 * the answer is yes. Like the shared runtime it decorates server-rendered
 * markup and never produces it.
 *
 * Everything expensive lives behind a dynamic import. A page with no hero slot
 * — every route but the home page — resolves the guard and stops, so it never
 * fetches the shader module at all.
 */
const SKIN_ID = 'evolving-interface';

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

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

function prefersReducedMotion(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia(REDUCED_MOTION).matches;
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
     * Teardown is deterministic rather than left to collection: a bfcache
     * restore would otherwise resume a loop nobody is watching, and Firefox
     * fires `pagehide` where `unload` would have cost the page its cache
     * entry. Turning reduced motion on mid-visit is a request to stop, so it
     * is honoured the same way.
     *
     * Both signals converge on one `release`, and every reference it needs to
     * unregister itself is held here for the lifetime of this mounted hero:
     * the query list is queried once, and the change handler is a named
     * function rather than an anonymous argument, because a listener whose
     * reference is not retained cannot be removed. `released` makes the path
     * idempotent — whichever signal arrives first, and however often either
     * repeats, the effect is destroyed exactly once.
     */
    const motion = typeof window.matchMedia === 'function' ? window.matchMedia(REDUCED_MOTION) : null;

    let released = false;

    const onMotionChange = (event: MediaQueryListEvent): void => {
      if (event.matches) {
        release();
      }
    };

    const release = (): void => {
      if (released) {
        return;
      }

      released = true;
      window.removeEventListener('pagehide', release);
      motion?.removeEventListener('change', onMotionChange);
      handle.destroy();
      setState(slot, 'static');
    };

    window.addEventListener('pagehide', release);
    motion?.addEventListener('change', onMotionChange);
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

function enhance(root: Document = document): void {
  markSkin(root);
  whenIdle(() => void enhanceHero(root));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => enhance());
} else {
  enhance();
}

export { SKIN_ID, enhance, enhanceHero, isLowTier, markSkin, prefersReducedMotion };
