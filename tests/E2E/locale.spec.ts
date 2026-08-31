/**
 * PORT-137 — the public site in two languages, driven by a real browser.
 *
 * What is proved here is what only a browser can prove: that following the
 * switch really navigates, that a preference set by one visit is sent back by
 * the browser on the next, that the header holds four controls at 320px without
 * anything overlapping, and that the whole of it works with JavaScript disabled.
 *
 * Everything is addressed by role and accessible name. A language switch found
 * by class name would keep passing through a change that left it unreachable by
 * keyboard or unannounced by a screen reader, which is exactly the regression
 * worth catching.
 */
import { expect, test } from './support/test';

const PAGES = [
  { fr: '/fr', en: '/en', frHeading: 'Thibault Paul', enHeading: 'Thibault Paul' },
  { fr: '/fr/projects', en: '/en/projects', frHeading: 'Projets', enHeading: 'Projects' },
  {
    fr: '/fr/about',
    en: '/en/about',
    frHeading: 'À propos de Thibault Paul',
    enHeading: 'About Thibault Paul',
  },
  { fr: '/fr/contact', en: '/en/contact', frHeading: 'Contact', enHeading: 'Contact' },
];

test.describe('canonical locale URLs', () => {
  // A French-speaking browser, so that an unprefixed refusal below is answered
  // in French and the assertion says which language it expected.
  test.use({ locale: 'fr-FR' });

  test('every public page exists in both languages, server-rendered', async ({ page }) => {
    for (const entry of PAGES) {
      const french = await page.goto(entry.fr);

      expect(french?.status(), `GET ${entry.fr}`).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
      await expect(page.getByRole('heading', { level: 1, name: entry.frHeading })).toBeVisible();

      const english = await page.goto(entry.en);

      expect(english?.status(), `GET ${entry.en}`).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', 'en');
      await expect(page.getByRole('heading', { level: 1, name: entry.enHeading })).toBeVisible();
    }
  });

  /**
   * The strongest statement a visitor can make about language is the URL they
   * opened. It has to beat everything else, or a link shared between two people
   * means two different things.
   */
  test('an explicit locale URL wins over a remembered preference', async ({ page, context }) => {
    // The preference is established the way a visitor establishes one — by
    // reading a page in that language — rather than by writing a cookie the
    // application never issued.
    await page.goto('/fr');

    const cookies = await context.cookies();
    expect(cookies.find((cookie) => cookie.name === 'facet_locale')?.value).toBe('fr');

    await page.goto('/en/about');

    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { level: 1, name: 'About Thibault Paul' })).toBeVisible();
  });

  test('an unsupported language is a 404 and is never repaired into French', async ({ page }) => {
    for (const path of ['/de', '/de/projects', '/es/about']) {
      const response = await page.goto(path);

      expect(response?.status(), `GET ${path}`).toBe(404);
      await expect(page.getByRole('heading', { level: 1, name: 'Page introuvable' })).toBeVisible();
    }
  });
});

test.describe('the language switch', () => {
  // A French-speaking browser throughout, so that "the site is in French" is
  // never something the header happened to agree with by accident.
  test.use({ locale: 'fr-FR' });

  test('it is two links, and it lands on the same page in the other language', async ({ page }) => {
    for (const entry of PAGES) {
      await page.goto(entry.fr);

      const languages = page.getByRole('navigation', { name: 'Langue' });
      await expect(languages).toBeVisible();

      const english = languages.getByRole('link', { name: /English/ });
      await expect(english).toHaveAttribute('href', entry.en);

      await english.click();

      await expect(page).toHaveURL(entry.en);
      await expect(page.locator('html')).toHaveAttribute('lang', 'en');
      await expect(page.getByRole('heading', { level: 1, name: entry.enHeading })).toBeVisible();

      // And back, which is the half that proves the pairing is symmetrical
      // rather than a one-way door into English. The landmark is named in the
      // language of the page it is on, so coming back is asked for in English.
      await page
        .getByRole('navigation', { name: 'Language' })
        .getByRole('link', { name: /Français/ })
        .click();

      await expect(page).toHaveURL(entry.fr);
      await expect(page.getByRole('heading', { level: 1, name: entry.frHeading })).toBeVisible();
    }
  });

  test('the language in effect is stated, not merely painted', async ({ page }) => {
    await page.goto('/en/projects');

    const languages = page.getByRole('navigation', { name: 'Language' });

    await expect(languages.getByRole('link', { name: /English/ })).toHaveAttribute(
      'aria-current',
      'true',
    );
    await expect(languages.getByRole('link', { name: /Français/ })).not.toHaveAttribute(
      'aria-current',
      'true',
    );
  });

  test('it is reachable and operable from the keyboard, with a visible ring', async ({ page }) => {
    await page.goto('/fr');

    const english = page.getByRole('navigation', { name: 'Langue' }).getByRole('link', {
      name: /English/,
    });

    await english.focus();
    await expect(english).toBeFocused();

    const outline = await english.evaluate((node) => getComputedStyle(node).outlineWidth);
    expect(Number.parseFloat(outline)).toBeGreaterThan(0);

    await english.press('Enter');
    await expect(page).toHaveURL('/en');
  });

  /**
   * The preference follows the visitor: choosing English once means the
   * unprefixed entry URLs lead to English afterwards.
   */
  test('choosing a language is remembered for the unprefixed entry URLs', async ({ page }) => {
    // The browser speaks French (see `test.use` above), so what the unprefixed
    // URLs follow below is the remembered choice and not the header agreeing
    // with it by chance.
    await page.goto('/fr');
    await page.getByRole('navigation', { name: 'Langue' }).getByRole('link', { name: /English/ }).click();
    await expect(page).toHaveURL('/en');

    await page.goto('/');
    await expect(page).toHaveURL('/en');

    await page.goto('/projects');
    await expect(page).toHaveURL('/en/projects');

    // And back the other way, so the memory is a preference and not a latch.
    await page.getByRole('navigation', { name: 'Language' }).getByRole('link', { name: /Français/ }).click();
    await expect(page).toHaveURL('/fr/projects');

    await page.goto('/about');
    await expect(page).toHaveURL('/fr/about');
  });
});

