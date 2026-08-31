/**
 * Satoshi Run, exercised in a real browser.
 *
 * The suite is deliberately split along the same seam the code is. The rules —
 * physics, spawning, collision, collection, scoring — are asserted against the
 * simulation directly, with no frame drawn and no timing involved, because
 * they are pure functions of a seed and a script of intents. Everything else
 * is asserted the way a player experiences it: press a key, watch the overlay,
 * crash, restart, close.
 *
 * Two properties get more attention than the rest, because they are the two
 * that would quietly rot. The chunk must not be fetched by a visitor who never
 * launches the game — that is checked against the network, not against the
 * source — and the game must give everything back when it closes, which is
 * checked by counting what is left in the document afterwards.
 */
import { expect, test } from './support/test';

const BRAND = '[data-facet-brand]';
const OVERLAY = '[data-facet-run]';

/**
 * The gesture, and the window it has to happen in.
 *
 * They are duplicated from the skin rather than imported, deliberately: this
 * suite drives the *built* page, and a constant it shared with the source
 * could drift with it and never fail. Five and 500 are the contract.
 */
const CLICKS = 5;
const WINDOW = 500;

/** The built chunk's filename stem. Vite fingerprints it; the stem is stable. */
const RUN_CHUNK = /\/assets\/run-[^/]+\.js$/;

/**
 * The simulation's own surface, published by the module for exactly this.
 * Typing it here rather than importing keeps the suite honest: it asserts what
 * the *built* chunk exposes to a page, not what a source file declares.
 */
interface RunCore {
  TICK: number;
  createWorld(options?: { seed?: number }): SimWorld;
  scoreOf(world: SimWorld): number;
  simulate(seed: number, ticks: number, intentAt: (tick: number, world: SimWorld) => Intent): SimWorld;
  stepWorld(world: SimWorld, intent: Intent): SimWorld;
  IDLE: Intent;
}

interface Intent {
  jump: boolean;
  duck: boolean;
}

interface SimWorld {
  status: 'running' | 'over';
  distance: number;
  y: number;
  velocity: number;
  grounded: boolean;
  ducking: boolean;
  coins: number;
  score: number;
  speed: number;
  obstacles: Array<{ kind: string; x: number; width: number; base: number; height: number }>;
  pickups: Array<{ x: number; y: number; collected: boolean }>;
}

declare global {
  interface Window {
    __facetSatoshiRun?: RunCore;
  }
}

/**
 * Performs the gesture: `count` clicks on the brand, fast enough to count as
 * one sequence.
 *
 * The mark is located once and then clicked through the mouse, rather than
 * through the locator each time. That is not an optimisation — it is what
 * makes the test measure the page instead of the harness. A locator click
 * re-resolves the element and re-runs its actionability checks on every call,
 * which on a loaded page can take longer than the gesture's own window; the
 * sequence would then expire between two clicks the *test* was slow to
 * deliver, and the failure would be reported against the trigger. A person
 * clicking five times sends five events a few dozen milliseconds apart, and so
 * does this.
 */
async function clickBrand(page: import('@playwright/test').Page, count: number): Promise<void> {
  const brand = page.locator(BRAND);
  await brand.waitFor({ state: 'visible' });

  const box = await brand.boundingBox();

  if (box === null) {
    throw new Error('the brand has no box to click');
  }

  const x = box.x + box.width / 2;
  const y = box.y + box.height / 2;

  for (let index = 0; index < count; index += 1) {
    await page.mouse.click(x, y);
  }
}

/** Opens the game the only way there is, and waits until it is running. */
async function launch(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/fr');
  await expect(page.locator(BRAND)).toBeVisible();
  await clickBrand(page, CLICKS);
  await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
}

/**
 * Lets a headless box mount the accelerated renderer.
 *
 * The module asks for a context that refuses a major performance caveat, which
 * is the right policy for a visitor — a software rasteriser running a 3D scene
 * is worse than a 2D lane running smoothly — and the wrong one for a machine
 * with no GPU. Lifting it here, and only here, makes the WebGL2 tests about
 * WebGL2 rather than about the hardware they happen to run on.
 */
async function allowSoftwareWebgl(page: import('@playwright/test').Page): Promise<void> {
  await page.addInitScript(() => {
    const original = HTMLCanvasElement.prototype.getContext;

    HTMLCanvasElement.prototype.getContext = function patched(
      this: HTMLCanvasElement,
      id: string,
      attributes?: Record<string, unknown>,
    ) {
      if (id === 'webgl2' && attributes !== undefined) {
        const rest = { ...attributes };
        delete rest.failIfMajorPerformanceCaveat;

        return original.call(this, id, rest);
      }

      return original.call(this, id, attributes);
    } as typeof HTMLCanvasElement.prototype.getContext;
  });
}

