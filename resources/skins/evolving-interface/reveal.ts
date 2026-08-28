/**
 * evolving-interface — section entry.
 *
 * The most restrained enhancement in the skin, and the one with the most ways
 * to go wrong, so the constraints are worth stating before the code:
 *
 * - **Scrolling stays the browser's.** Nothing here listens for `scroll`,
 *   changes `scroll-behavior`, or moves the viewport. The page scrolls at the
 *   speed the reader's own machine scrolls, with their own inertia, and a
 *   trackpad, a wheel, a spacebar and a screen reader's caret all behave
 *   exactly as they did before.
 * - **Only what is below the fold is ever hidden.** A section already on
 *   screen when this runs is left alone, so nothing that was painted can flash
 *   away and come back.
 * - **The page never moves.** A section fades and rises by ten pixels of
 *   `transform`; its box is never resized, so no other section shifts and the
 *   document's height is the same before and after.
 * - **Everything is given back.** One observer, disconnected on teardown, and
 *   every attribute removed — which is what makes a mid-visit switch to
 *   reduced motion restore the plain document rather than a half-revealed one.
 */

/** A mounted reveal. Every attribute it set is removed by `destroy()`. */
interface RevealHandle {
  destroy(): void;
}

/**
 * How far into the viewport a section must come before it is considered
 * arrived. A section that begins its entry the instant its first pixel appears
 * has finished before the reader has read anything.
 */
const ARRIVAL = '0px 0px -12% 0px';

function mountReveal(sections: HTMLElement[]): RevealHandle | null {
  if (sections.length === 0) {
    return null;
  }

  /*
   * Read everything, then write everything.
   *
   * The classification is one batch of geometry reads with no writes between
   * them, so the browser answers all of them from a single layout. Interleaving
   * would force one layout per section.
   */
  const below = sections.filter((section) => section.getBoundingClientRect().top > window.innerHeight * 0.9);

  if (below.length === 0) {
    return null;
  }

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) {
          continue;
        }

        const section = entry.target as HTMLElement;

        section.dataset.facetReveal = 'in';

        /*
         * A section arrives once. Keeping it observed would mean paying for
         * every subsequent crossing to answer a question already settled, and
         * would let a section fade out again as the reader scrolls back — which
         * is decoration turning into a nuisance.
         */
        observer.unobserve(section);
      }
    },
    { rootMargin: ARRIVAL }
  );

  for (const section of below) {
    section.dataset.facetRevealSection = '';
    observer.observe(section);
  }

  return {
    destroy(): void {
      observer.disconnect();

      for (const section of below) {
        delete section.dataset.facetRevealSection;
        delete section.dataset.facetReveal;
      }
    },
  };
}

export { mountReveal, ARRIVAL };
export type { RevealHandle };
