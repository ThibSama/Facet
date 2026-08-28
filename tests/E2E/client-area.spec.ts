/**
 * The client shell.
 *
 * There is no client feature yet, and the page says so rather than pretending
 * otherwise — so what is asserted here is what the shell actually promises:
 * it names the account it belongs to, and it can be left.
 */
import { CLIENT } from './support/seed';
import { signIn, signOut } from './support/actions';
import { expect, test } from './support/test';

test.describe('client area', () => {
  test('the shell identifies the account and can be signed out of', async ({ page }) => {
    await signIn(page, CLIENT.email, CLIENT.password, '/client');

    await expect(page.getByRole('heading', { level: 1, name: 'Client area' })).toBeVisible();
    await expect(page.getByRole('main')).toContainText(
      'This private area identifies your account. No client feature has been delivered here yet.',
    );
    await expect(page.getByRole('main')).toContainText(`Signed in as ${CLIENT.email}.`);

    await signOut(page);

    await page.goto('/client');
    await expect(page).toHaveURL('/login');
  });
});