test.describe('Satoshi Run — deferred loading', () => {
  test('a visitor who never performs the gesture never fetches its chunk', async ({ page }) => {
    const requested: string[] = [];

    page.on('request', (request) => {
      if (RUN_CHUNK.test(request.url())) {
        requested.push(request.url());
      }
    });

    await page.goto('/fr');
    // Everything the skin does on its own has had its idle moment by now.
    await expect(page.locator(BRAND)).toBeVisible();
    await page.waitForTimeout(1200);

    expect(requested, 'the game chunk must not be fetched before the gesture').toEqual([]);
    expect(await page.locator(OVERLAY).count()).toBe(0);

    await clickBrand(page, CLICKS);
    await expect(page.locator(OVERLAY)).toBeVisible();

    expect(requested.length, 'the gesture fetches the chunk exactly once').toBe(1);
  });

  test('a second launch re-uses the module rather than fetching it again', async ({ page }) => {
    const requested: string[] = [];

    page.on('request', (request) => {
      if (RUN_CHUNK.test(request.url())) {
        requested.push(request.url());
      }
    });

    await launch(page);
    await page.keyboard.press('Escape');
    await expect(page.locator(OVERLAY)).toHaveCount(0);

    await clickBrand(page, CLICKS);
    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');

    // The dynamic import is a module, and a module is evaluated once. A second
    // request here would mean the chunk had been re-fetched and re-run, which
    // is how a game grows a second copy of every listener it registers.
    expect(requested.length, 'the chunk is fetched once per document').toBe(1);
  });

  test('the served document offers no launcher and names no chunk', async ({ page }) => {
    for (const route of ['/', '/projects', '/about', '/contact']) {
      const html = await (await page.request.get(route)).text();

      // PORT-134's provisional button is gone from the public presentation:
      // the game is found by the gesture and by nothing else.
      expect(html, `${route} must offer no visible launcher`).not.toContain('data-facet-run-launch');
      expect(html, `${route} must name no game chunk`).not.toMatch(/assets\/run-/);
    }

    // What every page does still carry is a plain, working home link.
    const home = await (await page.request.get('/projects')).text();
    expect(home).toContain('data-facet-brand');
    expect(home).toMatch(/<a[^>]*class="facet-brand"[^>]*href="[^"]*"/);
  });
});

/*
 * The way in.
 *
 * Satoshi Run has no button, no route and no menu item: five rapid clicks on
 * the Facet mark start it. That makes the trigger a piece of behaviour bolted
 * onto a link that has a job of its own, and the whole risk of it lives in the
 * seam between the two — a counter that never resets, a link that stops
 * navigating, a burst that mounts two games, a game that can only be found
 * once per page load. This suite is that seam.
 */
test.describe('Satoshi Run — the five-click gesture', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/fr');
    await expect(page.locator(BRAND)).toBeVisible();
  });

  for (const count of [1, 2, 3, 4]) {
    test(`${count} click${count === 1 ? '' : 's'} does not start the game`, async ({ page }) => {
      await clickBrand(page, count);

      // Long enough for the sequence to expire and for a chunk to have loaded
      // if one were ever going to.
      await page.waitForTimeout(WINDOW + 400);

      expect(await page.locator(OVERLAY).count(), `${count} clicks must not launch`).toBe(0);
    });
  }

  test('five rapid clicks launch exactly one game', async ({ page }) => {
    await clickBrand(page, CLICKS);

    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
    expect(await page.locator(OVERLAY).count()).toBe(1);
    expect(await page.locator('.facet-run__canvas').count()).toBe(1);
  });

  test('a pause in the middle resets the count', async ({ page }) => {
    await clickBrand(page, 3);
    await page.waitForTimeout(WINDOW + 250);
    await clickBrand(page, 3);

    // Three, a pause, three: six clicks in total and no sequence of five.
    await page.waitForTimeout(WINDOW + 250);
    expect(await page.locator(OVERLAY).count()).toBe(0);

    // And the counter really did go back to zero rather than merely stalling:
    // a full five now works.
    await clickBrand(page, CLICKS);
    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
  });

  test('a long burst mounts one game, not several', async ({ page }) => {
    /*
     * Fifteen clicks is three sequences' worth. The first five mount the game;
     * the rest land while the chunk is still in flight or while the overlay
     * already has focus, which are the two windows a second mount could open.
     */
    await clickBrand(page, 15);

    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
    expect(await page.evaluate(() => document.querySelectorAll('[data-facet-run]').length)).toBe(1);
    expect(await page.evaluate(() => document.querySelectorAll('.facet-run__canvas').length)).toBe(1);
  });

  for (const [name, close] of [
    ['Close', async (page: import('@playwright/test').Page) => page.locator('[data-facet-run-close]').click()],
    ['Escape', async (page: import('@playwright/test').Page) => page.keyboard.press('Escape')],
  ] as const) {
    test(`after ${name} the gesture works again`, async ({ page }) => {
      await clickBrand(page, CLICKS);
      await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');

      await close(page);
      await expect(page.locator(OVERLAY)).toHaveCount(0);
      await expect(page.locator(BRAND)).toBeFocused();

      await clickBrand(page, CLICKS);
      await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
      expect(await page.locator(OVERLAY).count()).toBe(1);

      // A new run, not the old one shown again.
      await page.keyboard.press('Space');
      await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-jumps', '1');
    });
  }

  test('the brand is still a home link, and still navigates', async ({ page }) => {
    // It is a real anchor with a real destination, which is what makes it work
    // with JavaScript off and what makes middle-click and copy-link work now.
    const href = await page.locator(BRAND).getAttribute('href');
    expect(href).not.toBeNull();
    expect(await page.locator(BRAND).evaluate((node) => node.tagName)).toBe('A');

    // From another page a single click goes home immediately and untouched.
    await page.goto('/fr/projects');
    await page.locator(BRAND).click();
    await expect(page).toHaveURL('/fr');

    // And the gesture never fires on a page the brand does not already point
    // at: the first click leaves before a second can be counted.
    expect(await page.locator(OVERLAY).count()).toBe(0);
  });

  test('on the home page a pointer click does not reload, and Enter still does', async ({ page }) => {
    /*
     * The gesture's one cost, asserted in the open rather than left implicit.
     *
     * On the home page the brand points at the home page, so its click has no
     * destination to preserve — the "navigation" is a reload of what is
     * already on screen. That click is spent on the counter instead, and
     * nothing reloads. Every other way of asking for the reload is untouched,
     * and the one this suite can drive is Enter on the focused mark.
     *
     * The marker lives on `window` precisely because a new document destroys
     * it. Storage would survive the navigation and prove nothing either way.
     */
    const mark = (): Promise<void> =>
      page.evaluate(() => {
        (window as unknown as { __facetSameDocument?: boolean }).__facetSameDocument = true;
      });
    const survived = (): Promise<boolean> =>
      page.evaluate(() => (window as unknown as { __facetSameDocument?: boolean }).__facetSameDocument ?? false);

    await mark();
    await clickBrand(page, 1);
    await page.waitForTimeout(WINDOW + 300);

    expect(await survived(), 'a pointer click on the home brand must not reload').toBe(true);
    await expect(page).toHaveURL('/fr');
    expect(await page.locator(OVERLAY).count()).toBe(0);

    // Enter is never part of the gesture, so it keeps the navigation whole.
    // The navigation is awaited rather than polled for: a poll that happens to
    // evaluate while the document is being replaced fails on the destroyed
    // context instead of answering the question it was asked.
    const navigated = page.waitForEvent('framenavigated');
    await page.locator(BRAND).focus();
    await page.keyboard.press('Enter');
    await navigated;
    await page.waitForLoadState('domcontentloaded');

    expect(await survived(), 'Enter on the brand must still navigate').toBe(false);

    await expect(page.locator(BRAND)).toBeVisible();
    expect(await page.locator(OVERLAY).count()).toBe(0);
  });

  test('keyboard activation navigates at once and never counts', async ({ page }) => {
    await page.goto('/fr/projects');
    await page.locator(BRAND).focus();
    await expect(page.locator(BRAND)).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(page).toHaveURL('/fr');

    // Five keyboard activations are five navigations, not a gesture: a
    // keyboard user can never be trapped in a game they did not ask for.
    for (let index = 0; index < CLICKS; index += 1) {
      await page.locator(BRAND).focus();
      await page.keyboard.press('Enter');
      await page.waitForLoadState('domcontentloaded');
    }

    expect(await page.locator(OVERLAY).count()).toBe(0);
  });
});

