/**
 * The administrator's inbox: reading messages, and changing what state they are in.
 *
 * The seeded rows carry fixed ids because each test truncates before it runs,
 * so a URL like `/admin/messages?id=1` means the same thing in every test and
 * in every engine. The mutation is asserted twice over — on the detail the
 * redirect lands on, and on the row in the list — because a status that is only
 * echoed back by the form it was submitted from proves nothing was stored.
 */
import { ADMIN, MESSAGES } from './support/seed';
import { signIn } from './support/actions';
import { expect, test } from './support/test';

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN.email, ADMIN.password, '/admin');
});

test.describe('admin inbox', () => {
  test('the dashboard leads to the inbox', async ({ page }) => {
    await page.getByRole('link', { name: 'Open contact messages' }).click();

    await expect(page).toHaveURL('/admin/messages');
    await expect(page.getByRole('heading', { level: 1, name: 'Contact messages' })).toBeVisible();
  });

  test('the inbox lists every stored message with its sender and state', async ({ page }) => {
    await page.goto('/admin/messages');

    // Header row plus one row per seeded message.
    await expect(page.getByRole('row')).toHaveCount(MESSAGES.length + 1);

    for (const message of MESSAGES) {
      const row = page.getByRole('row').filter({ hasText: message.subject });

      await expect(row).toContainText(message.name);
      await expect(row).toContainText(message.status);
      await expect(row.getByRole('link', { name: message.subject })).toHaveAttribute(
        'href',
        `/admin/messages?id=${message.id}`,
      );
    }
  });

  test('a message opens on its own URL and shows what was written', async ({ page }) => {
    const message = MESSAGES[0];

    await page.goto('/admin/messages');
    await page.getByRole('link', { name: message.subject }).click();

    await expect(page).toHaveURL(`/admin/messages?id=${message.id}`);

    const article = page.getByRole('article');
    await expect(article.getByRole('heading', { level: 2, name: message.subject })).toBeVisible();
    await expect(article).toContainText(message.name);
    await expect(article).toContainText(message.email);
    await expect(article).toContainText('First seeded message.');

    // The control opens on the state the row is actually in.
    await expect(article.getByLabel('Status')).toHaveValue(message.status);
  });

  test('changing a status stores it, and the list agrees', async ({ page }) => {
    const message = MESSAGES[0];

    await page.goto(`/admin/messages?id=${message.id}`);
    await page.getByLabel('Status').selectOption('archived');
    await page.getByRole('button', { name: 'Update status' }).click();

    // Post/Redirect/Get back onto the same message.
    await expect(page).toHaveURL(`/admin/messages?id=${message.id}`);
    await expect(page.getByRole('article').getByLabel('Status')).toHaveValue('archived');

    const row = page.getByRole('row').filter({ hasText: message.subject });
    await expect(row).toContainText('archived');

    // And it survives a fresh read of the list, so it is a row and not a render.
    await page.goto('/admin/messages');
    await expect(page.getByRole('row').filter({ hasText: message.subject })).toContainText('archived');
  });

  test('the other messages are untouched by one status change', async ({ page }) => {
    await page.goto(`/admin/messages?id=${MESSAGES[0].id}`);
    await page.getByLabel('Status').selectOption('read');
    await page.getByRole('button', { name: 'Update status' }).click();
    await expect(page.getByRole('article').getByLabel('Status')).toHaveValue('read');

    for (const message of MESSAGES.slice(1)) {
      await expect(page.getByRole('row').filter({ hasText: message.subject })).toContainText(message.status);
    }
  });

  test('an id no message has is a 404', async ({ page }) => {
    const response = await page.goto('/admin/messages?id=99999');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible();
  });

  test('a malformed id is a 400 rather than a query', async ({ page }) => {
    const response = await page.goto('/admin/messages?id=not-a-number');

    expect(response?.status()).toBe(400);
    await expect(page.getByRole('heading', { level: 1, name: 'Bad request' })).toBeVisible();
  });
});
