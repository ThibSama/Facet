/**
 * PORT-135 visual evidence — Satoshi Run, photographed.
 *
 * Usage, against a server holding a production build:
 *
 *     node tools/satoshi-run-shots.mjs docs/reports/PORT-135 http://127.0.0.1:8765
 *
 * Not a test and never a substitute for one: it drives the real built page on
 * the real server and photographs the game in the states a human has to
 * approve.
 *
 * Two things make the shots repeatable. The seed comes from `Date.now`, which
 * is pinned, so every run opens on the same lane. And the loop is stopped
 * before the shutter: the game already stops itself when the document is
 * hidden, so telling the page it is hidden freezes the lane on its last frame
 * and the picture is taken at the position it was synchronised to, rather than
 * a second of play later — which is what a 2880-pixel screenshot costs.
 */
import playwright from '@playwright/test';

const { chromium, devices } = playwright;

const [, , out, base = 'http://127.0.0.1:8765'] = process.argv;

if (out === undefined) {
  console.error('usage: node tools/satoshi-run-shots.mjs <output-directory> [base-url]');
  process.exit(2);
}

const BASE = base;
const OUT = out;

/* Well inside the frame, with the opening obstacle in shot and not yet met. */
const IN_FRAME = 42;
/* Committed to the jump that clears it. */
const COMMITTED = 58;

const pin = () => {
  const fixed = 1_756_000_000_000;
  Date.now = () => fixed;
};

async function open(browser, theme, device) {
  const context = await browser.newContext(device);
  const page = await context.newPage();

  await page.addInitScript(pin);
  await page.addInitScript((value) => {
    try {
      window.localStorage.setItem('facet.theme', value);
    } catch {
      /* nothing to store into; the shot simply follows the system */
    }
  }, theme);

  await page.goto(BASE + '/');
  await page.waitForSelector('[data-facet-brand]');

  const box = await page.locator('[data-facet-brand]').boundingBox();

  for (let index = 0; index < 5; index += 1) {
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  }

  await page.waitForSelector('[data-facet-run][data-facet-run-state="running"]');

  return { context, page };
}

/** Waits for a *position* rather than a time: the score is distance × 1.6. */
async function atScore(page, score) {
  await page.locator('[data-facet-run-score]').evaluate(
    (node, target) =>
      new Promise((resolve) => {
        const tick = () => {
          if (Number(node.textContent) >= target) {
            resolve();

            return;
          }

          requestAnimationFrame(tick);
        };

        tick();
      }),
    score,
  );
}

/** Stops the lane where it stands, through the game's own visibility path. */
async function freeze(page) {
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.waitForTimeout(120);
}

const browser = await chromium.launch();
const desktop = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 };
const phone = devices['Pixel 7'];
const shots = [];

async function shoot(name, note, theme, device, act) {
  const { context, page } = await open(browser, theme, device);

  await act(page);
  await page.screenshot({ path: `${OUT}/${name}.png` });
  await context.close();
  shots.push(`${name}.png — ${note}`);
}

await shoot('desktop-dark-running', '1440×900, dark, mid-run', 'dark', desktop, async (page) => {
  await atScore(page, IN_FRAME);
  await freeze(page);
});

await shoot('desktop-dark-jump', '1440×900, dark, clearing the opening obstacle', 'dark', desktop, async (page) => {
  await atScore(page, COMMITTED);
  await page.keyboard.down('Space');
  await page.waitForTimeout(200);
  await freeze(page);
});

await shoot('desktop-dark-duck', '1440×900, dark, ducking', 'dark', desktop, async (page) => {
  await atScore(page, IN_FRAME);
  await page.keyboard.down('ArrowDown');
  await page.waitForTimeout(180);
  await freeze(page);
});

await shoot('desktop-dark-over', '1440×900, dark, game over', 'dark', desktop, async (page) => {
  await page.waitForSelector('[data-facet-run][data-facet-run-state="over"]', { timeout: 40_000 });
  await page.waitForTimeout(700);
});

await shoot('desktop-light-running', '1440×900, light, mid-run', 'light', desktop, async (page) => {
  await atScore(page, IN_FRAME);
  await freeze(page);
});

await shoot('desktop-light-jump', '1440×900, light, clearing the opening obstacle', 'light', desktop, async (page) => {
  await atScore(page, COMMITTED);
  await page.keyboard.down('Space');
  await page.waitForTimeout(200);
  await freeze(page);
});

await shoot('mobile-dark', '412×915 coarse pointer, dark, touch pads visible', 'dark', phone, async (page) => {
  await atScore(page, IN_FRAME);
  await freeze(page);
});

await shoot('mobile-light', '412×915 coarse pointer, light, touch pads visible', 'light', phone, async (page) => {
  await atScore(page, IN_FRAME);
  await freeze(page);
});

await browser.close();
console.log(shots.join('\n'));