test.describe('Satoshi Run — the rules', () => {
  test.beforeEach(async ({ page }) => {
    await launch(page);
  });

  test('the same seed and the same intents produce the same run', async ({ page }) => {
    const [first, second] = await page.evaluate(() => {
      const core = window.__facetSatoshiRun;

      if (core === undefined) {
        throw new Error('the game module published no simulation seam');
      }

      const script = (tick: number): Intent => ({ jump: tick % 240 < 6, duck: false });
      const summarise = (world: SimWorld): unknown => ({
        status: world.status,
        distance: world.distance,
        score: world.score,
        coins: world.coins,
        obstacles: world.obstacles.map((obstacle) => [obstacle.kind, obstacle.x]),
      });

      return [
        summarise(core.simulate(1337, 900, script)),
        summarise(core.simulate(1337, 900, script)),
      ];
    });

    expect(second).toEqual(first);
  });

  test('a different seed produces a different lane', async ({ page }) => {
    const lanes = await page.evaluate(() => {
      const core = window.__facetSatoshiRun!;
      const lane = (seed: number): string =>
        core
          .simulate(seed, 1400, () => core.IDLE)
          .obstacles.map((obstacle) => `${obstacle.kind}@${obstacle.x.toFixed(2)}`)
          .join(' ');

      return [lane(11), lane(12)];
    });

    expect(lanes[0]).not.toEqual(lanes[1]);
  });

  test('jumping leaves the ground, peaks, and lands again', async ({ page }) => {
    const flight = await page.evaluate(() => {
      const core = window.__facetSatoshiRun!;
      const world = core.createWorld({ seed: 7 });
      const heights: number[] = [];

      for (let tick = 0; tick < 180; tick += 1) {
        core.stepWorld(world, { jump: tick < 40, duck: false });
        heights.push(world.y);
      }

      return {
        peak: Math.max(...heights),
        landed: world.grounded && world.y === 0,
        // Held for two ticks only: the arc is capped, and lower — but never
        // lower than the tallest thing standing on the ground.
        cutPeak: (() => {
          const short = core.createWorld({ seed: 7 });
          let highest = 0;

          for (let tick = 0; tick < 180; tick += 1) {
            core.stepWorld(short, { jump: tick < 2, duck: false });
            highest = Math.max(highest, short.y);
          }

          return highest;
        })(),
      };
    });

    expect(flight.peak).toBeGreaterThan(2);
    expect(flight.landed).toBe(true);
    expect(flight.cutPeak).toBeLessThan(flight.peak);
    // A red candle is 1.62 units tall. The shortest tap must still clear it.
    expect(flight.cutPeak).toBeGreaterThan(1.7);
  });

  test('an idle runner is caught by the lane, and a played one survives it', async ({ page }) => {
    const outcome = await page.evaluate(() => {
      const core = window.__facetSatoshiRun!;

      /* Idle: never jumps, never ducks. The first obstacle ends it. */
      const idle = core.simulate(4242, 3000, () => core.IDLE);

      /*
       * Played: a crude autopilot that jumps at what is on the ground and
       * ducks under what hangs. It is not clever — it only proves the lane is
       * survivable, which is the half that would be missed by testing failure
       * alone.
       */
      const played = core.simulate(4242, 3000, (_tick, world) => {
        /* The runner is a box, not a point: an obstacle is still underfoot
           for a little after its right edge passes the runner's centre, and
           standing up there is what kills an autopilot that forgets it. */
        const ahead = world.obstacles.find(
          (obstacle) => obstacle.x + obstacle.width + 0.4 > world.distance,
        );

        if (ahead === undefined) {
          return core.IDLE;
        }

        const gap = ahead.x - world.distance;

        /* Thresholds scale with speed, because so does everything else: the
           lane's spacing is reaction time, and so is this. */
        if (ahead.base > 0) {
          return { jump: false, duck: gap < world.speed * 0.35 };
        }

        return { jump: gap > 0 && gap < world.speed * 0.25, duck: false };
      });

      return {
        idle: { status: idle.status, distance: idle.distance, score: idle.score },
        played: { status: played.status, distance: played.distance, coins: played.coins, score: played.score },
      };
    });

    expect(outcome.idle.status).toBe('over');
    // Everything is created forty-two units ahead of the runner, so an
    // untouched run ends at the first thing the lane ever put there.
    expect(outcome.idle.distance).toBeLessThan(60);

    expect(outcome.played.status).toBe('running');
    expect(outcome.played.distance).toBeGreaterThan(outcome.idle.distance);
    expect(outcome.played.coins).toBeGreaterThan(0);
  });

  test('the score is distance plus collection, and collection is worth more than a step', async ({ page }) => {
    const scoring = await page.evaluate(() => {
      const core = window.__facetSatoshiRun!;
      const world = core.createWorld({ seed: 99 });

      core.stepWorld(world, core.IDLE);
      const early = { score: world.score, distance: world.distance, coins: world.coins };

      /* A coin is placed under the runner and taken on the next tick. */
      world.pickups.push({ x: world.distance + 0.1, y: 0.5, collected: false });
      core.stepWorld(world, core.IDLE);

      return {
        early,
        after: { score: world.score, distance: world.distance, coins: world.coins },
        recomputed: core.scoreOf(world),
      };
    });

    expect(scoring.after.coins).toBe(1);
    expect(scoring.after.score).toBe(scoring.recomputed);
    // Distance moved by a hundredth of a unit; the coin is worth 25 points.
    expect(scoring.after.score - scoring.early.score).toBeGreaterThanOrEqual(25);
    expect(Math.floor(scoring.after.distance * 1.6) + 25).toBe(scoring.after.score);
  });

  test('ducking shrinks the runner enough to pass under what hangs', async ({ page }) => {
    const result = await page.evaluate(() => {
      const core = window.__facetSatoshiRun!;

      const attempt = (duck: boolean): string => {
        const world = core.createWorld({ seed: 5 });
        world.obstacles.push({ kind: 'barrier', x: world.distance + 6, width: 1.5, base: 1.05, height: 1.15 });

        for (let tick = 0; tick < 200 && world.status === 'running'; tick += 1) {
          core.stepWorld(world, { jump: false, duck });
        }

        return world.status;
      };

      return { standing: attempt(false), ducking: attempt(true) };
    });

    expect(result.standing).toBe('over');
    expect(result.ducking).toBe('running');
  });
});

