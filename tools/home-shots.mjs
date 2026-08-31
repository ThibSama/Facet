/**
 * PORT-136 visual evidence — the home page, photographed.
 *
 * Usage, against a server holding a production build:
 *
 *     node tools/home-shots.mjs docs/reports/PORT-136 http://127.0.0.1:8765
 *
 * Not a test and never a substitute for one: it drives the real built page on
 * the real server and photographs the composition a human has to approve.
 *
 * Determinism is the whole design of the script. The theme is stamped into
 * `localStorage` before the first paint rather than switched afterwards, so no
 * shot catches a transition; the reduced-motion preference is emulated for the
 * frames where a moving ribbon would otherwise be a different picture every
 * run; and every section shot waits for its own anchor to exist rather than
 * for a duration, so a slow machine produces the same photograph as a fast one.
 */
import playwright from '@playwright/test';

const { chromium, devices } = playwright;

const [, , out, base = 'http://127.0.0.1:8765'] = process.argv;

if (out === undefined) {
  console.error('usage: node tools/home-shots.mjs <output-directory> [base-url]');
  process.exit(2);
}

const DESKTOP = { width: 1512, height: 950 };
const LAPTOP = { width: 1280, height: 850 };
const TABLET = { width: 834, height: 1112 };

/**
 * Opens the home page in one theme at one size.
 *
 * `reducedMotion: 'reduce'` is passed for every shot on purpose. It stops the
 * skill ribbons mid-list and cancels section entry, which is what makes two
 * runs of this script produce the same bytes — and what it costs is exactly
 * the two things a still photograph could never have shown anyway.
 */
async function open(browser, theme, viewport, device) {
  const context = await browser.newContext({
    ...(device ?? {}),
    ...(viewport === null ? {} : { viewport }),
    reducedMotion: 'reduce',
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
  /* The H1, not the hero visual: below 40rem the visual is not rendered. */
  await page.waitForSelector('#hero-title');
  await page.waitForSelector('html[data-facet="ready"]');

  return { context, page };
}

/** One section, framed by its own landmark rather than by a scroll offset. */
async function section(page, selector, file) {
  const target = page.locator(selector).first();

  await target.scrollIntoViewIfNeeded();
  await page.waitForTimeout(120);
  await target.screenshot({ path: `${out}/${file}` });

  console.log(file);
}

async function viewport(page, file) {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: `${out}/${file}` });

  console.log(file);
}

async function fullPage(page, file) {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: `${out}/${file}`, fullPage: true });

  console.log(file);
}

const browser = await chromium.launch();

/* Desktop, dark: the whole rhythm, then each movement on its own. */
{
  const { context, page } = await open(browser, 'dark', DESKTOP);

  await viewport(page, 'desktop-dark-hero.png');
  await fullPage(page, 'desktop-dark-full.png');
  await section(page, 'section[aria-labelledby="featured-projects"]', 'desktop-dark-work.png');
  await section(page, 'section[aria-labelledby="skills"]', 'desktop-dark-skills.png');
  await section(page, 'section[aria-labelledby="journey"]', 'desktop-dark-journey.png');
  await section(page, 'section[aria-labelledby="get-in-touch"]', 'desktop-dark-finale.png');

  await context.close();
}

/* Desktop, light: an independent identity, not an inversion. */
{
  const { context, page } = await open(browser, 'light', DESKTOP);

  await viewport(page, 'desktop-light-hero.png');
  await fullPage(page, 'desktop-light-full.png');
  await section(page, 'section[aria-labelledby="skills"]', 'desktop-light-skills.png');
  await section(page, 'section[aria-labelledby="get-in-touch"]', 'desktop-light-finale.png');

  await context.close();
}

/* Laptop: the size at which the work grid is tightest. */
{
  const { context, page } = await open(browser, 'dark', LAPTOP);

  await viewport(page, 'laptop-dark-hero.png');

  await context.close();
}

/* Tablet: the breakpoint between the two-column work grid and one column. */
{
  const { context, page } = await open(browser, 'dark', TABLET);

  await fullPage(page, 'tablet-dark-full.png');

  await context.close();
}

/* Mobile, both themes. */
{
  const { context, page } = await open(browser, 'dark', null, devices['iPhone 13']);

  await fullPage(page, 'mobile-dark-full.png');

  await context.close();
}

{
  const { context, page } = await open(browser, 'light', null, devices['iPhone 13']);

  await viewport(page, 'mobile-light-hero.png');

  await context.close();
}

await browser.close();
