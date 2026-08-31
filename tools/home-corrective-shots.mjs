/**
 * PORT-136 corrective pass — visual evidence for the four review findings.
 *
 * Usage, against a server holding a production build:
 *
 *     node tools/home-corrective-shots.mjs docs/reports/PORT-136/corrective http://127.0.0.1:8765
 *
 * A companion to `tools/home-shots.mjs` rather than a replacement: that script
 * photographs the whole composition, this one photographs only what the four
 * findings were about, at the sizes they were found at.
 *
 * The one deliberate difference is motion. `home-shots.mjs` emulates reduced
 * motion for every frame, which is what makes its output byte-stable — and
 * which is also why it could never have shown the skills finding: under
 * reduced motion the ribbon's centre light is switched off entirely, so the
 * rail the review saw was invisible to it. The skills frames here are taken
 * with motion left on, against a live ribbon, because that is the state the
 * defect lived in. Their pill positions therefore differ from run to run; the
 * surface behind the pills, which is what they are evidence of, does not.
 */
import playwright from '@playwright/test';

const { chromium } = playwright;

const [, , out, base = 'http://127.0.0.1:8765'] = process.argv;

if (out === undefined) {
  console.error('usage: node tools/home-corrective-shots.mjs <output-directory> [base-url]');
  process.exit(2);
}

const DESKTOP = { width: 1512, height: 950 };

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

async function shot(page, file) {
  await page.screenshot({ path: `${out}/${file}` });
  console.log(file);
}

const browser = await chromium.launch();

/* F1 — the skills band at rest, in both themes, with the ribbons live. */
for (const theme of ['dark', 'light']) {
  const { context, page } = await open(browser, theme, { viewport: DESKTOP, deviceScaleFactor: 2 });

  await section(page, 'section[aria-labelledby="skills"]', `desktop-${theme}-skills.png`);

  await context.close();
}

/* F2 — the finale, and the seam it makes with the journey above it. */
{
  const { context, page } = await open(browser, 'light', { viewport: DESKTOP, deviceScaleFactor: 2 });

  await page.locator('section[aria-labelledby="get-in-touch"]').scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  await shot(page, 'desktop-light-journey-to-finale.png');
  await section(page, 'section[aria-labelledby="get-in-touch"]', 'desktop-light-finale.png');

  await context.close();
}

{
  const { context, page } = await open(browser, 'dark', { viewport: DESKTOP, deviceScaleFactor: 2 });

  await section(page, 'section[aria-labelledby="get-in-touch"]', 'desktop-dark-finale.png');

  await context.close();
}

/* F3 and F4 — the narrow header and the narrow footer, in both themes. */
for (const theme of ['light', 'dark']) {
  const { context, page } = await open(browser, theme, IPHONE_14);

  await shot(page, `mobile-${theme}-header.png`);

  await page.locator('footer').scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  await page.locator('footer').screenshot({ path: `${out}/mobile-${theme}-footer.png` });
  console.log(`mobile-${theme}-footer.png`);

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.getByRole('button', { name: 'Menu' }).click();
  await page.waitForTimeout(200);
  await shot(page, `mobile-${theme}-nav-open.png`);

  await context.close();
}

await browser.close();