/**
 * The unprefixed URLs, and the one signal they are allowed to negotiate on.
 *
 * The suite pins `en-US` in `playwright.config.ts` so that every other spec is
 * deterministic; these two blocks are where that is deliberately overridden, so
 * that "the browser's own language decides" is proved in both directions by a
 * real browser sending a real header rather than asserted about a parser.
 */
test.describe('unprefixed entry URLs — a French browser', () => {
  test.use({ locale: 'fr-FR' });

  test('they redirect into French', async ({ page }) => {
    for (const [entry, expected] of [
      ['/', '/fr'],
      ['/projects', '/fr/projects'],
      ['/about', '/fr/about'],
      ['/contact', '/fr/contact'],
      ['/projects/kushim', '/fr/projects/kushim'],
    ]) {
      const response = await page.goto(entry);

      expect(response?.status(), `GET ${entry}`).toBe(200);
      await expect(page).toHaveURL(expected);
      await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    }
  });
});

test.describe('unprefixed entry URLs — an English browser', () => {
  test.use({ locale: 'en-GB' });

  test('they redirect into English', async ({ page }) => {
    for (const [entry, expected] of [
      ['/', '/en'],
      ['/projects', '/en/projects'],
      ['/about', '/en/about'],
      ['/contact', '/en/contact'],
      ['/projects/kushim', '/en/projects/kushim'],
    ]) {
      const response = await page.goto(entry);

      expect(response?.status(), `GET ${entry}`).toBe(200);
      await expect(page).toHaveURL(expected);
      await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    }
  });
});

test.describe('unprefixed entry URLs — a browser speaking neither', () => {
  test.use({ locale: 'de-DE' });

  test('they fall back to French rather than to nothing', async ({ page }) => {
    const response = await page.goto('/projects');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL('/fr/projects');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });
});