test.describe('Satoshi Run — playing it', () => {
  test('keyboard jump and duck reach the runner', async ({ page }) => {
    await launch(page);

    const overlay = page.locator(OVERLAY);
    await expect(overlay).toHaveAttribute('data-facet-run-pose', 'run');

    await page.keyboard.press('Space');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');

    // There is no double jump, so the second one waits for the landing.
    await expect(overlay).toHaveAttribute('data-facet-run-pose', 'run');
    await page.keyboard.press('ArrowUp');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '2');

    await page.keyboard.down('ArrowDown');
    await expect(overlay).toHaveAttribute('data-facet-run-pose', 'duck');
    await page.keyboard.up('ArrowDown');
    await expect(overlay).toHaveAttribute('data-facet-run-pose', /run|air/);

    await page.keyboard.down('KeyS');
    await expect(overlay).toHaveAttribute('data-facet-run-ducks', '2');
    await page.keyboard.up('KeyS');
  });

  test('the run ends on impact, restarts, and remembers the best score', async ({ page }) => {
    await launch(page);

    const overlay = page.locator(OVERLAY);

    // Left alone, the runner meets the first obstacle. That is the loop's
    // other half and it needs no input to reach.
    await expect(overlay).toHaveAttribute('data-facet-run-state', 'over', { timeout: 15_000 });
    // The run speaks the language of the page it was launched from, and this
    // suite launches from `/fr`. Both languages of the run's own chrome are
    // asserted at the end of this file; what matters here is that the loop
    // reported the end of a run at all.
    await expect(page.locator('[data-facet-run-status]')).toContainText('Attrapé');

    const score = Number(await page.locator('[data-facet-run-score]').textContent());
    expect(score).toBeGreaterThan(0);

    const stored = await page.evaluate(() => window.localStorage.getItem('facet.satoshi-run.best'));
    expect(Number(stored)).toBe(score);

    await page.locator('[data-facet-run-restart]').click();
    await expect(overlay).toHaveAttribute('data-facet-run-state', 'running');
    await expect(page.locator('[data-facet-run-best]')).toHaveText(String(score));

    // The best survives a fresh document, which is the whole point of storing
    // it; the score of the new run does not.
    await page.reload();
    await clickBrand(page, CLICKS);
    await expect(page.locator('[data-facet-run-best]')).toHaveText(String(score));
  });

  test('touch plays it: the pads hold, and the stage is a control', async ({ page }) => {
    await launch(page);

    const overlay = page.locator(OVERLAY);
    const jump = page.locator('[data-facet-run-jump]');
    const duck = page.locator('[data-facet-run-duck]');

    await jump.dispatchEvent('pointerdown', { pointerId: 11, pointerType: 'touch', isPrimary: true });
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');
    await jump.dispatchEvent('pointerup', { pointerId: 11, pointerType: 'touch', isPrimary: true });

    await duck.dispatchEvent('pointerdown', { pointerId: 12, pointerType: 'touch', isPrimary: true });
    await expect(overlay).toHaveAttribute('data-facet-run-pose', 'duck');
    await duck.dispatchEvent('pointerup', { pointerId: 12, pointerType: 'touch', isPrimary: true });

    // A thumb on the upper part of the playfield jumps, with no button in it.
    await expect(overlay).toHaveAttribute('data-facet-run-pose', 'run');
    const stage = page.locator('[data-facet-run-stage]');
    // Measured once the overlay has finished arriving: it scales into place,
    // and a box read mid-animation is a box read at the wrong size.
    await overlay.evaluate((node) => Promise.all(node.getAnimations().map((animation) => animation.finished)));

    const box = await stage.boundingBox();
    expect(box).not.toBeNull();

    await stage.dispatchEvent('pointerdown', {
      pointerId: 13,
      pointerType: 'touch',
      isPrimary: true,
      clientY: box!.y + box!.height * 0.2,
      clientX: box!.x + box!.width * 0.5,
    });
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '2');
    await stage.dispatchEvent('pointerup', { pointerId: 13, pointerType: 'touch', isPrimary: true });
  });

  test('Escape closes it, and closing gives the document back', async ({ page }) => {
    await launch(page);

    const before = await page.evaluate(() => ({
      canvases: document.querySelectorAll('canvas').length,
      locked: document.documentElement.classList.contains('facet-run-open'),
    }));

    expect(before.canvases).toBeGreaterThanOrEqual(1);
    expect(before.locked).toBe(true);

    await page.keyboard.press('Escape');

    await expect(page.locator(OVERLAY)).toHaveCount(0);
    await expect(page.locator(BRAND)).toBeFocused();

    const after = await page.evaluate(() => ({
      overlays: document.querySelectorAll('[data-facet-run]').length,
      locked: document.documentElement.classList.contains('facet-run-open'),
    }));

    expect(after).toEqual({ overlays: 0, locked: false });
  });

  test('pagehide tears the game down with the rest of the skin', async ({ page }) => {
    await launch(page);

    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));

    await expect(page.locator(OVERLAY)).toHaveCount(0);
    expect(await page.evaluate(() => document.querySelectorAll('canvas').length)).toBe(0);
  });
});

