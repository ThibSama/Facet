/**
 * The contact form: the one public page that writes to the database.
 *
 * All three cases go through real HTTP against the real handler — a valid
 * submission, a submission the server rejects, and a submission that cannot
 * prove it was composed on this page. None of them stubs the store: the
 * accepted message is later found in the admin inbox, which is the only proof
 * that "received and stored" is a statement about a row and not about a banner.
 */
import { ADMIN } from './support/seed';
import { signIn } from './support/actions';
import { expect, test } from './support/test';

const VALID = {
  name: 'Visitor Under Test',
  email: 'visitor@example.test',
  subject: 'A message composed end to end',
  message: 'This message was typed into a real browser and posted to a real server.',
};

async function fill(page: import('@playwright/test').Page, values: Partial<typeof VALID>): Promise<void> {
  const merged = { ...VALID, ...values };

  await page.getByLabel('Name').fill(merged.name);
  await page.getByLabel('Email').fill(merged.email);
  await page.getByLabel('Subject').fill(merged.subject);
  await page.getByLabel('Message').fill(merged.message);
}

test.describe('contact', () => {
  test('a valid submission is confirmed, and the confirmation is not repeatable', async ({ page }) => {
    await page.goto('/contact');
    await expect(page.getByRole('heading', { level: 1, name: 'Contact' })).toBeVisible();

    await fill(page, {});
    await page.getByRole('button', { name: 'Send message' }).click();

    // Post/Redirect/Get: the visitor ends on the form's own URL, by a GET.
    await expect(page).toHaveURL('/contact');
    await expect(page.getByRole('status')).toHaveText(
      'Thank you — your message has been received and stored on this site.',
    );

    // The form comes back empty, ready for a different message rather than
    // holding the one that was just sent.
    await expect(page.getByLabel('Subject')).toHaveValue('');

    // The flash is read once. Reloading the landing page must not re-announce
    // a receipt for a message that was already confirmed.
    await page.reload();
    await expect(page.getByRole('status')).toHaveCount(0);
  });

  test('an accepted message reaches the administrator inbox', async ({ page }) => {
    await page.goto('/contact');
    await fill(page, {});
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect(page.getByRole('status')).toBeVisible();

    await signIn(page, ADMIN.email, ADMIN.password, '/admin');
    await page.getByRole('link', { name: 'Open contact messages' }).click();

    await expect(page).toHaveURL('/admin/messages');

    const row = page.getByRole('row').filter({ hasText: VALID.subject });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText(VALID.name);
    await expect(row).toContainText('new');

    await page.getByRole('link', { name: VALID.subject }).click();

    await expect(page.getByRole('heading', { level: 2, name: VALID.subject })).toBeVisible();
    await expect(page.getByRole('article')).toContainText(VALID.message);
    await expect(page.getByRole('article')).toContainText(VALID.email);
  });

  test('a submission the server rejects comes back with the values and the reason', async ({ page }) => {
    await page.goto('/contact');

    // Whitespace satisfies the browser's `required`; the server trims, so this
    // reaches the real validator rather than being stopped at the control.
    await fill(page, { name: '   ' });

    const response = page.waitForResponse(
      (candidate) => candidate.request().isNavigationRequest() && candidate.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Send message' }).click();

    expect((await response).status()).toBe(422);

    await expect(page).toHaveURL('/contact');
    await expect(page.getByRole('main')).toContainText(
      'Your message was not sent. Please correct the fields marked below.',
    );
    await expect(page.getByText('Please give a name I can address a reply to.')).toBeVisible();

    // The rejected control says so, and the values the visitor typed survive.
    await expect(page.getByLabel('Name')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Subject')).toHaveValue(VALID.subject);
    await expect(page.getByLabel('Message')).toHaveValue(VALID.message);
  });

  test('a submission carrying the wrong token is refused outright', async ({ page }) => {
    await page.goto('/contact');
    await fill(page, {});

    // The token is replaced in the document the browser is about to post, so
    // what reaches the server is a genuine cross-site-shaped request: right
    // form, right fields, wrong proof of intent.
    await page.locator('input[name="_token"]').evaluate((input: HTMLInputElement) => {
      input.value = 'not-this-session-token';
    });

    const response = page.waitForResponse(
      (candidate) => candidate.request().isNavigationRequest() && candidate.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Send message' }).click();

    expect((await response).status()).toBe(403);

    await expect(page.getByRole('heading', { level: 1, name: 'Not available' })).toBeVisible();

    // Refused before anything was written: an administrator finds nothing.
    await signIn(page, ADMIN.email, ADMIN.password, '/admin');
    await page.goto('/admin/messages');
    await expect(page.getByRole('row').filter({ hasText: VALID.subject })).toHaveCount(0);
  });
});