test.describe('the no-JavaScript contract', () => {
  test.use({ javaScriptEnabled: false });

  test('both languages are complete, and switching between them works', async ({ page }) => {
    await page.goto('/en');

    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { level: 1, name: 'Thibault Paul' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Selected work' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Journey' })).toBeVisible();

    // The switch is the one header control that needs no script at all, so it
    // is fully operable here.
    const languages = page.getByRole('navigation', { name: 'Language' });
    await expect(languages).toBeVisible();

    await languages.getByRole('link', { name: /Français/ }).click();

    await expect(page).toHaveURL('/fr');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await expect(page.getByRole('heading', { level: 2, name: 'Projets sélectionnés' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Parcours' })).toBeVisible();

    // The two enhanced controls stay hidden, and the navigation stays open.
    await expect(page.getByRole('button', { name: 'Menu' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Thème sombre' })).toBeHidden();
    await expect(page.getByRole('navigation', { name: 'Navigation principale' })).toBeVisible();
  });

  test('the contact form is usable and answers in the language it was served in', async ({ page }) => {
    await page.goto('/en/contact');

    /*
     * Whitespace, not emptiness, and a well-formed address.
     *
     * With no JavaScript the browser's *native* constraints are the only thing
     * in front of the server, and they are real: `required` would stop an empty
     * field and `type="email"` would stop `not-an-address`, so neither would
     * ever reach the validator. A name of spaces satisfies `required` and is
     * trimmed away on the server, which is exactly the case worth proving —
     * the server decides, and it says so in the language of the page.
     */
    await page.getByLabel('Name', { exact: true }).fill('   ');
    await page.getByLabel('Email').fill('visitor@example.test');
    await page.getByLabel('Subject').fill('A subject');
    await page.getByLabel('Message', { exact: true }).fill('A message.');

    await page.getByRole('button', { name: 'Send message' }).click();

    await expect(page).toHaveURL('/en/contact');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('main')).toContainText(
      'Your message was not sent. Please correct the fields marked below.',
    );
    await expect(page.getByText('Please give a name I can address a reply to.')).toBeVisible();

    // And what the visitor typed comes back, so a refusal is corrected rather
    // than retyped — in English, on an English page.
    await expect(page.getByLabel('Subject')).toHaveValue('A subject');
  });
});

test.describe('SEO in both languages', () => {
  test('every page is canonical to itself and advertises both alternates', async ({ page }) => {
    for (const entry of PAGES) {
      for (const [path, other] of [
        [entry.fr, entry.en],
        [entry.en, entry.fr],
      ]) {
        await page.goto(path);

        const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
        expect(canonical, `${path} canonical`).toContain(path);

        const alternates = await page
          .locator('link[rel="alternate"]')
          .evaluateAll((links) =>
            links.map((link) => ({
              hreflang: link.getAttribute('hreflang'),
              href: link.getAttribute('href'),
            })),
          );

        expect(alternates.map((a) => a.hreflang).sort()).toEqual(['en', 'fr', 'x-default']);
        expect(alternates.find((a) => a.href?.endsWith(other))).toBeTruthy();

        // x-default is French, deterministically — never the page it is on.
        const fallback = alternates.find((a) => a.hreflang === 'x-default');
        expect(fallback?.href).toContain(entry.fr);
      }
    }
  });

  test('titles and descriptions are translated, not merely reused', async ({ page }) => {
    for (const entry of PAGES) {
      await page.goto(entry.fr);
      const frenchTitle = await page.title();
      const frenchDescription = await page
        .locator('meta[name="description"]')
        .getAttribute('content');

      await page.goto(entry.en);

      expect(await page.title(), `${entry.en} title`).not.toBe(frenchTitle);
      expect(
        await page.locator('meta[name="description"]').getAttribute('content'),
        `${entry.en} description`,
      ).not.toBe(frenchDescription);
    }
  });
});

test.describe('the shell at its narrowest, in both languages', () => {
  for (const width of [320, 390, 412]) {
    test(`the header holds every control at ${width}px without overlap or overflow`, async ({
      page,
    }) => {
      await page.setViewportSize({ width, height: 844 });

      for (const path of ['/fr', '/en']) {
        await page.goto(path);

        const measured = await page.evaluate(() => {
          const box = (selector: string): DOMRect | null => {
            const node = document.querySelector<HTMLElement>(selector);

            return node === null || node.hidden ? null : node.getBoundingClientRect();
          };

          const controls = {
            brand: box('[data-facet-brand]'),
            language: box('[data-facet-lang]'),
            menu: box('[data-facet-nav-toggle]'),
            theme: box('[data-facet-theme-toggle]'),
          };

          const names = Object.keys(controls) as Array<keyof typeof controls>;
          const collisions: string[] = [];

          for (let i = 0; i < names.length; i += 1) {
            for (let j = i + 1; j < names.length; j += 1) {
              const a = controls[names[i]];
              const b = controls[names[j]];

              if (a === null || b === null) {
                continue;
              }

              if (
                a.x < b.x + b.width &&
                b.x < a.x + a.width &&
                a.y < b.y + b.height &&
                b.y < a.y + a.height
              ) {
                collisions.push(`${names[i]}×${names[j]}`);
              }
            }
          }

          const row = document.querySelector('.facet-header__inner');

          return {
            collisions,
            headerOverflow:
              row !== null && row.scrollWidth > document.documentElement.clientWidth + 1,
            languageVisible: controls.language !== null,
            targets: Array.from(document.querySelectorAll('[data-facet-lang] a')).map((link) => {
              const rect = link.getBoundingClientRect();

              return { width: rect.width, height: rect.height };
            }),
          };
        });

        expect(measured.languageVisible, `${path} @${width}`).toBe(true);
        expect(measured.collisions, `${path} @${width}`).toEqual([]);
        expect(measured.headerOverflow, `${path} @${width}`).toBe(false);

        for (const target of measured.targets) {
          expect(target.height, `${path} @${width}: touch target height`).toBeGreaterThanOrEqual(24);
          expect(target.width, `${path} @${width}: touch target width`).toBeGreaterThanOrEqual(24);
        }
      }
    });
  }
});
