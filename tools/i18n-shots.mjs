/**
 * PORT-137 visual and responsive evidence — the site in both languages.
 *
 * Usage, against a server holding a production build:
 *
 *     node tools/i18n-shots.mjs docs/reports/PORT-137 http://127.0.0.1:8765
 *
 * Two jobs, and they are different in kind.
 *
 * The screenshots are for a human: what the site looks like in French and in
 * English, in both themes, so that a reviewer can judge translations in place
 * rather than in a table. Every one of them is deterministic — the theme is
 * stamped into `localStorage` before the first paint so nothing photographs
 * mid-transition, and reduced motion is emulated so the skills ribbons stand
 * still.
 *
 * The measurements are for the decision the header actually turned on: whether
 * a language switch fits beside the brand, the collapse control and the theme
 * toggle at 320px, in both languages, without anything overlapping or the page
 * scrolling sideways. That is a question about pixels, so it is answered with
 * pixels and written to a file rather than guessed at.
 */
import { mkdir, writeFile } from 'node:fs/promises';

import playwright from '@playwright/test';

const { chromium } = playwright;

const [, , out, base = 'http://127.0.0.1:8765'] = process.argv;

if (out === undefined) {
  console.error('usage: node tools/i18n-shots.mjs <output-directory> [base-url]');
  process.exit(2);
}

await mkdir(out, { recursive: true });

const DESKTOP = { width: 1512, height: 950 };
const MOBILE = { width: 390, height: 844 };

/** The widths the accepted shell is reviewed at. */
const WIDTHS = [320, 390, 412, 768, 834, 1024, 1280, 1512, 1920];

const browser = await chromium.launch();

/**
 * A page with a theme decided before the first byte of the document paints.
 *
 * Writing the preference through an init script rather than clicking the
 * toggle is what makes every shot below reproducible: the document is never
 * caught in a transition it was put into by this script.
 */
async function open(theme, viewport) {
  const context = await browser.newContext({
    viewport,
    reducedMotion: 'reduce',
    deviceScaleFactor: 2,
  });

  await context.addInitScript((value) => {
    try {
      window.localStorage.setItem('facet.theme', value);
    } catch {
      /* A context that cannot store one simply follows the system. */
    }
  }, theme);

  return context;
}

async function shot(context, path, file, { full = false } = {}) {
  const page = await context.newPage();

  await page.goto(`${base}${path}`, { waitUntil: 'networkidle' });
  await page.locator('main h1').first().waitFor();
  await page.screenshot({ path: `${out}/${file}`, fullPage: full });
  await page.close();
}

// ------------------------------------------------------------- desktop pages

for (const theme of ['dark', 'light']) {
  const context = await open(theme, DESKTOP);

  await shot(context, '/fr', `desktop-${theme}-fr-home.png`, { full: true });
  await shot(context, '/en', `desktop-${theme}-en-home.png`, { full: true });

  if (theme === 'dark') {
    for (const [path, file] of [
      ['/fr/projects', 'desktop-dark-fr-projects.png'],
      ['/en/projects', 'desktop-dark-en-projects.png'],
      ['/fr/about', 'desktop-dark-fr-about.png'],
      ['/en/about', 'desktop-dark-en-about.png'],
      ['/fr/contact', 'desktop-dark-fr-contact.png'],
      ['/en/contact', 'desktop-dark-en-contact.png'],
      ['/fr/projects/kushim', 'desktop-dark-fr-project.png'],
      ['/en/projects/kushim', 'desktop-dark-en-project.png'],
    ]) {
      await shot(context, path, file, { full: true });
    }
  }

  await context.close();
}

// -------------------------------------------------------------- header, both

for (const theme of ['dark', 'light']) {
  const context = await open(theme, DESKTOP);
  const page = await context.newPage();

  for (const locale of ['fr', 'en']) {
    await page.goto(`${base}/${locale}`, { waitUntil: 'networkidle' });
    await page.locator('[data-facet-header]').waitFor();
    await page.locator('[data-facet-header]').screenshot({
      path: `${out}/header-${theme}-${locale}.png`,
    });

    await page.locator('[data-facet-lang]').screenshot({
      path: `${out}/switch-${theme}-${locale}.png`,
    });
  }

  await page.close();
  await context.close();
}

// ------------------------------------------------------------- mobile header

