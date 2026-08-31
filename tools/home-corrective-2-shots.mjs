/**
 * PORT-136 second corrective pass — visual evidence for the three findings the
 * second human review left open.
 *
 * Usage, against a server holding a production build:
 *
 *     node tools/home-corrective-2-shots.mjs docs/reports/PORT-136/corrective-2 http://127.0.0.1:8765
 *
 * A companion to `tools/home-corrective-shots.mjs` rather than a replacement:
 * that script photographed the first pass's four findings, this one photographs
 * only the three that survived it, at the sizes and in the states they were
 * found in.
 *
 * Two of them need states the byte-stable suite cannot produce.
 *
 * F1 is a *moving* artifact, so the skills frames are taken with motion left
 * on, at two points in the ribbon's travel, and one of them is taken again at
 * 4× against the band's left edge — the residual seam was a few levels of grey
 * wide, and a 1× frame of a whole section is not evidence of its absence. The
 * pill positions therefore differ from run to run. The surface behind them,
 * which is what these frames are evidence of, does not.
 *
 * F3 is an absence, so it is photographed as the bottom of the page rather
 * than as the footer: a frame of an element that is `display: none` would be a
 * frame of nothing, and the thing to see is that the document ends cleanly on
 * section 04 with no strip under it.
 */
import playwright from '@playwright/test';

const { chromium } = playwright;

const [, , out, base = 'http://127.0.0.1:8765'] = process.argv;

if (out === undefined) {
  console.error('usage: node tools/home-corrective-2-shots.mjs <output-directory> [base-url]');
  process.exit(2);
}

/* The three widths the light ribbon was asked to be checked at. */
const WIDE = { width: 1920, height: 1000 };
const DESKTOP = { width: 1512, height: 950 };
const LAPTOP = { width: 1280, height: 900 };

/* The size the review was carried out at. */
const IPHONE_14 = {
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 3,
  isMobile: true,
  hasTouch: true,
  userAgent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'
    + ' (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
};

/**
 * Opens the home page in one theme, at one size.
 *
 * The theme is stamped into `localStorage` before the first paint as well as
 * emulated, so a frame is never caught mid-transition and never depends on
 * which of the two the runtime happened to resolve first.
 */
async function open(browser, theme, options) {
  const context = await browser.newContext({ ...options, colorScheme: theme });
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

/** One section, framed by its own landmark rather than by a scroll offset. */
async function section(page, selector, file) {
  const target = page.locator(selector).first();

  await target.scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  await target.screenshot({ path: `${out}/${file}` });

  console.log(file);
}

async function shot(page, file, clip) {
  await page.screenshot({ path: `${out}/${file}`, ...(clip ? { clip } : {}) });
  console.log(file);
}

const browser = await chromium.launch();

/*
 * F1 — the light band with the ribbons live, at three widths, and twice at the
 * width the review used so the two frames catch the pills in different places.
 */
for (const [name, viewport] of [['1920', WIDE], ['1512', DESKTOP], ['1280', LAPTOP]]) {
  const { context, page } = await open(browser, 'light', { viewport, deviceScaleFactor: 2 });

  await page.waitForSelector('[data-facet-ribbon="live"]');
  await section(page, 'section[aria-labelledby="skills"]', `desktop-${name}-light-skills-a.png`);
  await page.waitForTimeout(2600);
  await section(page, 'section[aria-labelledby="skills"]', `desktop-${name}-light-skills-b.png`);

  await context.close();
}

/*
 * F1, close up. The band's left edge at 4×, where the clipped shadow used to
 * terminate in a straight line under the pills.
 */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, { viewport: DESKTOP, deviceScaleFactor: 4 });

  await page.waitForSelector('[data-facet-ribbon="live"]');
  await page.locator('section[aria-labelledby="skills"]').scrollIntoViewIfNeeded();
  await page.waitForTimeout(400);

  const rect = await page.evaluate(
    () => document.querySelector('[data-facet-ribbon="live"]').getBoundingClientRect().toJSON(),
  );

  await shot(page, `desktop-${theme}-skills-zoom.png`, {
    x: 330,
    y: Math.round(rect.top) - 14,
    width: 300,
    height: Math.round(rect.height) + 28,
  });

  await context.close();
}

/* F1 dark, at the reviewed width, as the regression frame for the band. */
{
  const { context, page } = await open(browser, 'dark', { viewport: DESKTOP, deviceScaleFactor: 2 });

  await page.waitForSelector('[data-facet-ribbon="live"]');
  await section(page, 'section[aria-labelledby="skills"]', 'desktop-dark-skills.png');

  await context.close();
}

/* F2 — the finale, the seam it makes with the journey, and the desktop footer. */
{
  const { context, page } = await open(browser, 'light', { viewport: DESKTOP, deviceScaleFactor: 2 });

  await page.locator('section[aria-labelledby="get-in-touch"]').scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  await shot(page, 'desktop-light-journey-to-finale.png');
  await section(page, 'section[aria-labelledby="get-in-touch"]', 'desktop-light-finale.png');
  await section(page, 'footer', 'desktop-light-footer.png');

  await context.close();
}

{
  const { context, page } = await open(browser, 'dark', { viewport: DESKTOP, deviceScaleFactor: 2 });

  await section(page, 'section[aria-labelledby="get-in-touch"]', 'desktop-dark-finale.png');

  await context.close();
}

/*
 * F2 and F3 on the narrow page: the finale at the reviewer's size, and the
 * bottom of the document, which is where the footer used to be.
 */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, IPHONE_14);

  await section(page, 'section[aria-labelledby="get-in-touch"]', `mobile-${theme}-finale.png`);

  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
  await page.waitForTimeout(300);
  await shot(page, `mobile-${theme}-page-end.png`);

  await context.close();
}

await browser.close();