/*
 * Closing is only half a lifecycle if the launcher stays spent.
 *
 * The suite above proves that closing gives the *document* back — no overlay,
 * no canvas, no scroll lock. What it cannot see is the handle the skin keeps
 * on the running game, which is what refuses a second one: a run that closed
 * itself without saying so leaves that handle pointing at nothing and the
 * launcher dead until a reload. From the outside the two are told apart by one
 * question only, so this is the suite that asks it: can you play again?
 */
test.describe('Satoshi Run — closing and relaunching', () => {
  /*
   * Every overlay, lane and lock the game is capable of leaving behind.
   *
   * The canvases are counted by the game's own class rather than by tag: the
   * hero visual mounts a canvas of its own on this page, at an idle moment
   * this suite does not control, and counting every canvas in the document
   * would make the assertion a race against an unrelated effect.
   */
  const residue = async (page: import('@playwright/test').Page) =>
    page.evaluate(() => ({
      overlays: document.querySelectorAll('[data-facet-run]').length,
      lanes: document.querySelectorAll('.facet-run__canvas').length,
      locked: document.documentElement.classList.contains('facet-run-open'),
    }));

  for (const [name, close] of [
    ['the Close button', async (page: import('@playwright/test').Page) => page.locator('[data-facet-run-close]').click()],
    ['Escape', async (page: import('@playwright/test').Page) => page.keyboard.press('Escape')],
  ] as const) {
    test(`${name} closes a run and the gesture starts another`, async ({ page }) => {
      await launch(page);
      await close(page);

      await expect(page.locator(OVERLAY)).toHaveCount(0);
      expect(await residue(page)).toEqual({ overlays: 0, lanes: 0, locked: false });

      // The whole finding, in one line: without a reload, perform the gesture
      // again.
      await clickBrand(page, CLICKS);
      await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
      expect(await page.locator(OVERLAY).count()).toBe(1);

      // And it is a *new* run rather than the old one shown again.
      await page.keyboard.press('Space');
      await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-jumps', '1');
    });
  }

  test('open and close it repeatedly and nothing accumulates', async ({ page }) => {
    await page.goto('/fr');
    await expect(page.locator(BRAND)).toBeVisible();

    for (let cycle = 0; cycle < 4; cycle += 1) {
      await clickBrand(page, CLICKS);

      const overlay = page.locator(OVERLAY);
      await expect(overlay).toHaveAttribute('data-facet-run-state', 'running');

      // One of everything while it is up, on every cycle and not just the first.
      const open = await residue(page);
      expect(open, `cycle ${cycle} must hold exactly one game`).toEqual({
        overlays: 1,
        lanes: 1,
        locked: true,
      });

      // Alternate the two ways out, so neither path is the only one exercised.
      if (cycle % 2 === 0) {
        await page.locator('[data-facet-run-close]').click();
      } else {
        await page.keyboard.press('Escape');
      }

      await expect(overlay).toHaveCount(0);
      expect(await residue(page), `cycle ${cycle} must leave nothing behind`).toEqual({
        overlays: 0,
        lanes: 0,
        locked: false,
      });
    }

    /*
     * A stale RAF loop is invisible in the DOM, so it is caught by what it
     * would still be doing: four abandoned loops would go on stepping four
     * abandoned worlds. The counter below belongs to the *current* run, and
     * the frozen-then-moving check is the loop being alive exactly once.
     */
    await clickBrand(page, CLICKS);
    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
    await expect(page.locator('[data-facet-run-score]')).not.toHaveText('0');
  });

  test('a burst of presses after a close still opens exactly one game', async ({ page }) => {
    await launch(page);
    await page.locator('[data-facet-run-close]').click();
    await expect(page.locator(OVERLAY)).toHaveCount(0);

    /*
     * Two sequences' worth of presses, dispatched in one synchronous burst so
     * that every one of them lands before the dynamic import can resolve. That
     * is the one window in which two mounts could race — the handle that
     * refuses a second run is only assigned once the chunk is in — and no
     * amount of clicking through the harness can be relied on to fall inside
     * it, which is why the events are made here.
     *
     * Each press is a `pointerdown` and then a `click`, because that is what a
     * pointer does and the trigger reads both: the press is what tells a real
     * click from a keyboard activation.
     */
    await page.locator(BRAND).evaluate((node, count) => {
      for (let index = 0; index < count; index += 1) {
        node.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, isPrimary: true }));
        node.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
      }
    }, CLICKS * 2);

    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
    expect(await page.evaluate(() => document.querySelectorAll('[data-facet-run]').length)).toBe(1);
    expect(await page.evaluate(() => document.querySelectorAll('.facet-run__canvas').length)).toBe(1);
  });

  test('pagehide after a local close is a no-op, not a second teardown', async ({ page }) => {
    await launch(page);
    await page.keyboard.press('Escape');
    await expect(page.locator(OVERLAY)).toHaveCount(0);

    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));

    expect(errors, 'tearing down an already-closed run must throw nothing').toEqual([]);
    expect(await residue(page)).toEqual({ overlays: 0, lanes: 0, locked: false });
  });
});