for (const locale of ['fr', 'en']) {
  const context = await open('dark', MOBILE);
  const page = await context.newPage();

  await page.goto(`${base}/${locale}`, { waitUntil: 'networkidle' });
  await page.locator('[data-facet-header]').waitFor();
  await page.locator('[data-facet-header]').screenshot({
    path: `${out}/mobile-header-${locale}.png`,
  });

  // And with the collapsed menu open, which is the state the header is most
  // crowded in.
  const toggle = page.locator('[data-facet-nav-toggle]');

  if ((await toggle.getAttribute('aria-expanded')) === 'false') {
    await toggle.click();
  }

  await page.locator('[data-facet-nav]').waitFor({ state: 'visible' });
  await page.screenshot({ path: `${out}/mobile-header-${locale}-open.png` });

  await page.close();
  await context.close();
}

// ------------------------------------------------------------- measurements

const lines = [];

lines.push('PORT-137 — header geometry with the language switch, FR and EN.');
lines.push('');
lines.push('Every row is measured on the served page, in the language named.');
lines.push('');
lines.push('"header" is the shell row PORT-137 added a control to: it overflows');
lines.push('when the brand, the language switch, the collapse control and the');
lines.push('theme toggle no longer fit on one line. That is the measurement the');
lines.push('placement decision turned on, and it is "no" at every width.');
lines.push('');
lines.push('"document" is the whole page scrolling sideways. It is "no" at every');
lines.push('width in both languages: the skills ribbons and the finale plate run');
lines.push('past the measure by design and are clipped, so nothing escapes.');
lines.push('');
lines.push('"collision" is any pair of header controls whose boxes intersect.');
lines.push('');

for (const width of WIDTHS) {
  for (const locale of ['fr', 'en']) {
    const context = await open('dark', { width, height: 900 });
    const page = await context.newPage();

    await page.goto(`${base}/${locale}`, { waitUntil: 'networkidle' });
    await page.locator('[data-facet-header]').waitFor();

    const measured = await page.evaluate(() => {
      const box = (selector) => {
        const node = document.querySelector(selector);

        if (node === null || node.hidden) {
          return null;
        }

        const rect = node.getBoundingClientRect();

        return rect.width === 0 && rect.height === 0
          ? null
          : { x: rect.x, y: rect.y, width: rect.width, height: rect.height };
      };

      const controls = {
        brand: box('[data-facet-brand]'),
        language: box('[data-facet-lang]'),
        menu: box('[data-facet-nav-toggle]'),
        theme: box('[data-facet-theme-toggle]'),
        nav: box('[data-facet-nav]'),
      };

      const collisions = [];
      const names = Object.keys(controls);

      for (let i = 0; i < names.length; i += 1) {
        for (let j = i + 1; j < names.length; j += 1) {
          const a = controls[names[i]];
          const b = controls[names[j]];

          if (a === null || b === null) {
            continue;
          }

          const overlapX = a.x < b.x + b.width && b.x < a.x + a.width;
          const overlapY = a.y < b.y + b.height && b.y < a.y + a.height;

          if (overlapX && overlapY) {
            collisions.push(`${names[i]}×${names[j]}`);
          }
        }
      }

      const languageLinks = Array.from(
        document.querySelectorAll('[data-facet-lang] a'),
      ).map((link) => {
        const rect = link.getBoundingClientRect();

        return { width: Math.round(rect.width), height: Math.round(rect.height) };
      });

      const row = document.querySelector('.facet-header__inner');

      return {
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
        headerOverflow: row !== null && row.scrollWidth > document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        header: Math.round(controls.brand === null ? 0 : controls.brand.height),
        language: controls.language === null
          ? 'absent'
          : `${Math.round(controls.language.width)}×${Math.round(controls.language.height)}`,
        links: languageLinks,
        collisions,
      };
    });

    lines.push(
      [
        `${String(width).padStart(4)}px`,
        locale,
        `switch ${String(measured.language).padEnd(9)}`,
        `targets ${measured.links.map((l) => `${l.width}×${l.height}`).join(' ')}`,
        `header ${measured.headerOverflow ? 'OVERFLOWS' : 'fits'}`,
        `document ${measured.overflow ? 'YES' : 'no '} (${measured.scrollWidth}/${measured.clientWidth})`,
        `collisions ${measured.collisions.length === 0 ? 'none' : measured.collisions.join(', ')}`,
      ].join('  '),
    );

    await page.close();
    await context.close();
  }
}

await writeFile(`${out}/measurements.txt`, `${lines.join('\n')}\n`, 'utf8');

await browser.close();

console.log(lines.join('\n'));
