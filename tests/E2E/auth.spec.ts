/**
 * Signing in, failing to sign in, and signing out.
 *
 * Where a successful sign-in lands is decided by the role on the row that was
 * read, so both roles are exercised: an admin and a client submit the same form
 * and arrive somewhere different. The refusal is asserted for its text as well
 * as its status, because saying one sentence for every kind of failure is the
 * property — a page that started distinguishing them would still be a 422.
 */
import { ADMIN, CLIENT } from './support/seed';
import { signIn, signOut } from './support/actions';
import { expect, test } from './support/test';

test.describe('authentication', () => {
  test('an administrator signs in and lands in the administration area', async ({ page }) => {
    await signIn(page, ADMIN.email, ADMIN.password, '/admin');

    await expect(page.getByRole('heading', { level: 1, name: 'Administration' })).toBeVisible();
    await expect(page.getByRole('main')).toContainText(`Signed in as ${ADMIN.email}.`);
  });

  test('a client signs in and lands in the client area', async ({ page }) => {
    await signIn(page, CLIENT.email, CLIENT.password, '/client');

    await expect(page.getByRole('heading', { level: 1, name: 'Client area' })).toBeVisible();
    await expect(page.getByRole('main')).toContainText(`Signed in as ${CLIENT.email}.`);
  });

  test('a wrong password is refused, and says nothing about why', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill('not-the-fixture-password');

    const response = page.waitForResponse(
      (candidate) => candidate.request().isNavigationRequest() && candidate.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Sign in' }).click();

    expect((await response).status()).toBe(422);

    await expect(page).toHaveURL('/login');
    await expect(page.getByRole('main')).toContainText(
      'Those details did not match an account that can sign in.',
    );

    // The address comes back so a typo can be corrected. The password never does.
    await expect(page.getByLabel('Email')).toHaveValue(ADMIN.email);
    await expect(page.getByLabel('Password')).toHaveValue('');
  });

  test('an unknown address is refused in exactly the same words', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('nobody@facet.test');
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL('/login');
    await expect(page.getByRole('main')).toContainText(
      'Those details did not match an account that can sign in.',
    );
  });

  test('signing out ends the session, and the private area is protected again', async ({ page }) => {
    await signIn(page, ADMIN.email, ADMIN.password, '/admin');
    await signOut(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Thibault Paul' })).toBeVisible();

    await page.goto('/admin');
    await expect(page).toHaveURL('/login');
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
  });

  test('someone already signed in is sent to their own area instead of the form', async ({ page }) => {
    await signIn(page, CLIENT.email, CLIENT.password, '/client');

    await page.goto('/login');

    await expect(page).toHaveURL('/client');
    await expect(page.getByRole('heading', { level: 1, name: 'Client area' })).toBeVisible();
  });
});
