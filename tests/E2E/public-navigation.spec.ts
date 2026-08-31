/**
 * The public shell: the pages anyone can reach, and the links between them.
 *
 * Everything below is addressed by role and accessible name. A visitor does not
 * find "Projects" by class name, and neither should the suite — a selector tied
 * to markup would keep passing through a restyle that broke the page for a
 * screen reader, which is precisely the regression worth catching.
 */
import { expect, test } from './support/test';

test.describe('public navigation', () => {
  test('the home page presents the profile and the primary navigation', async ({ page }) => {
    await page.goto('/fr');

    await expect(page).toHaveURL('/fr');
    await expect(page.getByRole('heading', { level: 1, name: 'Thibault Paul' })).toBeVisible();

    const navigation = page.getByRole('navigation', { name: 'Navigation principale' });
    await expect(navigation).toBeVisible();

    for (const label of ['Accueil', 'Projets', 'À propos', 'Contact']) {
      await expect(navigation.getByRole('link', { name: label, exact: true })).toBeVisible();
    }

    // The current section is stated to assistive technology, not merely
    // painted, and it is the server that states it.
    await expect(navigation.getByRole('link', { name: 'Accueil', exact: true })).toHaveAttribute(
      'aria-current',
      'page',
    );
  });

  test('every primary section is reachable by its own link', async ({ page }) => {
    await page.goto('/fr');

    const navigation = page.getByRole('navigation', { name: 'Navigation principale' });

    const journey = [
      { label: 'Projets', url: '/fr/projects', heading: 'Projets' },
      { label: 'À propos', url: '/fr/about', heading: 'À propos de Thibault Paul' },
      { label: 'Contact', url: '/fr/contact', heading: 'Contact' },
      { label: 'Accueil', url: '/fr', heading: 'Thibault Paul' },
    ];

    for (const step of journey) {
      await navigation.getByRole('link', { name: step.label, exact: true }).click();

      await expect(page).toHaveURL(step.url);
      await expect(page.getByRole('heading', { level: 1, name: step.heading })).toBeVisible();
      await expect(navigation.getByRole('link', { name: step.label, exact: true })).toHaveAttribute(
        'aria-current',
        'page',
      );
    }
  });

  test('the brand returns to the home page from anywhere', async ({ page }) => {
    await page.goto('/fr/contact');

    await page.getByRole('banner').getByRole('link', { name: 'Facet' }).click();

    await expect(page).toHaveURL('/fr');
    await expect(page.getByRole('heading', { level: 1, name: 'Thibault Paul' })).toBeVisible();
  });

  test('the skip link moves the keyboard to the main landmark', async ({ page }) => {
    await page.goto('/fr');

    // The link is off-screen until it has focus — that is its whole design —
    // so it is reached the way its only user reaches it: the first Tab stop.
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Aller au contenu' })).toBeFocused();

    await page.keyboard.press('Enter');

    await expect(page).toHaveURL('/fr#main');
    // The landmark carries tabindex="-1" precisely so the fragment can hand it
    // the keyboard; without that, the link moves the viewport and leaves focus
    // behind in the header.
    await expect(page.getByRole('main')).toBeFocused();
  });

  test('the about page states the profile the corpus documents', async ({ page }) => {
    await page.goto('/fr/about');

    await expect(
      page.getByRole('heading', { level: 1, name: 'À propos de Thibault Paul' }),
    ).toBeVisible();
    await expect(page.getByRole('main')).toContainText('Foreach Academy');
  });

  /*
   * An unknown *unprefixed* URL has no language of its own, so the refusal is
   * written in the language the visitor's own signals resolve to. Playwright's
   * contexts send `en-US`, so this one reads English; the French half of the
   * same rule — an unknown URL under `/fr/` — is asserted in projects.spec.ts,
   * and the negotiation itself in locale.spec.ts.
   */
  test('an unknown public URL is a 404 page and not a broken one', async ({ page }) => {
    const response = await page.goto('/no-such-page');

    expect(response?.status()).toBe(404);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible();

    // Still a working site: the shell, and the way back, are both present —
    // and the way back leads into the language the page was written in.
    await page.getByRole('main').getByRole('link', { name: 'Back to the home page' }).click();
    await expect(page).toHaveURL('/en');
  });
});

/**
 * The route list's presentation, which PORT-138 gave a mark of its own.
 *
 * The four links used to be four tinted pills — the default any framework
 * hands you, and the one part of the header that had not been looked at. They
 * are now marked by a tapered accent line drawn on `::after`, at three
 * strengths: absent at rest, partial on hover and focus, full on the route you
 * are on.
 *
 * These assertions are deliberately about *state*, not about pixels. What must
 * hold is that the three states are distinguishable, that the current route is
 * the strongest of them, and that none of it touches where the links go — a
 * restyle that changed a destination would be caught by the cases above, and a
 * restyle that made the current route indistinguishable is caught here.
 */
