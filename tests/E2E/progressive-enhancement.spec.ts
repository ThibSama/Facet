/**
 * The two enhanced controls, exercised in a real browser.
 *
 * This is where the three engines are most likely to actually differ. The rest
 * of the site is HTML the server composed and every browser renders the same
 * markup; the collapse and the theme switch depend on `matchMedia`,
 * `localStorage`, the `hidden` property and a media-query listener, and those
 * are implementation surface.
 *
 * The no-JavaScript contract itself is asserted where it belongs — in the
 * PHPUnit rendering gates, against the served document, which is a stronger and
 * cheaper check than any browser can make. What is asserted here is the other
 * half: that the enhancement, once running, does what it claims.
 */
import { expect, test } from './support/test';

const NARROW = { width: 420, height: 900 };

test.describe('progressive enhancement', () => {
  test('the served document offers a plain navigation and hides both controls', async ({ page }) => {
    // Read the markup the server actually sent, before any script has run.
    const response = await page.request.get('/');
    const html = await response.text();

    expect(html).toContain('<button');
    // Both enhanced controls ship hidden: a control that cannot do anything yet
    // is never presented.
    expect(html.match(/hidden\n/g)?.length ?? 0).toBeGreaterThanOrEqual(2);
    // The navigation itself is complete and unconditional in that same markup.
    for (const label of ['Home', 'Projects', 'About', 'Contact']) {
      expect(html).toContain(`>${label}</a>`);
    }
  });

  test('the theme control appears, switches the document, and is remembered', async ({ page }) => {
    await page.goto('/');

    const toggle = page.getByRole('button', { name: 'Dark theme' });
    await expect(toggle).toBeVisible();

    await toggle.click();

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');

    // Stored browser-side and stamped before the first paint, so the choice
    // survives a fresh document rather than being re-derived from the system.
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.getByRole('button', { name: 'Dark theme' })).toHaveAttribute('aria-pressed', 'true');

    // And it is reversible.
    await page.getByRole('button', { name: 'Dark theme' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  });

  test('the navigation collapses on a narrow viewport and is opened by its own button', async ({ page }) => {
    await page.setViewportSize(NARROW);
    await page.goto('/');

    const navigation = page.getByRole('navigation', { name: 'Primary' });
    const toggle = page.getByRole('button', { name: 'Menu' });

    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(navigation).toBeHidden();

    await toggle.click();

    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(navigation).toBeVisible();

    // A disclosure, not a dialog: Escape closes it and hands focus back.
    await page.keyboard.press('Escape');

    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(navigation).toBeHidden();
    await expect(toggle).toBeFocused();
  });

  test('above the breakpoint the collapse is gone and the list is always shown', async ({ page }) => {
    await page.setViewportSize(NARROW);
    await page.goto('/');

    const navigation = page.getByRole('navigation', { name: 'Primary' });
    await expect(navigation).toBeHidden();

    await page.setViewportSize({ width: 1280, height: 900 });

    await expect(navigation).toBeVisible();
    await expect(page.getByRole('button', { name: 'Menu' })).toBeHidden();

    // Addressed by attribute rather than by role, and only here: above the
    // breakpoint the stylesheet removes the button from the page, so it has no
    // accessible role left to look it up by. What is still worth asserting is
    // that the state it reports stays truthful rather than describing a
    // collapse nobody can reach.
    await expect(page.locator('[data-facet-nav-toggle]')).toHaveAttribute('aria-expanded', 'true');
  });

  test('a narrow visitor can still reach every section through the collapse', async ({ page }) => {
    await page.setViewportSize(NARROW);
    await page.goto('/');

    await page.getByRole('button', { name: 'Menu' }).click();
    await page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Contact', exact: true }).click();

    await expect(page).toHaveURL('/contact');
    await expect(page.getByRole('heading', { level: 1, name: 'Contact' })).toBeVisible();
  });
});
