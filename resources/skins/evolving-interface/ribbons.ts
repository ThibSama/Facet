/**
 * evolving-interface — the skill ribbons.
 *
 * A ribbon is a thing the runtime *makes* out of a list the server already
 * sent, and can unmake without loss. The document carries one wrapping list of
 * chips per canonical category, every skill in it exactly once; this module
 * measures that list, repeats it enough times to fill the viewport, and hands
 * the strip to CSS to translate at a constant speed.
 *
 * Three properties are load-bearing and each is arranged for deliberately:
 *
 * - **The loop has no seam.** The strip travels exactly one set's width, so the
 *   frame the animation restarts on is pixel-identical to the frame before it.
 *   There is no reset to see because there is nothing to see.
 * - **The copies are copies.** Every repeat is `aria-hidden`, holds nothing
 *   focusable, and is removed on teardown, so what a screen reader, a search
 *   engine and a reader with no JavaScript receive is the canonical list once.
 * - **Nothing runs per frame.** The motion is a CSS animation on a promoted
 *   layer. This module measures at mount, adds copies when the viewport grows,
 *   and otherwise does nothing at all until the reader touches it.
 */

/** A mounted ribbon. Everything it added is undone by `destroy()`. */
interface RibbonHandle {
  destroy(): void;
}

/**
 * Travel speed, in CSS pixels per second.
 *
 * Constant across ribbons rather than a constant duration: a category with
 * three skills and one with eleven must read at the same pace, and a shared
 * duration would make the short one crawl and the long one race.
 */
const SPEED = 42;

/**
 * Repeats needed to keep the strip covered.
 *
 * One set's width of travel has to leave the viewport still full, so the strip
 * must be at least as wide as the viewport *plus* the distance it moves.
 */
function repeats(viewport: number, shift: number): number {
  return Math.ceil(viewport / shift) + 1;
}

function mountRibbon(ribbon: HTMLElement): RibbonHandle | null {
  const track = ribbon.querySelector<HTMLElement>('[data-facet-ribbon-track]');
  const set = track?.querySelector<HTMLElement>('[data-facet-ribbon-set]') ?? null;

  if (track === null || set === null) {
    return null;
  }

  /*
   * The distance is one set plus the gap that follows it, because the gap is
   * part of the rhythm: translating by the set alone would butt the second
   * copy against the first and the loop would visibly tighten once a cycle.
   */
  const gap = Number.parseFloat(getComputedStyle(track).columnGap) || 0;
  const shift = set.getBoundingClientRect().width + gap;

  if (!Number.isFinite(shift) || shift <= 0) {
    return null;
  }

  ribbon.style.setProperty('--facet-ribbon-shift', `${shift}px`);
  ribbon.style.setProperty('--facet-ribbon-duration', `${(shift / SPEED).toFixed(2)}s`);

  const clones: HTMLElement[] = [];

  /*
   * Copies are only ever added, never removed while the ribbon is live. A
   * removal would change the strip under a running animation, and the one
   * thing this module must never do is make the loop stutter; an extra copy
   * scrolled past the edge of a narrowed viewport costs nothing.
   */
  const cover = (): void => {
    const needed = repeats(ribbon.clientWidth, shift);

    while (clones.length + 1 < needed) {
      const clone = set.cloneNode(true) as HTMLElement;

      clone.setAttribute('aria-hidden', 'true');
      clone.dataset.facetRibbonClone = '';
      track.append(clone);
      clones.push(clone);
    }
  };

  cover();

  /*
   * Reasons to stand still, counted rather than toggled. A pointer resting on
   * a ribbon that then scrolls out of view has given two reasons to pause and
   * taking one away must not start it moving again.
   */
  const holds = new Set<string>();

  const hold = (reason: string): void => {
    holds.add(reason);
    ribbon.dataset.facetRibbonHold = '';
  };

  const release = (reason: string): void => {
    holds.delete(reason);

    if (holds.size === 0) {
      delete ribbon.dataset.facetRibbonHold;
    }
  };

  const onPointerEnter = (): void => hold('pointer');
  const onPointerLeave = (): void => release('pointer');
  const onPointerDown = (): void => hold('press');
  const onPointerUp = (): void => release('press');
  const onFocusIn = (): void => hold('focus');
  const onFocusOut = (): void => release('focus');

  ribbon.addEventListener('pointerenter', onPointerEnter);
  ribbon.addEventListener('pointerleave', onPointerLeave);
  ribbon.addEventListener('pointerdown', onPointerDown);
  ribbon.addEventListener('pointerup', onPointerUp);
  ribbon.addEventListener('pointercancel', onPointerUp);
  ribbon.addEventListener('focusin', onFocusIn);
  ribbon.addEventListener('focusout', onFocusOut);

  /* A ribbon nobody can see is a compositor layer being animated for nobody. */
  const visibility = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) {
        release('offscreen');
      } else {
        hold('offscreen');
      }
    }
  });

  const box = new ResizeObserver(() => cover());

  visibility.observe(ribbon);
  box.observe(ribbon);

  ribbon.dataset.facetRibbon = 'live';

  return {
    destroy(): void {
      visibility.disconnect();
      box.disconnect();

      ribbon.removeEventListener('pointerenter', onPointerEnter);
      ribbon.removeEventListener('pointerleave', onPointerLeave);
      ribbon.removeEventListener('pointerdown', onPointerDown);
      ribbon.removeEventListener('pointerup', onPointerUp);
      ribbon.removeEventListener('pointercancel', onPointerUp);
      ribbon.removeEventListener('focusin', onFocusIn);
      ribbon.removeEventListener('focusout', onFocusOut);

      for (const clone of clones.splice(0)) {
        clone.remove();
      }

      /*
       * Back to the document the server sent, attribute for attribute: the
       * live flag is what every ribbon rule is gated on, so removing it is
       * what restores the plain wrapping list.
       */
      delete ribbon.dataset.facetRibbon;
      delete ribbon.dataset.facetRibbonHold;
      ribbon.style.removeProperty('--facet-ribbon-shift');
      ribbon.style.removeProperty('--facet-ribbon-duration');
    },
  };
}

export { mountRibbon, repeats, SPEED };
export type { RibbonHandle };