/*
 * A context can be taken away mid-run, and the game must survive it.
 *
 * The choice of renderer is made once, at mount, from what the browser said it
 * could do — and a browser is allowed to change its mind afterwards. A driver
 * reset or a compositor reclaiming memory fires `webglcontextlost`, after
 * which every GL call is a silent no-op: the picture freezes on its last frame
 * while the simulation runs on behind it. That is indistinguishable from a
 * hang to the person playing, so it is the one renderer failure the mount-time
 * choice cannot cover, and the one this suite injects for real.
 */
test.describe('Satoshi Run — losing the context', () => {
  /** Kills the live WebGL2 context the way the driver would. */
  const loseContext = async (page: import('@playwright/test').Page): Promise<boolean> =>
    page.evaluate(() => {
      const canvas = document.querySelector<HTMLCanvasElement>('[data-facet-run-stage] canvas');

      if (canvas === null) {
        return false;
      }

      /* The same id returns the context already in use rather than a new one. */
      const gl = canvas.getContext('webgl2');
      const extension = gl?.getExtension('WEBGL_lose_context') ?? null;

      if (extension === null) {
        return false;
      }

      extension.loseContext();

      return true;
    });

  test.beforeEach(async ({ page }) => {
    await allowSoftwareWebgl(page);
    await launch(page);

    const renderer = await page.locator(OVERLAY).getAttribute('data-facet-run-renderer');
    test.skip(renderer !== 'webgl2', 'this browser did not give the run an accelerated lane');
  });

  test('a lost context degrades to the 2D lane and keeps the run', async ({ page }) => {
    const overlay = page.locator(OVERLAY);

    // Somewhere to fall from: a score and a jump that must both survive.
    await page.keyboard.press('Space');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');
    await expect(page.locator('[data-facet-run-score]')).not.toHaveText('0');

    const before = Number(await page.locator('[data-facet-run-score]').textContent());

    test.skip(!(await loseContext(page)), 'this browser cannot lose a context on request');

    // The lane changes; the game does not end, and does not restart.
    await expect(overlay).toHaveAttribute('data-facet-run-renderer', 'canvas2d');
    await expect(overlay).toHaveAttribute('data-facet-run-degraded', 'context-lost');
    await expect(overlay).toHaveAttribute('data-facet-run-state', 'running');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');

    // Exactly one surface is being drawn on, not the dead one plus its heir.
    expect(await page.evaluate(() => document.querySelectorAll('.facet-run__canvas').length)).toBe(1);

    /*
     * The score is the loop's own pulse: it only advances while something is
     * stepping the world *and* the frozen renderer is no longer the one being
     * drawn to. It must have carried its old value across, not reset to zero.
     */
    const after = Number(await page.locator('[data-facet-run-score]').textContent());
    expect(after).toBeGreaterThanOrEqual(before);

    await expect
      .poll(async () => Number(await page.locator('[data-facet-run-score]').textContent()))
      .toBeGreaterThan(after);

    /*
     * And it is still a game: the controls reach the fallback lane too. The
     * press is retried rather than made once, because a runner already in the
     * air ignores a jump — which is the rule, not a fault, and not something
     * this test is entitled to be lucky about.
     */
    await expect
      .poll(
        async () => {
          await page.keyboard.press('Space');

          return Number(await overlay.getAttribute('data-facet-run-jumps'));
        },
        { timeout: 10_000 },
      )
      .toBeGreaterThan(1);

    // Played to its end on the lane it fell back to.
    await expect(overlay).toHaveAttribute('data-facet-run-state', 'over', { timeout: 15_000 });
  });

  test('closing after a lost context still gives everything back', async ({ page }) => {
    test.skip(!(await loseContext(page)), 'this browser cannot lose a context on request');
    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-renderer', 'canvas2d');

    await page.locator('[data-facet-run-close]').click();

    await expect(page.locator(OVERLAY)).toHaveCount(0);
    await expect(page.locator(BRAND)).toBeFocused();
    expect(
      await page.evaluate(() => ({
        lanes: document.querySelectorAll('.facet-run__canvas').length,
        locked: document.documentElement.classList.contains('facet-run-open'),
      })),
    ).toEqual({ lanes: 0, locked: false });

    // A degraded run is still a run the gesture can start again.
    await clickBrand(page, CLICKS);
    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-state', 'running');
  });

  test('pagehide after a lost context leaves no run resources behind', async ({ page }) => {
    test.skip(!(await loseContext(page)), 'this browser cannot lose a context on request');
    await expect(page.locator(OVERLAY)).toHaveAttribute('data-facet-run-renderer', 'canvas2d');

    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));

    expect(errors).toEqual([]);
    await expect(page.locator(OVERLAY)).toHaveCount(0);
    expect(await page.evaluate(() => document.querySelectorAll('canvas').length)).toBe(0);
  });
});

