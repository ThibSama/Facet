/**
 * Satoshi Run — the deferred entry point.
 *
 * This is the only module the skin names, and it is named exactly once: inside
 * a dynamic import that runs when somebody presses Play. A visitor who never
 * presses it never fetches this chunk, never allocates a context and never
 * pays a frame — which is the whole reason the launcher is a button and not an
 * observer.
 *
 * What lives here is presentation and lifecycle, and nothing else. The rules
 * are in `world.ts`, the drawing is behind `renderer.ts`, and the reading of a
 * player's fingers is in `controls.ts`. This file builds the overlay, runs a
 * fixed-timestep loop over the simulation, tells the HUD what happened, and —
 * the part that matters most — gives every one of those things back on
 * `destroy()`: the loop, the listeners, the observers, the GPU objects, the
 * scroll lock and the focus.
 */
import { createControls } from './controls';
import { mountCanvasRenderer } from './canvas-renderer';
import { mountWebglRenderer } from './webgl-renderer';
import { readBestScore, recordScore } from './storage';
import type { RunRenderer } from './renderer';
import { IDLE, TICK, createWorld, scoreOf, simulate, stepWorld } from './world';
import type { Intent, World } from './world';
import './run.css';

interface RunHandle {
  destroy(): void;
}

interface RunOptions {
  /**
   * Where the overlay is attached. The game is a layer over the document, not
   * a part of the page's content, so it never enters `main`.
   */
  readonly container?: HTMLElement;
  /** Focus goes back here when the game closes. Usually the launcher. */
  readonly returnFocus?: HTMLElement | null;
  /**
   * Told once, when this run has finished giving everything back.
   *
   * Whoever mounted the game holds a handle to it, and that handle is how they
   * refuse to mount a second one. A run that ends on its own — the Close
   * button, Escape — would otherwise leave that handle pointing at a run which
   * no longer exists, and the owner would go on refusing to start another for
   * the life of the document. Closing is therefore not something done *to* the
   * run from outside; it is something the run reports, however it was reached.
   */
  readonly onClose?: () => void;
  /**
   * Decided by the skin, which owns the one reduced-motion query list in the
   * document. Passing it keeps that a single fact rather than two answers that
   * happen to agree.
   */
  readonly reducedMotion: boolean;
  /**
   * The run's own chrome, in the language of the page that launched it.
   *
   * Presentation only, and deliberately the whole of what a language can reach
   * in here: nothing in the world, the physics, the obstacles or the scoring
   * knows a locale, and this is the one boundary they are named at. Omitted,
   * the run speaks the defaults below — a game launched from a page that
   * carries no labels is playable rather than blank.
   */
  readonly labels?: Partial<RunLabels>;
}

/** The strings the run puts on screen. */
interface RunLabels {
  jump: string;
  duck: string;
  restart: string;
  close: string;
  score: string;
  best: string;
  ready: string;
  over: string;
  record: string;
}

/**
 * The longest gap the loop will simulate in one frame.
 *
 * A backgrounded tab, a blocked main thread or a machine coming out of sleep
 * all arrive as one enormous delta. Simulating it honestly would run thousands
 * of ticks in a frame — a hang, and a run that ended against an obstacle
 * nobody was shown. The excess is dropped instead: the game loses time, which
 * is the only thing it can afford to lose.
 */
const MAX_FRAME = 0.25;

/** The run's proper name. The same string in every language, so never a label. */
const TITLE = 'Satoshi Run';

const DEFAULT_LABELS: RunLabels = {
  jump: 'Jump',
  duck: 'Duck',
  restart: 'Restart',
  close: 'Close',
  score: 'Score',
  best: 'Best',
  ready: 'Space to jump · ▼ to duck · collect ₿',
  over: 'Caught. Press Restart, or R, to run again.',
  record: 'New best score.',
};

function element<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  className: string,
  attributes: Record<string, string> = {},
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  node.className = className;

  for (const [name, value] of Object.entries(attributes)) {
    node.setAttribute(name, value);
  }

  return node;
}

