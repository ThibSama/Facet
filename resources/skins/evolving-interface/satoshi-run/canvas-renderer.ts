/**
 * Satoshi Run — the fallback lane.
 *
 * Canvas 2D, for a browser with no WebGL2 or one that refused the context. It
 * is the same run: the same obstacles at the same distances, the same coins,
 * the same collisions, because all of that happened in `world.ts` before this
 * file was asked to draw anything.
 *
 * It is also the same *picture*, as far as a CPU can carry it. The composition
 * is identical to the accelerated lane — the runner a fifth of the way in, the
 * ground low, the world travelling towards him — and so is the cast: the same
 * hooded icon, the same struck coins, the same four silhouettes. What is lost
 * is a true perspective; what replaces it is a fixed oblique projection and
 * three scenery layers scrolling at three rates, which reads at a glance as
 * the same lane drawn more cheaply. That is the point: a visitor on the
 * fallback should not be able to tell they are being given less game, because
 * they are not.
 */
import { css, mix, readPalette } from './renderer';
import type { Colour, Frame, Palette, RendererOptions, RunRenderer } from './renderer';
import { DUCKING_HEIGHT, STANDING_HEIGHT } from './world';
import type { Obstacle, World } from './world';

/** How far a solid's top face is pushed up-and-right, in world units. */
const DEPTH_X = 0.26;
const DEPTH_Y = 0.3;

/** The same composition the accelerated lane uses, in the same numbers. */
const RUNNER_COLUMN = 0.22;
const GROUND_LINE = 0.76;
const WIDE_SPAN = 24;
const NARROW_SPAN = 13.5;

/** A stable pseudo-random for a scenery index. Shared with the WebGL lane. */
function hash(index: number): number {
  const value = Math.sin(index * 127.1 + 311.7) * 43758.5453;

  return value - Math.floor(value);
}