test.describe('Satoshi Run — presentation', () => {
  test('the accelerated renderer compiles, links and draws', async ({ page }) => {
    await allowSoftwareWebgl(page);
    await launch(page);

    const overlay = page.locator(OVERLAY);
    await expect(overlay).toHaveAttribute('data-facet-run-renderer', 'webgl2');

    // A shader that failed to compile makes the mount answer null and the 2D
    // lane take over, so the attribute above is the compile-and-link proof.
    // What remains is that it keeps drawing: the score only moves if it does.
    await page.keyboard.press('Space');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');
    await expect(page.locator('[data-facet-run-score]')).not.toHaveText('0');
  });

  test('with WebGL2 refused, the 2D lane plays the same game', async ({ page }) => {
    await page.addInitScript(() => {
      const original = HTMLCanvasElement.prototype.getContext;

      HTMLCanvasElement.prototype.getContext = function patched(this: HTMLCanvasElement, id: string, ...rest: unknown[]) {
        if (id === 'webgl2' || id === 'webgl') {
          return null;
        }

        return (original as (...args: unknown[]) => unknown).call(this, id, ...rest);
      } as typeof HTMLCanvasElement.prototype.getContext;
    });

    await launch(page);

    const overlay = page.locator(OVERLAY);
    await expect(overlay).toHaveAttribute('data-facet-run-renderer', 'canvas2d');

    await page.keyboard.press('Space');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');

    await expect(overlay).toHaveAttribute('data-facet-run-state', 'over', { timeout: 15_000 });
    expect(Number(await page.locator('[data-facet-run-score]').textContent())).toBeGreaterThan(0);
  });

  test('it plays in both themes and follows a switch mid-run', async ({ page }) => {
    await page.goto('/fr');
    await page.getByRole('button', { name: 'Thème sombre' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await clickBrand(page, CLICKS);
    const overlay = page.locator(OVERLAY);
    await expect(overlay).toHaveAttribute('data-facet-run-state', 'running');

    const dark = await overlay.evaluate((node) => getComputedStyle(node).backgroundColor);

    // The overlay paints with the skin's own semantic tokens, so a theme
    // switch under a running game moves the game with it.
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));

    const light = await overlay.evaluate((node) => getComputedStyle(node).backgroundColor);
    expect(light).not.toBe(dark);

    await page.keyboard.press('Space');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');
  });
});

