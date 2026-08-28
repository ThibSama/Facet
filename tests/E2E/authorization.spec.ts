/**
 * Who may reach what, asserted through the front door.
 *
 * The truth table itself is a unit test — it is a pure function and belongs
 * there. What only a browser can show is that the table is actually applied on
 * the way in: that an anonymous request for a private URL never renders the
 * page, and that the refusal an administrator meets in the client area is the
 * same refusal a client meets in the admin area. The symmetry is the point;
 * a role hierarchy that quietly let an admin into the client area would still
 * pass every test that only checked the client's side.
 */
import { ADMIN, CLIENT } from './support/seed';
import { signIn } from './support/actions';
import { expect, test } from './support/test';

const PROTECTED = ['/admin', '/admin/messages', '/client'];

test.describe('authorisation', () => {
  for (const path of PROTECTED) {
    test(`an anonymous visitor asking for ${path} is sent to sign in`, async ({ page }) => {
      await page.goto(path);

      await expect(page).toHaveURL('/login');
      await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();

      // Sent somewhere useful, and nothing of the private page leaked on the way.
      await expect(page.getByRole('main')).not.toContainText('Signed in as');
    });
  }

  test('an administrator is refused the client area', async ({ page }) => {
    await signIn(page, ADMIN.email, ADMIN.password, '/admin');

    const response = await page.goto('/client');

    expect(response?.status()).toBe(403);
    await expect(page).toHaveURL('/client');
    await expect(page.getByRole('heading', { level: 1, name: 'Not available' })).toBeVisible();
    await expect(page.getByRole('main')).not.toContainText('Client area');
  });

  for (const path of ['/admin', '/admin/messages']) {
    test(`a client is refused ${path}`, async ({ page }) => {
      await signIn(page, CLIENT.email, CLIENT.password, '/client');

      const response = await page.goto(path);

      expect(response?.status()).toBe(403);
      await expect(page).toHaveURL(path);
      await expect(page.getByRole('heading', { level: 1, name: 'Not available' })).toBeVisible();
      await expect(page.getByRole('main')).not.toContainText('Contact messages');
    });
  }

  test('a private mutation without this session’s token is refused', async ({ page }) => {
    await signIn(page, ADMIN.email, ADMIN.password, '/admin');

    await page.locator('input[name="_token"]').evaluate((input: HTMLInputElement) => {
      input.value = 'not-this-session-token';
    });

    const response = page.waitForResponse(
      (candidate) => candidate.request().isNavigationRequest() && candidate.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Sign out' }).click();

    expect((await response).status()).toBe(403);
    await expect(page.getByRole('heading', { level: 1, name: 'Not available' })).toBeVisible();

    // Refused, and therefore still signed in — the failed logout did nothing.
    await page.goto('/admin');
    await expect(page).toHaveURL('/admin');
    await expect(page.getByRole('main')).toContainText(`Signed in as ${ADMIN.email}.`);
  });
});