function mountCanvasRenderer(canvas: HTMLCanvasElement, options: RendererOptions): RunRenderer | null {
  const context = canvas.getContext('2d');

  if (context === null) {
    return null;
  }

  let palette: Palette = readPalette(options.palette);
  let width = 1;
  let height = 1;
  /** Device pixels per world unit, recomputed on resize. */
  let unit = 40;
  /** Where the ground line sits, in device pixels from the top. */
  let ground = 0;
  /** The runner's column, in device pixels from the left. */
  let origin = 0;
  /** How much lane is on screen, in world units. */
  let span = WIDE_SPAN;

  const resize = (nextWidth: number, nextHeight: number): void => {
    width = Math.max(1, nextWidth);
    height = Math.max(1, nextHeight);
    canvas.width = width;
    canvas.height = height;

    /*
     * A wide stage is shown wide lane; a narrow one is shown less of it, so a
     * phone gets a bigger runner rather than a longer view it cannot read.
     * Identical to the accelerated lane's rule, and for the same reason.
     */
    const aspect = width / height;
    const t = Math.min(1, Math.max(0, (aspect - 0.75) / (1.7 - 0.75)));
    span = NARROW_SPAN + (WIDE_SPAN - NARROW_SPAN) * t;

    unit = width / span;
    ground = height * GROUND_LINE;
    origin = width * RUNNER_COLUMN;
  };

  const toX = (worldX: number): number => origin + worldX * unit;
  const toY = (worldY: number): number => ground - worldY * unit;

  /** One solid: front face, top face, and the side that catches the light. */
  const solid = (x: number, y: number, w: number, h: number, colour: Colour, depth = 1): void => {
    const left = toX(x);
    const bottom = toY(y);
    const boxWidth = w * unit;
    const boxHeight = h * unit;
    const dx = DEPTH_X * unit * depth;
    const dy = DEPTH_Y * unit * depth;

    context.fillStyle = css(mix(colour, [0, 0, 0], 0.34));
    context.beginPath();
    context.moveTo(left + boxWidth, bottom);
    context.lineTo(left + boxWidth + dx, bottom - dy);
    context.lineTo(left + boxWidth + dx, bottom - dy - boxHeight);
    context.lineTo(left + boxWidth, bottom - boxHeight);
    context.closePath();
    context.fill();

    context.fillStyle = css(mix(colour, [1, 1, 1], 0.2));
    context.beginPath();
    context.moveTo(left, bottom - boxHeight);
    context.lineTo(left + boxWidth, bottom - boxHeight);
    context.lineTo(left + boxWidth + dx, bottom - boxHeight - dy);
    context.lineTo(left + dx, bottom - boxHeight - dy);
    context.closePath();
    context.fill();

    context.fillStyle = css(colour);
    context.fillRect(left, bottom - boxHeight, boxWidth, boxHeight);
  };

  const disc = (x: number, y: number, radius: number, colour: Colour, squash = 1): void => {
    context.fillStyle = css(colour);
    context.beginPath();
    context.ellipse(toX(x), toY(y), Math.max(1, radius * unit), Math.max(1, radius * unit * squash), 0, 0, Math.PI * 2);
    context.fill();
  };

  /**
   * A contact shadow: the same information it carries on the accelerated lane.
   *
   * In a jumping game a shadow is not decoration — it is how a player reads
   * where they are about to land, so it is the one piece of the 2.5D treatment
   * the fallback keeps in full.
   */
  const shadow = (x: number, y: number, size: number): void => {
    const lift = Math.min(1, y / 3.4);
    const dark = mix(palette.canvas, [0, 0, 0], palette.dark ? 0.5 : 0.18);

    disc(x, 0.03, size * (1 - lift * 0.45) * 0.5, mix(dark, palette.canvas, lift * 0.6), 0.28);
  };

  /** The runner: the same icon, in flat faces. */
  const drawRunner = (world: World): void => {
    const y = world.y;
    const hoodie = palette.dark
      ? mix(palette.accent, [1, 1, 1], 0.1)
      : mix(palette.accent, [0.05, 0.04, 0.14], 0.42);
    const trouser = mix(hoodie, palette.dark ? palette.canvas : [0, 0, 0], 0.35);
    const skin = palette.dark ? mix(palette.ink, palette.accent, 0.18) : mix(palette.ink, palette.accent, 0.3);
    const orange: Colour = [0.97, 0.58, 0.1];

    shadow(0, y, world.ducking ? 1.5 : 1.15);

    if (world.ducking) {
      const tall = DUCKING_HEIGHT;
      const scuff = world.grounded ? Math.sin(world.distance * 3.4) * 0.06 : 0;

      solid(-0.72, y + 0.05, 0.5, 0.26, trouser);
      solid(-0.86 + scuff, y + 0.02, 0.34, 0.14, trouser);
      solid(-0.5, y + 0.12, 1.0, tall * 0.5, hoodie);
      solid(0.06, y + tall * 0.56, 0.62, 0.3, hoodie);
      disc(0.44, y + tall * 0.52, 0.23, skin);
      solid(0.18, y + tall * 0.32, 0.2, 0.2, orange, 0.3);

      return;
    }

    const swing = world.grounded ? Math.sin(world.distance * 2.9) : 0.55;
    const air = world.grounded ? 0 : 0.16;

    /* Back leg and back arm first, so the near side overlaps them. */
    solid(-0.26 - swing * 0.24, y + air, 0.24, 0.66, mix(trouser, [0, 0, 0], 0.22));
    solid(-0.32 - swing * 0.24, y + air, 0.42, 0.13, mix(trouser, [0, 0, 0], 0.4));
    solid(-0.22 + swing * 0.26, y + 0.78, 0.2, 0.46, mix(hoodie, [0, 0, 0], 0.2));

    solid(-0.26 + swing * 0.24, y + air * 0.6, 0.24, 0.66, trouser);
    solid(-0.32 + swing * 0.24, y + air * 0.6, 0.42, 0.13, mix(trouser, [0, 0, 0], 0.25));

    solid(-0.28, y + 0.62, 0.56, 0.5, hoodie);
    solid(-0.26, y + 1.08, 0.52, 0.26, mix(hoodie, palette.dark ? [1, 1, 1] : [0, 0, 0], 0.12));

    /* The ₿, worn rather than drawn. */
    solid(-0.08, y + 0.76, 0.19, 0.3, orange, 0.25);

    solid(-0.2 - swing * 0.26, y + 0.78, 0.2, 0.46, hoodie);

    disc(0.02, y + STANDING_HEIGHT - 0.22, 0.22, skin);
    solid(-0.28, y + STANDING_HEIGHT - 0.34, 0.5, 0.34, hoodie);
    solid(-0.3, y + STANDING_HEIGHT - 0.1, 0.62, 0.1, hoodie);
    solid(0.06, y + STANDING_HEIGHT - 0.16, 0.34, 0.08, mix(hoodie, [0, 0, 0], 0.3));
  };

  /** A Bitcoin: a struck disc with a rim, a face and the ₿ raised on it. */
  const drawCoin = (x: number, y: number, phase: number): void => {
    const gold: Colour = [0.98, 0.66, 0.16];
    const radius = 0.39;

    /* The coin narrows as it turns, which is the whole of its thickness. */
    const squash = Math.max(0.12, Math.abs(phase));
    const centreX = toX(x);

    context.save();
    context.translate(centreX, 0);
    context.scale(squash, 1);
    context.translate(-centreX, 0);

    disc(x, y, radius, mix(gold, [0.42, 0.18, 0.02], 0.45));
    disc(x, y, radius * 0.78, mix(gold, [1, 1, 1], 0.14));

    if (squash > 0.5) {
      context.fillStyle = css(mix(gold, [0.35, 0.14, 0.01], 0.7));
      context.font = `700 ${Math.round(radius * 2.1 * unit)}px system-ui, -apple-system, "Segoe UI", sans-serif`;
      context.textAlign = 'center';
      context.textBaseline = 'middle';
      context.fillText('₿', centreX, toY(y) + unit * 0.02);
    }

    context.restore();
  };

  const drawObstacle = (world: World, obstacle: Obstacle, frame: Frame): void => {
    const local = obstacle.x - world.distance;
    const w = obstacle.width;
    const h = obstacle.height;
    const fatal = world.fatal === obstacle;

    const solidTone = fatal
      ? palette.danger
      : palette.dark
        ? mix(palette.ink, palette.canvas, 0.42)
        : mix(palette.ink, [0.02, 0.02, 0.06], 0.72);

    if (obstacle.kind === 'bank') {
      shadow(local + w / 2, 0, w * 1.5);

      const stone = solidTone;
      const shade = mix(stone, palette.canvas, 0.28);

      solid(local - 0.08, 0, w + 0.16, 0.1, mix(stone, palette.canvas, 0.15));
      solid(local + w * 0.07, 0.1, w * 0.86, 0.82, shade);

      for (let index = 0; index < 4; index += 1) {
        solid(local + 0.1 + index * (w - 0.28) / 3.4, 0.14, 0.11, 0.74, stone, 0.3);
      }

      solid(local - 0.07, 0.92, w + 0.14, 0.14, stone);
      solid(local, 1.06, w, 0.12, stone);
      solid(local + w * 0.19, 1.18, w * 0.62, 0.1, stone);
      solid(local + w * 0.35, 1.28, w * 0.3, 0.07, mix(stone, palette.accent, 0.35));

      return;
    }

    if (obstacle.kind === 'candle') {
      shadow(local + w / 2, 0, w * 1.6);

      /* Red in both themes: it is the market's colour, not the skin's. */
      const red: Colour = fatal ? [1, 0.36, 0.3] : [0.86, 0.21, 0.24];

      solid(local, 0, w, h - 0.22, red);
      solid(local + w / 2 - 0.045, h - 0.22, 0.09, 0.22, mix(red, palette.ink, 0.35));
      solid(local + 0.07, 0.08, w - 0.14, h - 0.42, mix(red, [0, 0, 0], 0.24), 0.15);

      return;
    }

    const drift = options.reducedMotion ? 0 : Math.sin(frame.time * 1.4 + obstacle.variant * 6.28) * 0.07;
    const base = obstacle.base + drift;

    if (obstacle.kind === 'barrier') {
      const bar = fatal ? palette.danger : mix(palette.accent, palette.dark ? [1, 1, 1] : [0, 0, 0], 0.18);
      const hanger = mix(palette.canvas, palette.accent, palette.dark ? 0.3 : 0.35);

      solid(local + 0.12, base + h, 0.1, 2.6, hanger, 0.4);
      solid(local + w - 0.22, base + h, 0.1, 2.6, hanger, 0.4);
      solid(local, base, w, h, bar);

      for (let index = 0; index < 4; index += 1) {
        solid(local + 0.14 + index * (w - 0.34) / 4, base + 0.16, 0.16, h - 0.32, mix(bar, [0.02, 0.02, 0.05], 0.62), 0.12);
      }

      return;
    }

    const panel = fatal ? palette.danger : palette.dark
      ? mix(palette.ink, palette.accentSoft, 0.35)
      : mix(palette.ink, [0.02, 0.02, 0.06], 0.62);
    const mark: Colour = fatal ? [1, 0.5, 0.45] : [0.98, 0.72, 0.16];

    solid(local, base, w, h, panel);
    solid(local + 0.04, base + 0.06, w - 0.08, h - 0.12, mix(panel, palette.canvas, 0.18), 0.12);

    for (const at of [w * 0.27, w * 0.73]) {
      solid(local + at - 0.05, base + h * 0.34, 0.1, h * 0.4, mark, 0.1);
      solid(local + at - 0.05, base + h * 0.14, 0.1, 0.1, mark, 0.1);
    }

    disc(local + 0.06, base + h / 2, 0.11, mark);
    disc(local + w - 0.06, base + h / 2, 0.11, mark);
  };

  /**
   * The scenery, in three layers at three rates.
   *
   * The accelerated lane gets its parallax from a real perspective; here the
   * rates are written down. They are the same rates — roughly a third, a half
   * and three quarters of the lane — so the two renderers read as one game
   * seen through different pipelines rather than as two different games.
   */
  const drawScenery = (world: World, frame: Frame): void => {
    const sky = palette.dark
      ? mix(palette.canvas, palette.accentSoft, 0.5)
      : mix(palette.canvas, palette.accent, 0.08);

    context.fillStyle = css(sky);
    context.fillRect(0, 0, width, height);

    /* A haze band just above the ground: the horizon, and the thing that
       stops the sky and the lane meeting at a hard edge. */
    context.fillStyle = css(mix(sky, palette.accent, palette.dark ? 0.14 : 0.09));
    context.fillRect(0, ground - unit * 3.4, width, unit * 3.4);

    /* Far: a skyline of stacked blocks, barely apart from the sky. */
    const far = mix(sky, palette.dark ? palette.accent : palette.ink, palette.dark ? 0.16 : 0.07);
    context.fillStyle = css(far);

    for (let index = -1; index < 16; index += 1) {
      const period = 5.2;
      const at = Math.floor(world.distance * 0.32 / period) + index;
      const local = at * period - world.distance * 0.32;
      const noise = hash(at);
      const tall = (1.6 + noise * 3.1) * unit;
      const left = toX(local);

      context.fillRect(left, ground - unit * 0.35 - tall, (1.5 + noise * 0.8) * unit, tall);
      context.fillRect(left + 0.3 * unit, ground - unit * 0.35 - tall - 0.35 * unit, 0.9 * unit, 0.35 * unit);
    }

    /* Mid: the chain — blocks in a line, linked, one in four confirming. */
    const chain = mix(sky, palette.accent, palette.dark ? 0.32 : 0.18);

    for (let index = -1; index < 12; index += 1) {
      const period = 3.4;
      const at = Math.floor(world.distance * 0.5 / period) + index;
      const local = at * period - world.distance * 0.5;
      const y = 3.4 + hash(at * 3) * 1.5;
      const size = 0.78 + hash(at * 7) * 0.26;

      const phase = (world.distance * 0.05 + hash(at * 11) * 6.28) % 6.28;
      const confirming = !options.reducedMotion && at % 4 === 0 && phase < 1.4;

      context.fillStyle = css(mix(chain, palette.accent, palette.dark ? 0.5 : 0.4));
      context.fillRect(toX(local + size), toY(y) - 1, (period - size) * unit, Math.max(1, 0.05 * unit));

      context.fillStyle = css(confirming ? mix(chain, palette.accent, 0.75) : chain);
      context.fillRect(toX(local), toY(y) - size * unit * 0.5, size * unit, size * unit);
    }

    /*
     * Node masts, above the lane and never crossing it. A mast that runs down
     * to the ground line is a vertical bar exactly where an obstacle stands,
     * and scenery that can be mistaken for an obstacle is worse than none.
     */
    const pylon = mix(sky, palette.accent, palette.dark ? 0.26 : 0.18);

    for (let index = -1; index < 7; index += 1) {
      const period = 8;
      const at = Math.floor(world.distance * 0.36 / period) + index;
      const local = at * period - world.distance * 0.36;

      solid(local, 2.6, 0.16, 2.6, pylon, 0.3);
      solid(local - 0.3, 5.2, 0.8, 0.14, pylon, 0.3);
      disc(local + 0.1, 5.4, 0.14, mix(pylon, palette.accent, 0.7));
    }

    if (!options.reducedMotion) {
      context.fillStyle = css(mix(sky, palette.accent, 0.5));

      for (let index = 0; index < 5; index += 1) {
        const offset = (frame.time * 2.2 + index * 4.7) % 22;

        context.fillRect(toX(span - offset), toY(2.4 + hash(index * 13) * 2.6), 0.4 * unit, Math.max(1, 0.05 * unit));
      }
    }
  };

  /**
   * The ground: a lit top sliver, a bright leading edge, and the face below.
   *
   * Light is not dark inverted. Dark gets the page's deep canvas pushed
   * towards the accent, so the lane glows; light gets a genuine mineral grey,
   * several steps down from the sky, because a near-white lane under a
   * near-white sky is a lane whose edge nobody can find.
   */
  const drawGround = (world: World): void => {
    const surface = palette.dark
      ? mix(palette.canvas, palette.accentSoft, 0.8)
      : mix(palette.canvas, palette.ink, 0.26);
    const face = palette.dark
      ? mix(palette.canvas, palette.accentSoft, 0.42)
      : mix(surface, palette.ink, 0.16);

    const lip = ground + unit * 0.55;

    context.fillStyle = css(surface);
    context.fillRect(0, ground, width, lip - ground);
    context.fillStyle = css(face);
    context.fillRect(0, lip, width, height - lip);

    /* The line that says "this is the floor" — the most important mark in the
       picture, because everything else is read relative to it. */
    context.fillStyle = css(mix(surface, palette.accent, palette.dark ? 0.5 : 0.3));
    context.fillRect(0, ground - Math.max(1, unit * 0.03), width, Math.max(2, unit * 0.055));

    const dash = mix(surface, palette.accent, palette.dark ? 0.3 : 0.26);
    const striation = palette.dark ? mix(face, palette.accent, 0.16) : mix(face, palette.ink, 0.12);
    const first = Math.ceil((world.distance - 6) / 2.6) * 2.6;

    for (let x = first; x < world.distance + span + 4; x += 2.6) {
      const local = x - world.distance;

      context.fillStyle = css(dash);
      context.fillRect(toX(local), ground + unit * 0.22, unit * 1.2, Math.max(2, unit * 0.07));
      /* The striations carry the speed in the bottom band, where nothing the
         player has to read can ever be covered by them. */
      context.fillStyle = css(striation);
      context.fillRect(toX(local + 1.3), lip, Math.max(2, unit * 0.18), height - lip);
    }
  };

  return {
    kind: 'canvas2d',
    resize,

    retheme(): void {
      palette = readPalette(options.palette);
    },

    draw(world: World, frame: Frame): void {
      drawScenery(world, frame);
      drawGround(world);

      for (const obstacle of world.obstacles) {
        const local = obstacle.x - world.distance;

        if (local < -4 || local > span + 6) {
          continue;
        }

        drawObstacle(world, obstacle, frame);
      }

      for (const coin of world.pickups) {
        if (coin.collected) {
          continue;
        }

        const local = coin.x - world.distance;

        if (local < -3 || local > span + 6) {
          continue;
        }

        /* Distance drives the spin, so a paused game shows a still coin
           rather than one turning on its own. */
        const phase = options.reducedMotion ? 0.94 : Math.cos(world.distance * 1.15 + coin.x * 0.8);

        shadow(local, coin.y, 0.5);
        drawCoin(local, coin.y, phase);
      }

      drawRunner(world);
    },

    destroy(): void {
      context.clearRect(0, 0, width, height);
    },
  };
}

export { mountCanvasRenderer };
