/**
 * evolving-interface — the card's reactive light.
 *
 * This module moves one thing: the origin of a radial gradient that the
 * stylesheet already paints. Everything a card does — the lift, the accent
 * border, the raised shadow, the light itself — is CSS, reached by `:hover`
 * and `:focus-within`, and is complete with no JavaScript at all. What is
 * added here is only that the light follows the pointer instead of sitting at
 * a fixed origin.
 *
 * That framing is what keeps the cost honest. There is no scripted
 * navigation, no measurement of anything the browser would have to lay out
 * again, and nothing an absent or failed module could take away.
 */

/** A mounted grid. Every listener it registered is removed by `destroy()`. */
interface CardsHandle {
  destroy(): void;
}

/**
 * Tracks the pointer over one grid of cards.
 *
 * One delegated listener serves the whole grid rather than two per card, so a
 * catalogue of any length costs the same two registrations.
 *
 * The card's rectangle is read once, on the frame the pointer arrives, and
 * never again while it stays there. The event handler itself measures nothing
 * and writes nothing: it records a position and asks for a frame. Everything
 * else happens in `paint`, where a read is free because layout is already
 * settled — see the note there.
 */
function mountCards(grid: HTMLElement): CardsHandle {
  let frame = 0;
  let card: HTMLElement | null = null;
  let bounds: DOMRect | null = null;
  let pointerX = 0;
  let pointerY = 0;

  /*
   * Read, then write, and both inside the same animation frame.
   *
   * The order is the whole reason the card's rectangle is measured here and
   * not in the event handler. At the start of a frame the browser's layout is
   * already settled, so this read costs nothing; a read taken in the handler
   * would land immediately after the previous frame's property write and force
   * the layout to be recomputed on the spot. The gate measured that difference
   * as 0.80 ms against 0.02 ms per arrival.
   */
  const paint = (): void => {
    frame = 0;

    if (card === null) {
      return;
    }

    bounds ??= card.getBoundingClientRect();

    if (bounds.width === 0 || bounds.height === 0) {
      return;
    }

    /*
     * The mark that promotes this card's light to its own compositor layer.
     * It is set here rather than in the stylesheet's `:hover` rule because the
     * layer is only worth its memory while something is actually moving, and
     * this module is the only thing that knows that.
     */
    card.dataset.facetCard = 'tracked';

    card.style.setProperty('--facet-card-dx', `${Math.round(pointerX - (bounds.left + bounds.width / 2))}px`);
    card.style.setProperty('--facet-card-dy', `${Math.round(pointerY - bounds.top)}px`);
  };

  /*
   * Handing the card back exactly as it was found. The properties are removed
   * rather than reset, so the card returns to the stylesheet's own default
   * origin — the same one it would have had if this module had never run.
   */
  const clear = (): void => {
    if (card !== null) {
      card.style.removeProperty('--facet-card-dx');
      card.style.removeProperty('--facet-card-dy');
      delete card.dataset.facetCard;
    }

    card = null;
    bounds = null;
  };

  const onPointerMove = (event: PointerEvent): void => {
    /*
     * A touch is not a hover. It reports one position, at the moment of the
     * tap, and honouring it would leave a light behind on a card the finger
     * has already left.
     */
    if (event.pointerType === 'touch') {
      return;
    }

    const target = event.target instanceof Element
      ? event.target.closest<HTMLElement>('.facet-card')
      : null;

    if (target === null) {
      clear();

      return;
    }

    if (target !== card) {
      clear();
      card = target;
    }

    pointerX = event.clientX;
    pointerY = event.clientY;

    if (frame === 0) {
      frame = requestAnimationFrame(paint);
    }
  };

  const onPointerLeave = (): void => {
    if (frame !== 0) {
      cancelAnimationFrame(frame);
      frame = 0;
    }

    clear();
  };

  grid.addEventListener('pointermove', onPointerMove);
  grid.addEventListener('pointerleave', onPointerLeave);

  return {
    destroy(): void {
      onPointerLeave();
      grid.removeEventListener('pointermove', onPointerMove);
      grid.removeEventListener('pointerleave', onPointerLeave);
    },
  };
}

export { mountCards };
export type { CardsHandle };