/**
 * Asks for the accelerated renderer and accepts the answer.
 *
 * There is no preference stored, no query string and no console warning: a
 * browser without WebGL2 gets the 2D lane and gets it silently, because the
 * fallback is a supported way to play rather than a degraded state to report.
 */
function createRenderer(
  canvas: HTMLCanvasElement,
  palette: Element,
  reducedMotion: boolean,
  onContextLost: () => void,
): RunRenderer | null {
  const options = { palette, reducedMotion, onContextLost };

  return mountWebglRenderer(canvas, options) ?? mountCanvasRenderer(canvas, options);
}

function mountSatoshiRun(options: RunOptions): RunHandle | null {
  const container = options.container ?? document.body;
  const labels: RunLabels = { ...DEFAULT_LABELS, ...options.labels };

  const overlay = element('div', 'facet-run', {
    role: 'dialog',
    'aria-modal': 'true',
    'aria-label': TITLE,
    tabindex: '-1',
    'data-facet-run': '',
    'data-facet-run-state': 'running',
  });

  const stage = element('div', 'facet-run__stage', { 'data-facet-run-stage': '' });

  /*
   * Not `const`: a canvas that has handed out a WebGL2 context can never hand
   * out a 2D one, so falling back to the other lane mid-run means replacing
   * the element rather than re-asking this one.
   */
  let canvas = element('canvas', 'facet-run__canvas', { 'aria-hidden': 'true' });
  stage.appendChild(canvas);

  /*
   * The interface layers over the playfield rather than sitting beside it.
   *
   * A panel of readouts under the lane made the game look like an instrument
   * that happened to have a runner in it. Score, coins and best still have to
   * be legible — they are what the run is *for* — but they are subordinate to
   * the lane, so they are laid over its corners and the lane gets the whole
   * stage.
   *
   * The layer is a *sibling* of the stage, not a child of it, and that is
   * load-bearing. The stage is itself a control — a thumb held on it jumps or
   * ducks — so a button nested inside it would deliver every press twice, once
   * to the button and once to the lane underneath. Overlaying instead of
   * nesting keeps the two apart without a single event having to be stopped.
   * The layer is transparent to the pointer; only the controls in it are not.
   */
  const ui = element('div', 'facet-run__ui');

  const hud = element('div', 'facet-run__hud');
  const scoreOut = element('output', 'facet-run__value', { 'data-facet-run-score': '' });
  const coinsOut = element('output', 'facet-run__value facet-run__value--coin', { 'data-facet-run-coins': '' });
  const bestOut = element('output', 'facet-run__value', { 'data-facet-run-best': '' });

  for (const [label, output] of [[labels.score, scoreOut], ['₿', coinsOut], [labels.best, bestOut]] as Array<[string, HTMLOutputElement]>) {
    const group = element('p', 'facet-run__stat');
    const name = element('span', 'facet-run__label');
    name.textContent = label;
    output.textContent = '0';
    group.append(name, output);
    hud.appendChild(group);
  }

  const status = element('p', 'facet-run__status', { role: 'status', 'aria-live': 'polite', 'data-facet-run-status': '' });
  status.textContent = labels.ready;

  const actions = element('div', 'facet-run__actions');
  const restartButton = element('button', 'facet-run__button', { type: 'button', 'data-facet-run-restart': '' });
  restartButton.textContent = labels.restart;
  const closeButton = element('button', 'facet-run__button', { type: 'button', 'data-facet-run-close': '' });
  closeButton.textContent = labels.close;
  actions.append(restartButton, closeButton);

  const controlBar = element('div', 'facet-run__controls');
  const jumpButton = element('button', 'facet-run__pad', { type: 'button', 'data-facet-run-jump': '' });
  jumpButton.textContent = labels.jump;
  const duckButton = element('button', 'facet-run__pad facet-run__pad--duck', { type: 'button', 'data-facet-run-duck': '' });
  duckButton.textContent = labels.duck;

  controlBar.append(jumpButton, duckButton);
  ui.append(hud, actions, status, controlBar);
  overlay.append(stage, ui);

  /*
   * The overlay enters the document before the renderer is asked for.
   *
   * Both renderers read their palette from the overlay's *computed* style, and
   * a detached element has none: every custom property would come back empty
   * and the lane would be painted in the fallback colours whatever theme the
   * page is in. Inserting first is what makes the game the same colour as the
   * document it is played on top of.
   */
  document.documentElement.classList.add('facet-run-open');
  container.appendChild(overlay);

  const created = createRenderer(canvas, overlay, options.reducedMotion, () => degrade());

  if (created === null) {
    overlay.remove();
    document.documentElement.classList.remove('facet-run-open');

    return null;
  }

  /* Re-bound so its type is non-null everywhere below, including inside the
     hoisted `destroy` a listener registered before it was declared. Not
     `const`, because `degrade` swaps it for the fallback lane mid-run. */
  let renderer: RunRenderer = created;

  overlay.dataset.facetRunRenderer = renderer.kind;

  let world: World = createWorld({ seed: (Date.now() ^ 0x9e3779b9) >>> 0 });
  let best = readBestScore();
  bestOut.textContent = String(best);

  let running = true;
  let frame = 0;
  let previous = 0;
  let accumulator = 0;
  let presented = 0;
  let destroyed = false;

  /*
   * What the runner is doing, and how often it has been asked to do it.
   *
   * The overlay carries all three as data attributes, written only when they
   * change rather than every frame. They exist because "the key did something"
   * is otherwise unobservable from outside: a jump is over in seven hundred
   * milliseconds, and a test that has to catch it mid-air is a test that fails
   * on a slow machine. A counter is permanent, so asserting it is not a race.
   */
  let jumps = 0;
  let ducks = 0;
  let pose = '';
  let grounded = true;

  const controls = createControls({
    /*
     * Keys are read at the document, not at the overlay.
     *
     * The overlay is a modal dialog covering the page, so while it is up every
     * key in the document belongs to the game — and a player whose focus has
     * wandered to some other control must not silently lose the ability to
     * jump. Keydown bubbles here from wherever it landed, and the listener is
     * removed on teardown, so nothing is swallowed once the game is gone.
     */
    surface: document.documentElement,
    stage,
    jump: jumpButton,
    duck: duckButton,
    onCommand: (command): void => {
      if (command === 'close') {
        destroy();

        return;
      }

      restart();
    },
  });

  const paint = (): void => {
    scoreOut.textContent = String(world.score);
    coinsOut.textContent = String(world.coins);
    bestOut.textContent = String(best);
  };

  const readPose = (): string => {
    if (world.ducking) {
      return 'duck';
    }

    return world.grounded ? 'run' : 'air';
  };

  const reportPose = (): void => {
    /*
     * A jump is leaving the ground, and nothing else. Counting entries into
     * the "air" pose instead would double-count a player who ducks in mid-air
     * and stands up again — one press, two transitions — which is a thing the
     * game explicitly allows.
     */
    if (grounded && !world.grounded) {
      jumps += 1;
      overlay.dataset.facetRunJumps = String(jumps);
    }

    grounded = world.grounded;

    const next = readPose();

    if (next === pose) {
      return;
    }

    if (next === 'duck') {
      ducks += 1;
      overlay.dataset.facetRunDucks = String(ducks);
    }

    pose = next;
    overlay.dataset.facetRunPose = next;
  };

  const finish = (): void => {
    overlay.dataset.facetRunState = 'over';

    const previousBest = best;
    best = recordScore(world.score);
    status.textContent = best > previousBest ? `${labels.over} ${labels.record}` : labels.over;
    paint();
  };

  function restart(): void {
    /*
     * A new seed per run, so the lane is not memorised — and a cleared input
     * state, so a run never begins with a jump the player was still holding
     * from the moment they pressed Restart.
     */
    world = createWorld({ seed: (Date.now() ^ (world.seed * 1103515245)) >>> 0 });
    controls.clear();
    accumulator = 0;
    pose = '';
    grounded = true;
    reportPose();
    overlay.dataset.facetRunState = 'running';
    status.textContent = labels.ready;
    paint();
  }

  const step = (): void => {
    frame = requestAnimationFrame(step);

    /*
     * Time comes from the clock, not from the frame's own timestamp.
     *
     * The two agree on a page that is painting steadily and disagree exactly
     * when it is not: a browser that has been withholding frames may deliver
     * several callbacks back to back carrying the timeline it *would* have
     * had, and a loop that believed them would simulate seconds of lane in one
     * burst — a run that ended against an obstacle nobody was ever shown.
     * Consecutive callbacks in such a burst are microseconds apart by the
     * clock, so measuring it this way costs the game the frames it genuinely
     * missed and nothing else.
     */
    const now = performance.now();
    const delta = previous === 0 ? 0 : Math.min((now - previous) / 1000, MAX_FRAME);
    previous = now;
    presented += delta;

    const wasOver = world.status === 'over';
    accumulator += delta;

    /*
     * Intent is sampled only when there is a tick to spend it on.
     *
     * Reading it on a frame that simulates nothing would consume the latched
     * tap in `controls.ts` and throw it away — and a frame that simulates
     * nothing is exactly what a browser produces while it is warming up, which
     * is exactly when a player's first press lands.
     */
    if (accumulator >= TICK) {
      const intent: Intent = controls.intent();

      while (accumulator >= TICK) {
        accumulator -= TICK;
        stepWorld(world, intent);
      }
    }

    reportPose();

    if (world.status === 'over' && !wasOver) {
      finish();
    } else if (world.status === 'running') {
      paint();
    }

    renderer.draw(world, { time: presented, delta });
  };

  const start = (): void => {
    if (!running || frame !== 0) {
      return;
    }

    previous = 0;
    frame = requestAnimationFrame(step);
  };

  const stop = (): void => {
    if (frame === 0) {
      return;
    }

    cancelAnimationFrame(frame);
    frame = 0;
  };

  /* A hidden tab is not a paused game anywhere else, and it is not one here. */
  const onVisibility = (): void => {
    if (document.visibilityState === 'hidden') {
      stop();
      controls.clear();

      return;
    }

    start();
  };

  const ratio = Math.min(window.devicePixelRatio || 1, 2);
  let width = 0;
  let height = 0;

  const resize = (): void => {
    /*
     * Measured on the canvas, not on the stage.
     *
     * They are the same box on a wide screen and deliberately different on a
     * tall one, where the stylesheet letterboxes the lane inside the stage so
     * the runner is not shrunk to honour a phone's shape. The renderer has to
     * be told the size of the surface it is drawing on; the stage is the size
     * of the surface a thumb can touch, which is a different question.
     */
    const box = canvas.getBoundingClientRect();
    const nextWidth = Math.max(1, Math.round(box.width * ratio));
    const nextHeight = Math.max(1, Math.round(box.height * ratio));

    if (nextWidth === width && nextHeight === height) {
      return;
    }

    width = nextWidth;
    height = nextHeight;
    renderer.resize(width, height);
  };

  /**
   * Moves a live run onto the fallback lane after the accelerated one died.
   *
   * The renderer boundary reports the loss and stops there; this is the part
   * that costs a canvas, and only the file that owns the canvas can pay it. A
   * surface that has served a WebGL2 context will never serve a 2D one, so the
   * element is replaced rather than reused — which is also why this cannot
   * live inside a renderer: no renderer owns the element it draws on.
   *
   * Nothing about the run itself is touched. `world` is not read here, let
   * alone rebuilt: the score, the distance, the coins, the obstacles and the
   * player's held keys are all exactly where they were, because losing a
   * picture of a game is not losing the game. The player sees the lane change
   * its drawing and keeps running.
   *
   * If even the 2D context is refused — a document being torn down under us is
   * the realistic way that happens — there is no playable state left to offer,
   * and the honest answer is to close rather than to leave an overlay that
   * cannot draw.
   */
  const degrade = (): void => {
    if (destroyed || renderer.kind !== 'webgl2') {
      return;
    }

    const replacement = element('canvas', 'facet-run__canvas', { 'aria-hidden': 'true' });
    const fallback = mountCanvasRenderer(replacement, {
      palette: overlay,
      reducedMotion: options.reducedMotion,
    });

    if (fallback === null) {
      destroy();

      return;
    }

    renderer.destroy();
    canvas.replaceWith(replacement);
    canvas = replacement;
    renderer = fallback;

    overlay.dataset.facetRunRenderer = fallback.kind;
    /* Why this run is on the 2D lane when the browser can do better. It is the
       only trace the fallback leaves, and the only one a test can hold on to. */
    overlay.dataset.facetRunDegraded = 'context-lost';

    /* The new surface has no size yet, and `resize` short-circuits on a size
       it believes it has already applied. Forgetting is what re-applies it. */
    width = 0;
    height = 0;
    resize();
  };

  const box = new ResizeObserver(() => resize());
  const theme = new MutationObserver(() => renderer.retheme());
  const system = typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const onSystemChange = (): void => renderer.retheme();

  /**
   * Focus stays in the overlay while it is open.
   *
   * It is a modal dialog, so this is the contract rather than a nicety: Tab
   * cycles the game's own controls and Escape leaves. Nothing behind the
   * overlay is reachable while it is up, and everything behind it is reachable
   * again the moment it comes down.
   */
  const focusable = (): HTMLElement[] => [jumpButton, duckButton, restartButton, closeButton];

  const onTab = (event: KeyboardEvent): void => {
    if (event.key !== 'Tab') {
      return;
    }

    const order = focusable();
    const active = document.activeElement;
    const index = order.indexOf(active as HTMLElement);

    event.preventDefault();

    const next = event.shiftKey
      ? order[(index <= 0 ? order.length : index) - 1]
      : order[(index + 1) % order.length];

    next.focus();
  };

  const onRestartClick = (): void => restart();
  const onCloseClick = (): void => destroy();

  function destroy(): void {
    if (destroyed) {
      return;
    }

    destroyed = true;
    running = false;
    stop();

    controls.destroy();
    box.disconnect();
    theme.disconnect();
    system?.removeEventListener('change', onSystemChange);
    document.removeEventListener('visibilitychange', onVisibility);
    overlay.removeEventListener('keydown', onTab);
    restartButton.removeEventListener('click', onRestartClick);
    closeButton.removeEventListener('click', onCloseClick);

    renderer.destroy();
    overlay.remove();
    document.documentElement.classList.remove('facet-run-open');

    options.returnFocus?.focus();

    /*
     * Last, and after the document is already whole again: the owner is told
     * the run is over so it can forget its handle and let the launcher work.
     * Telling it earlier would invite it to mount a second run into a document
     * this one has not finished vacating.
     */
    options.onClose?.();
  }

  restartButton.addEventListener('click', onRestartClick);
  closeButton.addEventListener('click', onCloseClick);
  overlay.addEventListener('keydown', onTab);
  document.addEventListener('visibilitychange', onVisibility);
  theme.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  system?.addEventListener('change', onSystemChange);

  box.observe(stage);

  resize();
  paint();
  reportPose();
  overlay.focus();
  start();

  return { destroy };
}

/**
 * The observation seam the browser suite uses.
 *
 * It publishes the *rules* — the pure, deterministic half — and nothing about
 * the running game, because the running game is already fully described by the
 * DOM the overlay renders. A test can therefore assert what a jump costs and
 * where an obstacle lands without a frame being drawn, which is exactly the
 * separation `world.ts` exists to make possible.
 */
declare global {
  interface Window {
    __facetSatoshiRun?: {
      TICK: number;
      createWorld: typeof createWorld;
      scoreOf: typeof scoreOf;
      simulate: typeof simulate;
      stepWorld: typeof stepWorld;
      IDLE: Intent;
    };
  }
}

window.__facetSatoshiRun = { TICK, createWorld, scoreOf, simulate, stepWorld, IDLE };

export { DEFAULT_LABELS, TITLE, mountSatoshiRun };
export type { RunLabels };
export type { RunHandle, RunOptions };