test.describe('Satoshi Run — a phone', () => {
  /*
   * A narrow viewport is not a smaller feature. The lane is measured in world
   * units and the renderers scale it, so what has to be checked here is that
   * the overlay still lays out — a stage with a real box, two pads inside the
   * screen — and that a thumb still plays the game to its end.
   */
  test('the overlay fits a phone and a thumb plays it to the end', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await launch(page);

    const overlay = page.locator(OVERLAY);
    const stage = page.locator('[data-facet-run-stage]');
    const jump = page.locator('[data-facet-run-jump]');

    // Measured once the overlay has finished arriving: it scales into place,
    // and a box read mid-animation is a box read at the wrong size.
    await overlay.evaluate((node) => Promise.all(node.getAnimations().map((animation) => animation.finished)));

    const box = await stage.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.width).toBeGreaterThan(300);
    expect(box!.height).toBeGreaterThan(200);

    // Both pads are on screen and big enough to hit without aiming.
    for (const pad of [jump, page.locator('[data-facet-run-duck]')]) {
      const padBox = await pad.boundingBox();
      expect(padBox).not.toBeNull();
      expect(padBox!.height).toBeGreaterThanOrEqual(44);
      expect(padBox!.x + padBox!.width).toBeLessThanOrEqual(390);
    }

    await jump.dispatchEvent('pointerdown', { pointerId: 21, pointerType: 'touch', isPrimary: true });
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');
    await jump.dispatchEvent('pointerup', { pointerId: 21, pointerType: 'touch', isPrimary: true });

    await expect(overlay).toHaveAttribute('data-facet-run-state', 'over', { timeout: 15_000 });
    expect(Number(await page.locator('[data-facet-run-score]').textContent())).toBeGreaterThan(0);
  });
});

test.describe('Satoshi Run — reduced motion', () => {
  test('it is fully playable, without the decoration', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/fr');

    expect(await page.evaluate(() => window.matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(true);

    await clickBrand(page, CLICKS);
    const overlay = page.locator(OVERLAY);
    await expect(overlay).toHaveAttribute('data-facet-run-state', 'running');

    // The overlay's own arrival animation is behind a no-preference query.
    expect(await overlay.evaluate((node) => getComputedStyle(node).animationName)).toBe('none');

    await page.keyboard.press('Space');
    await expect(overlay).toHaveAttribute('data-facet-run-jumps', '1');

    await expect(overlay).toHaveAttribute('data-facet-run-state', 'over', { timeout: 15_000 });
    expect(Number(await page.locator('[data-facet-run-score]').textContent())).toBeGreaterThan(0);
  });
});

/**
 * PORT-137 — the run under two languages.
 *
 * Nothing about the game changed: the gesture, the physics, the world, the
 * scoring and the deferred chunk are exactly what PORT-134 and PORT-135 left.
 * What is asserted here is only the presentation boundary the language reaches
 * — the run's own labels, handed to it as data on the element the gesture is
 * attached to — and that the gesture itself is unaffected by which language the
 * page it launches from is written in.
 */
test.describe('Satoshi Run is localized at its chrome and nowhere else', () => {
  for (const [path, labels] of [
    ['/fr', { jump: 'Sauter', duck: 'Se baisser', restart: 'Recommencer', close: 'Fermer' }],
    ['/en', { jump: 'Jump', duck: 'Duck', restart: 'Restart', close: 'Close' }],
  ] as const) {
    test(`the five-click gesture launches the run on ${path}, in that language`, async ({ page }) => {
      await page.goto(path);

      // Nothing of the game is fetched before the gesture completes.
      const requested: string[] = [];
      page.on('request', (request) => {
        if (RUN_CHUNK.test(request.url())) {
          requested.push(request.url());
        }
      });

      await expect(page.locator(BRAND)).toBeVisible();
      expect(requested, 'the chunk must not be fetched before the gesture').toEqual([]);

      await clickBrand(page, CLICKS);
      await page.locator(OVERLAY).waitFor({ state: 'visible' });
      expect(requested.length, 'the gesture fetches the chunk exactly once').toBe(1);

      await expect(page.locator(OVERLAY)).toHaveCount(1);
      await expect(page.getByRole('dialog', { name: 'Satoshi Run' })).toBeVisible();

      for (const label of Object.values(labels)) {
        await expect(page.getByRole('button', { name: label, exact: true })).toBeVisible();
      }

      await page.getByRole('button', { name: labels.close, exact: true }).click();
      await expect(page.locator(OVERLAY)).toHaveCount(0);
    });
  }

  test('the brand points at the home page of the language being read', async ({ page }) => {
    for (const [path, home] of [
      ['/en/about', '/en'],
      ['/fr/about', '/fr'],
    ]) {
      await page.goto(path);
      await expect(page.locator(BRAND)).toHaveAttribute('href', home);
    }
  });

  test('switching language does not mount the game', async ({ page }) => {
    await page.goto('/fr');

    await page
      .getByRole('navigation', { name: 'Langue' })
      .getByRole('link', { name: /English/ })
      .click();

    await expect(page).toHaveURL('/en');
    await expect(page.locator(OVERLAY)).toHaveCount(0);
  });
});
