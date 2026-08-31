/**
 * PORT-138 corrective evidence — the clouds, the route list, and the crossfade.
 *
 * Usage, against a server holding a production build:
 *
 *     node tools/shell-corrective-shots.mjs docs/reports/PORT-138/corrective http://127.0.0.1:8765
 *
 * A companion to tools/shell-toggle-shots.mjs rather than a replacement: that
 * script photographed the control PORT-138 built, this one photographs the
 * four things the human review asked to see changed — the cloud silhouette,
 * the nav's three states, the header at both ends of the responsive range, and
 * the page transition that did not exist before.
 *
 * The settled shots are deterministic by construction: the theme is written to
 * `localStorage` before the first paint rather than switched afterwards, so no
 * still frame can catch a transition it was not meant to catch, and reduced
 * motion is emulated wherever a ribbon would otherwise photograph differently
 * every run.
 *
 * The transition strip is the exception and says so in its own filenames. A
 * screenshot of a crossfade is a screenshot of one arbitrary moment on a
 * machine of one particular speed, so each frame is named with the elapsed
 * milliseconds actually measured when it was taken — not with the moment it
 * was aimed at. Alongside them the script measures the change the way a
 * visitor experiences it: sampling the document's own background colour every
 * frame and reporting how long it took to stop moving.
 */
import { mkdir, writeFile } from 'node:fs/promises';

import playwright from '@playwright/test';

const { chromium, devices } = playwright;

const [, , out, base = 'http://127.0.0.1:8765'] = process.argv;

if (out === undefined) {
  console.error('usage: node tools/shell-corrective-shots.mjs <output-directory> [base-url]');
  process.exit(2);
}

await mkdir(out, { recursive: true });

const DESKTOP = { width: 1512, height: 950 };
const TOGGLE = '[data-facet-theme-toggle]';
const HEADER = '[data-facet-header]';
const NAV = '[data-facet-nav]';

const browser = await chromium.launch();

const notes = [];

function note(line) {
  notes.push(line);
  console.log(line);
}

async function open(browser, theme, viewport, device, { motion = 'reduce' } = {}) {
  const context = await browser.newContext({
    ...(device ?? {}),
    ...(viewport === null ? {} : { viewport }),
    reducedMotion: motion,
    colorScheme: theme,
    deviceScaleFactor: 2,
  });

  const page = await context.newPage();

  await page.addInitScript((value) => {
    try {
      window.localStorage.setItem('facet.theme', value);
    } catch {
      /* nothing to store into; the shot simply follows the emulated scheme */
    }
  }, theme);

  await page.goto(`${base}/`);
  await page.waitForSelector('#hero-title');
  await page.waitForSelector('html[data-facet="ready"]');

  return { context, page };
}

/** The capsule alone, blown up: this is the shot the cloud has to survive. */
async function capsuleShot(page, file, { scale = 1 } = {}) {
  const box = await page.locator(`${TOGGLE} .facet-theme-toggle__scene`).boundingBox();

  if (box === null) {
    throw new Error(`the capsule is not laid out: ${file}`);
  }

  const pad = 10 * scale;

  await page.screenshot({
    path: `${out}/${file}`,
    clip: {
      x: Math.max(0, box.x - pad),
      y: Math.max(0, box.y - pad),
      width: box.width + pad * 2,
      height: box.height + pad * 2,
    },
  });

  console.log(file);
}

async function element(page, selector, file) {
  await page.locator(selector).first().screenshot({ path: `${out}/${file}` });
  console.log(file);
}

/* ------------------------------------------- 1-3: the capsule, close up */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, DESKTOP);

  // The cloud is drawn in rem, so a larger root size is the same drawing at a
  // larger size — the honest way to inspect a 52px control without resampling.
  await capsuleShot(page, `capsule-${theme}-idle.png`);

  await page.evaluate(() => {
    document.documentElement.style.fontSize = '48px';
  });
  await page.waitForTimeout(120);
  await capsuleShot(page, `capsule-${theme}-magnified.png`, { scale: 3 });

  await page.evaluate(() => {
    document.documentElement.style.fontSize = '';
  });
  await page.waitForTimeout(120);

  await page.locator(TOGGLE).hover();
  await page.waitForTimeout(160);
  await capsuleShot(page, `capsule-${theme}-hover.png`);

  await context.close();
}

/* ------------------------------------ 4-8: the header and the route list */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, DESKTOP);

  await element(page, HEADER, `header-desktop-${theme}.png`);

  // The current route, at rest, next to three that are not.
  await element(page, NAV, `nav-${theme}-idle.png`);

  await page.getByRole('link', { name: 'Projects', exact: true }).first().hover();
  await page.waitForTimeout(240);
  await element(page, NAV, `nav-${theme}-hover.png`);

  await page.mouse.move(0, 0);
  await page.getByRole('link', { name: 'Projects', exact: true }).first().focus();
  await page.waitForTimeout(240);
  await element(page, NAV, `nav-${theme}-focus.png`);

  await context.close();
}