test.describe('the route list is marked, not tinted', () => {
  test('idle, hover, focus and the current route are four distinguishable states', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/fr');

    const navigation = page.getByRole('navigation', { name: 'Navigation principale' });
    const current = navigation.getByRole('link', { name: 'Accueil', exact: true });
    const idle = navigation.getByRole('link', { name: 'Projets', exact: true });

    const mark = (link: typeof idle): Promise<number> =>
      link.evaluate((node) => Number.parseFloat(getComputedStyle(node, '::after').opacity));

    // At rest the header is quiet: the word carries itself and nothing else.
    await expect.poll(() => mark(idle)).toBeLessThan(0.05);

    // The route you are on is stated at full strength...
    await expect.poll(() => mark(current)).toBeGreaterThan(0.9);

    // ...and in accent, which is a second, redundant encoding of the same fact.
    const colours = await Promise.all(
      [current, idle].map((link) => link.evaluate((node) => getComputedStyle(node).color)),
    );
    expect(colours[0]).not.toBe(colours[1]);

    // Hover is feedback, and it is weaker than the current route rather than
    // equal to it: two links must never look like the page you are on.
    await idle.hover();
    await expect.poll(() => mark(idle), { timeout: 2000 }).toBeGreaterThan(0.3);
    expect(await mark(idle)).toBeLessThan(await mark(current));

    await page.mouse.move(0, 0);
    await expect.poll(() => mark(idle), { timeout: 2000 }).toBeLessThan(0.05);

    // Keyboard focus says the same thing hover does, and adds the ring.
    await idle.focus();
    await expect(idle).toBeFocused();
    await expect.poll(() => mark(idle), { timeout: 2000 }).toBeGreaterThan(0.3);

    const outline = await idle.evaluate((node) => getComputedStyle(node).outlineWidth);
    expect(Number.parseFloat(outline)).toBeGreaterThan(0);

    // And the mark is decoration only: it is drawn on a pseudo-element, so the
    // link's own accessible name and destination are untouched.
    await expect(idle).toHaveAttribute('href', '/fr/projects');
    await idle.press('Enter');
    await expect(page).toHaveURL('/fr/projects');
    await expect(
      page.getByRole('navigation', { name: 'Navigation principale' }).getByRole('link', {
        name: 'Projets',
        exact: true,
      }),
    ).toHaveAttribute('aria-current', 'page');
  });

  test('reduced motion keeps the three states and drops the growth', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/fr');

    const navigation = page.getByRole('navigation', { name: 'Navigation principale' });
    const current = navigation.getByRole('link', { name: 'Accueil', exact: true });
    const idle = navigation.getByRole('link', { name: 'Projets', exact: true });

    const state = (link: typeof idle) =>
      link.evaluate((node) => {
        const style = getComputedStyle(node, '::after');

        return { opacity: Number.parseFloat(style.opacity), scale: style.scale };
      });

    // The line is drawn at full width in every state; only its presence
    // differs, so nothing has to travel for the states to be told apart.
    const at_rest = await state(idle);
    const marked = await state(current);

    expect(at_rest.opacity).toBeLessThan(0.05);
    expect(marked.opacity).toBeGreaterThan(0.9);
    expect(at_rest.scale).toBe(marked.scale);

    await idle.hover();
    await expect.poll(async () => (await state(idle)).opacity, { timeout: 2000 }).toBeGreaterThan(
      0.3,
    );
    expect((await state(idle)).scale).toBe(marked.scale);
  });

  test('the collapsed menu is unchanged and the controls never overlap it', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/fr');

    const menu = page.getByRole('button', { name: 'Menu' });
    await expect(menu).toBeVisible();
    await expect(menu).toHaveAttribute('aria-expanded', 'false');

    const theme = page.getByRole('button', { name: 'Thème sombre' });
    await expect(theme).toBeVisible();

    await menu.click();
    await expect(menu).toHaveAttribute('aria-expanded', 'true');

    const navigation = page.getByRole('navigation', { name: 'Navigation principale' });
    await expect(navigation).toBeVisible();

    for (const label of ['Accueil', 'Projets', 'À propos', 'Contact']) {
      await expect(navigation.getByRole('link', { name: label, exact: true })).toBeVisible();
    }

    // The mark stands down in the stacked list — a full-width line under a
    // block link is a border, not a mark — and the ground states carry it.
    const drawn = await navigation
      .getByRole('link', { name: 'Accueil', exact: true })
      .evaluate((node) => getComputedStyle(node, '::after').content);
    expect(drawn).toBe('none');

    await page.keyboard.press('Escape');
    await expect(menu).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toBeFocused();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);
  });
});
