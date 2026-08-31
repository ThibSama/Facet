/**
 * PORT-138 visual evidence — the theme control, the header, and the page end.
 *
 * Usage, against a server holding a production build:
 *
 *     node tools/shell-toggle-shots.mjs docs/reports/PORT-138 http://127.0.0.1:8765
 *
 * Not a test, and pointedly not proof of the animation. A screenshot of a
 * transition is a screenshot of one arbitrary moment on a machine of one
 * particular speed; what the transition *does* is asserted in
 * tests/E2E/theme-toggle.spec.ts, against the properties CSS transitions to.
 * What this script produces is the thing a test cannot judge — whether the
 * result is any good — plus one deliberately non-deterministic strip of
 * mid-transition frames, labelled as such, so a reviewer can see the shape of
 * the movement before deciding whether to trust it.
 *
 * Everything else here is deterministic by construction. The theme is stamped
 * into `localStorage` before the first paint rather than switched afterwards,
 * so no settled shot catches a transition; reduced motion is emulated wherever
 * a moving ribbon would otherwise photograph differently every run; and each
 * shot waits on an anchor in the DOM rather than on a duration.
 */
import { mkdir } from 'node:fs/promises';

import playwright from '@playwright/test';

const { chromium, devices } = playwright;

const [, , out, base = 'http://127.0.0.1:8765'] = process.argv;

if (out === undefined) {
  console.error('usage: node tools/shell-toggle-shots.mjs <output-directory> [base-url]');
  process.exit(2);
}

await mkdir(out, { recursive: true });

const DESKTOP = { width: 1512, height: 950 };
const WIDE = { width: 1920, height: 1000 };

const TOGGLE = '[data-facet-theme-toggle]';

/**
 * The control, framed with room around it.
 *
 * The button is 44px and the capsule inside it is smaller still, so a shot
 * clipped to the element is a picture of the element and not of the control in
 * its header. This crops a fixed box around the button's own centre instead,
 * which keeps the brand-side and nav-side spacing in every frame — the spacing
 * being half of what the header rebalance is asking to be judged on.
 */
async function toggleShot(page, file, { pad = 40 } = {}) {
  const box = await page.locator(TOGGLE).boundingBox();

  if (box === null) {
    throw new Error(`the theme control is not laid out: ${file}`);
  }

  await page.screenshot({
    path: `${out}/${file}`,
    clip: {
      x: Math.max(0, box.x - pad * 2.5),
      y: Math.max(0, box.y - pad),
      width: box.width + pad * 5,
      height: box.height + pad * 2,
    },
  });

  console.log(file);
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

  await page.goto(base + '/');
  await page.waitForSelector('#hero-title');
  await page.waitForSelector('html[data-facet="ready"]');

  return { context, page };
}

async function section(page, selector, file) {
  const target = page.locator(selector).first();

  await target.scrollIntoViewIfNeeded();
  await page.waitForTimeout(120);
  await target.screenshot({ path: `${out}/${file}` });

  console.log(file);
}

async function pageEnd(page, file) {
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(200);
  await page.screenshot({ path: `${out}/${file}` });

  console.log(file);
}

const browser = await chromium.launch();

/* ---------------------------------------------- the control, both states */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, DESKTOP);

  await toggleShot(page, `toggle-desktop-${theme}-idle.png`);

  await page.locator(TOGGLE).hover();
  await page.waitForTimeout(160);
  await toggleShot(page, `toggle-desktop-${theme}-hover.png`);

  await page.mouse.move(0, 0);
  await page.locator(TOGGLE).focus();
  await page.waitForTimeout(120);
  await toggleShot(page, `toggle-desktop-${theme}-focus.png`);

  await context.close();
}

/* ------------------------------------------------ the control, on a phone */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, null, devices['iPhone 13']);

  await toggleShot(page, `toggle-mobile-${theme}.png`, { pad: 24 });

  await context.close();
}

/*
 * ----------------------------------------------------- the transition
 *
 * Deliberately time-dilated, and labelled as such everywhere it appears.
 *
 * The real transition is 360ms. Capturing a screenshot costs more than a
 * frame of that, so sampling the real thing on a timer produces a strip of
 * near-identical settled states plus whatever the machine happened to be
 * doing — which tells a reviewer nothing except how fast this laptop is.
 *
 * So the duration token is overridden to ten times its real value for these
 * frames only, and the strip is sampled across that. What it shows is the
 * *shape* of the interpolation — where the body is when the clouds have gone,
 * how late the stars arrive relative to the sky — which is the thing a human
 * is being asked to judge. It is not evidence of timing, and the file names
 * say so. The timing is asserted in tests/E2E/theme-toggle.spec.ts.
 */
const DILATION = 10;

