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
    await page.goto('/');

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Thibault Paul' })).toBeVisible();

    const navigation = page.getByRole('navigation', { name: 'Primary' });
    await expect(navigation).toBeVisible();

    for (const label of ['Home', 'Projects', 'About', 'Contact']) {
      await expect(navigation.getByRole('link', { name: label, exact: true })).toBeVisible();
    }

    // The current section is stated to assistive technology, not merely
    // painted, and it is the server that states it.
    await expect(navigation.getByRole('link', { name: 'Home', exact: true })).toHaveAttribute(
      'aria-current',
      'page',
    );
  });

  test('every primary section is reachable by its own link', async ({ page }) => {
    await page.goto('/');

    const navigation = page.getByRole('navigation', { name: 'Primary' });

    const journey = [
      { label: 'Projects', url: '/projects', heading: 'Projects' },
      { label: 'About', url: '/about', heading: 'About Thibault Paul' },
      { label: 'Contact', url: '/contact', heading: 'Contact' },
      { label: 'Home', url: '/', heading: 'Thibault Paul' },
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
    await page.goto('/contact');

    await page.getByRole('banner').getByRole('link', { name: 'Facet' }).click();

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { level: 1, name: 'Thibault Paul' })).toBeVisible();
  });

  test('the skip link moves the keyboard to the main landmark', async ({ page }) => {
    await page.goto('/');

    // The link is off-screen until it has focus — that is its whole design —
    // so it is reached the way its only user reaches it: the first Tab stop.
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();

    await page.keyboard.press('Enter');

    await expect(page).toHaveURL('/#main');
    // The landmark carries tabindex="-1" precisely so the fragment can hand it
    // the keyboard; without that, the link moves the viewport and leaves focus
    // behind in the header.
    await expect(page.getByRole('main')).toBeFocused();
  });

  test('the about page states the profile the corpus documents', async ({ page }) => {
    await page.goto('/about');

    await expect(page.getByRole('heading', { level: 1, name: 'About Thibault Paul' })).toBeVisible();
    await expect(page.getByRole('main')).toContainText('Foreach Academy');
  });

  test('an unknown public URL is a 404 page and not a broken one', async ({ page }) => {
    const response = await page.goto('/no-such-page');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible();

    // Still a working site: the shell, and the way back, are both present.
    await page.getByRole('main').getByRole('link', { name: 'Back to the home page' }).click();
    await expect(page).toHaveURL('/');
  });
});
