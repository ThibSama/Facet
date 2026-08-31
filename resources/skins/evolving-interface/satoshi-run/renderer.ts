/**
 * Satoshi Run — the renderer boundary.
 *
 * Everything above this line is the simulation; everything below it is a way
 * of looking at the simulation. The contract is deliberately small — take a
 * world, take a size, draw, and give everything back — because the point of it
 * is that the drawing half is replaceable. PORT-131 may decide a scene library
 * is worth its transfer cost; if it does, it implements `RunRenderer` and
 * nothing in `world.ts`, `run.ts` or the tests changes.
 *
 * Two implementations ship today, and the choice between them is made once, at
 * mount, by asking for the better one and accepting the answer:
 *
 * - `webgl-renderer.ts` — accelerated 2.5D, real perspective, instanced boxes.
 * - `canvas-renderer.ts` — the same lane in 2D, for a browser with no WebGL2
 *   or a visitor whose browser refuses it. It is a smaller picture of the same
 *   game, never a smaller game.
 */
import type { World } from './world';

/** What a renderer is told about the moment it is drawing. */
interface Frame {
  /** Seconds since the run's presentation began. Never drives the rules. */
  readonly time: number;
  /** Seconds since the last drawn frame, for purely decorative easing. */
  readonly delta: number;
}

interface RunRenderer {
  /** Which one actually mounted. Reported in the HUD and asserted in tests. */
  readonly kind: 'webgl2' | 'canvas2d';
  /** Device-pixel dimensions. Called on every real size change, not per frame. */
  resize(width: number, height: number): void;
  /** Re-reads the palette. Called when the document's theme changes under it. */
  retheme(): void;
  draw(world: World, frame: Frame): void;
  destroy(): void;
}

interface RendererOptions {
  /**
   * The element whose computed style carries the palette. The renderers read
   * the same semantic custom properties the rest of the skin paints with, so a
   * theme switch mid-run moves the game with it.
   */
  readonly palette: Element;
  /**
   * When true: no camera drift, no impact shake, no particles. The lane still
   * scrolls and the runner still jumps, because that is the game rather than
   * decoration around it.
   */
  readonly reducedMotion: boolean;
  /**
   * Told once, when this renderer has stopped being able to draw and will not
   * recover on its own.
   *
   * A driver reset, a GPU the compositor took back, a tab restored from the
   * background on a machine short of memory: the accelerated lane can die
   * mid-run through nothing the game did, and it dies silently — the surface
   * keeps its last frame while the simulation carries on behind it. That is
   * the one failure the mount-time choice cannot cover, because at mount time
   * it had not happened yet.
   *
   * Reporting it is the whole of the renderer's responsibility here. What to
   * do about it is a lifecycle decision and belongs to `run.ts`, which owns
   * the canvas and can mount the other lane on a fresh one. A renderer that
   * cannot lose its context — the 2D one — simply never calls this.
   */
  readonly onContextLost?: () => void;
}

/** `#rgb` / `#rrggbb` to 0..1 components, or null when it is neither. */
function parseHex(value: string): [number, number, number] | null {
  const hex = value.trim().replace('#', '');

  if (!/^[0-9a-f]{3}$|^[0-9a-f]{6}$/i.test(hex)) {
    return null;
  }

  const full = hex.length === 3 ? hex.replace(/./g, (channel) => channel + channel) : hex;
  const int = Number.parseInt(full, 16);

  return [((int >> 16) & 255) / 255, ((int >> 8) & 255) / 255, (int & 255) / 255];
}

type Colour = [number, number, number];

/**
 * The colours a lane is built from, all of them semantic tokens the skin
 * already defines. `dark` is read from the surface's luminance rather than
 * from `data-theme`, because the attribute is absent whenever the visitor is
 * simply following their system — the luminance is a fact about what is
 * actually on screen.
 */
interface Palette {
  canvas: Colour;
  surface: Colour;
  raised: Colour;
  accent: Colour;
  accentSoft: Colour;
  danger: Colour;
  ink: Colour;
  coin: Colour;
  dark: boolean;
}

function readPalette(element: Element): Palette {
  const styles = getComputedStyle(element);
  const read = (token: string, fallback: Colour): Colour =>
    parseHex(styles.getPropertyValue(token)) ?? fallback;

  const canvas = read('--facet-canvas', [0.02, 0.02, 0.07]);
  const luminance = 0.2126 * canvas[0] + 0.7152 * canvas[1] + 0.0722 * canvas[2];

  return {
    canvas,
    surface: read('--facet-surface', [0.03, 0.04, 0.09]),
    raised: read('--facet-surface-raised', [0.06, 0.09, 0.16]),
    accent: read('--facet-accent', [0.70, 0.65, 1.0]),
    accentSoft: read('--facet-accent-soft', [0.15, 0.13, 0.31]),
    danger: read('--facet-danger', [1.0, 0.58, 0.55]),
    ink: read('--facet-ink', [0.96, 0.96, 1.0]),
    /*
     * The one colour that is not a skin token. Bitcoin's orange is the coin's
     * identity rather than the skin's, and a coin tinted with the page accent
     * would stop reading as a coin at all.
     */
    coin: [0.97, 0.58, 0.10],
    dark: luminance < 0.5,
  };
}

function mix(a: Colour, b: Colour, amount: number): Colour {
  return [
    a[0] + (b[0] - a[0]) * amount,
    a[1] + (b[1] - a[1]) * amount,
    a[2] + (b[2] - a[2]) * amount,
  ];
}

function css(colour: Colour, alpha = 1): string {
  const channel = (value: number): number => Math.round(Math.min(1, Math.max(0, value)) * 255);

  return `rgba(${channel(colour[0])}, ${channel(colour[1])}, ${channel(colour[2])}, ${alpha})`;
}

export { css, mix, parseHex, readPalette };
export type { Colour, Frame, Palette, RendererOptions, RunRenderer };