for (const [from, direction] of [
  ['light', 'to-dark'],
  ['dark', 'to-light'],
]) {
  const { context, page } = await open(browser, from, DESKTOP, undefined, { motion: 'no-preference' });

  /* The skin states the token behind `html[data-skin=…]`, so the override is
     forced rather than merely appended: out-specifying it by hand is one
     attribute selector away from silently losing and producing a strip of
     settled frames that looks like evidence and is not. */
  await page.addStyleTag({
    content: `.facet-theme-toggle {
                --facet-toggle-duration: ${360 * DILATION}ms !important;
              }
              .facet-theme-toggle[aria-pressed='true'] .facet-theme-toggle__stars {
                transition-delay: ${80 * DILATION}ms !important;
              }`,
  });

  /* If the override did not land, the strip is worthless — so say so loudly
     rather than photographing six copies of the finished state. */
  const applied = await page
    .locator(TOGGLE)
    .evaluate((node) => window.getComputedStyle(node).getPropertyValue('--facet-toggle-duration').trim());

  if (!applied.startsWith(String(360 * DILATION))) {
    throw new Error(`the transition override did not apply (got "${applied}")`);
  }

  const toggle = page.locator(TOGGLE);
  await toggle.scrollIntoViewIfNeeded();
  await toggleShot(page, `transition-${direction}-slowed-00-before.png`);

  const started = Date.now();
  await toggle.click();

  /* Six samples spread across the dilated transition, by elapsed time rather
     than by a cumulative sleep, so a slow capture does not push the rest. */
  for (const [index, at] of [400, 900, 1400, 1900, 2500, 3200].entries()) {
    const remaining = at - (Date.now() - started);

    if (remaining > 0) {
      await page.waitForTimeout(remaining);
    }

    await toggleShot(page, `transition-${direction}-slowed-0${index + 1}.png`);
  }

  await page.waitForTimeout(1200);
  await toggleShot(page, `transition-${direction}-slowed-99-after.png`);

  await context.close();
}

/* --------------------------------------- header, skills, finale, page end */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, DESKTOP);

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: `${out}/header-desktop-${theme}.png`,
    clip: { x: 0, y: 0, width: DESKTOP.width, height: 140 },
  });
  console.log(`header-desktop-${theme}.png`);

  /*
   * The ribbons, twice.
   *
   * The static one is the reduced-motion and no-JavaScript state, where the
   * list simply wraps and no mask exists at all. The mask being adjusted only
   * exists on a *live* ribbon, so the shot that actually shows the change is
   * taken separately, below, with motion enabled — a shot of the static
   * ribbon would have been a picture of the thing not under review.
   */
  await section(page, 'section[aria-labelledby="skills"]', `skills-static-${theme}.png`);
  await section(page, 'section[aria-labelledby="journey"]', `journey-desktop-${theme}.png`);
  await pageEnd(page, `page-end-desktop-${theme}.png`);

  await context.close();
}

/*
 * The live ribbons, which is where the entry mask exists.
 *
 * The strip is moving, so this frame is not reproducible byte for byte — what
 * is reproducible is the property under review: the first chip after each
 * category label is fully opaque rather than half-dissolved beneath it.
 */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, DESKTOP, undefined, { motion: 'no-preference' });

  const skills = page.locator('section[aria-labelledby="skills"]');
  await skills.scrollIntoViewIfNeeded();
  await page.waitForSelector('[data-facet-ribbon="live"]');
  await page.waitForTimeout(600);

  await skills.screenshot({ path: `${out}/skills-live-${theme}.png` });
  console.log(`skills-live-${theme}.png`);

  /* And the entry edge on its own, at the scale the problem was visible at. */
  const row = await page.locator('.facet-skills__row').last().boundingBox();

  if (row !== null) {
    await page.screenshot({
      path: `${out}/skills-left-edge-${theme}.png`,
      clip: { x: row.x, y: row.y - 190, width: 620, height: 300 },
    });
    console.log(`skills-left-edge-${theme}.png`);
  }

  await context.close();
}

/* The finale at the width its horizontal emptiness was raised at. */
{
  const { context, page } = await open(browser, 'dark', WIDE);

  await section(page, 'section[aria-labelledby="get-in-touch"]', 'finale-wide-dark.png');
  await pageEnd(page, 'page-end-wide-dark.png');

  await context.close();
}

/* The page end on a phone: the footer is gone there too. */
{
  const { context, page } = await open(browser, 'dark', null, devices['iPhone 13']);

  await pageEnd(page, 'page-end-mobile-dark.png');

  await context.close();
}

/* --------------------------------------------------- the header, at width */
for (const width of [320, 390, 412, 768, 834, 1024, 1280, 1512, 1920]) {
  const { context, page } = await open(browser, 'dark', { width, height: 900 });

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: `${out}/header-${width}-dark.png`,
    clip: { x: 0, y: 0, width, height: 130 },
  });
  console.log(`header-${width}-dark.png`);

  await context.close();
}

await browser.close();