/* --------------------------------------------- 9-10: the header on a phone */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, null, devices['iPhone 13']);

  await element(page, HEADER, `header-mobile-${theme}.png`);

  await page.getByRole('button', { name: 'Menu' }).click();
  await page.waitForTimeout(200);
  await element(page, HEADER, `header-mobile-${theme}-open.png`);

  await context.close();
}

/* ------------------------------------------------- the page transition */

/**
 * One direction of the crossfade, as frames and as a measurement.
 *
 * The frames are not snapshots of a running animation — a full-page screenshot
 * takes longer than the transition it would be trying to photograph, so aiming
 * a camera at it produces six pictures of the finished page and a caption that
 * lies. They are the real transitions, paused: the press starts them, every
 * animation on the document is paused in the same task, and each frame is the
 * document with `currentTime` set to a stated fraction of the 320ms. What is
 * shown is therefore exactly what the engine would have painted at that
 * moment, and the filenames say which moment.
 *
 * The measurement is separate and unpaused: a second pass samples the resolved
 * ground colour every frame, in real time, and reports how long it moved.
 */
async function transitionStrip(from) {
  const to = from === 'light' ? 'dark' : 'light';

  /* ---- the frames, paused at fractions of the real transition ---- */
  {
    const { context, page } = await open(browser, from, DESKTOP, undefined, {
      motion: 'no-preference',
    });

    await page.evaluate(() => {
      document.querySelector('[data-facet-theme-toggle]').click();

      for (const animation of document.getAnimations()) {
        animation.pause();
      }
    });

    for (const fraction of [0, 0.25, 0.5, 0.75, 1]) {
      await page.evaluate((value) => {
        for (const animation of document.getAnimations()) {
          animation.currentTime = 320 * value;
        }
      }, fraction);

      const label = String(Math.round(fraction * 320)).padStart(3, '0');

      await page.screenshot({
        path: `${out}/transition-${from}-to-${to}-paused-at-${label}ms.png`,
        clip: { x: 0, y: 0, width: DESKTOP.width, height: 460 },
      });

      console.log(`transition-${from}-to-${to}-paused-at-${label}ms.png`);
    }

    await context.close();
  }

  /* ---- and the same switch, unpaused, measured ---- */
  {
    const { context, page } = await open(browser, from, DESKTOP, undefined, {
      motion: 'no-preference',
    });

    await page.evaluate(() => {
      const readings = [];
      const start = performance.now();

      const sample = () => {
        const root = getComputedStyle(document.documentElement);

        readings.push({
          at: performance.now() - start,
          colour: root.getPropertyValue('--facet-canvas').trim(),
          ink: getComputedStyle(document.body).color,
          shift: document.documentElement.hasAttribute('data-facet-theme-shift'),
        });

        if (performance.now() - start < 1500) {
          requestAnimationFrame(sample);
        }
      };

      window.__facetStartSampling = () => requestAnimationFrame(sample);
      window.__facetTransitionReadings = readings;
    });

    await page.evaluate(() => window.__facetStartSampling());
    await page.locator(TOGGLE).click();
    await page.waitForTimeout(1600);

    const readings = await page.evaluate(() => window.__facetTransitionReadings ?? []);

    const span = (key) => {
      const moving = readings.filter((reading, index) => {
        const previous = readings[index - 1];

        return previous !== undefined && previous[key] !== reading[key];
      });

      const first = moving.at(0);
      const last = moving.at(-1);

      return first === undefined || last === undefined
        ? { ms: 0, frames: 0 }
        : { ms: Math.round(last.at - first.at), frames: moving.length };
    };

    const ground = span('colour');
    const ink = span('ink');
    const marked = readings.filter((reading) => reading.shift);

    note(
      `${from} → ${to}: ground moved ${ground.ms}ms over ${ground.frames} frames; ` +
        `body ink moved ${ink.ms}ms over ${ink.frames} frames; ` +
        `the transition mark was present ` +
        `${marked.length === 0 ? '0' : Math.round((marked.at(-1)?.at ?? 0) - (marked.at(0)?.at ?? 0))}ms ` +
        `(${readings.length} frames sampled).`,
    );

    await element(page, HEADER, `transition-${from}-to-${to}-settled.png`);

    await context.close();
  }
}

await transitionStrip('light');
await transitionStrip('dark');

await writeFile(`${out}/measurements.txt`, `${notes.join('\n')}\n`, 'utf8');

await browser.close();
