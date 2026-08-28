/**
 * The suite's own foundations, checked rather than assumed.
 *
 * Two claims hold everything else up, and both are cheap to falsify and
 * expensive to be wrong about.
 *
 * The first is that the fixture and the assertions describe the same rows.
 * `support/seed.ts` restates in TypeScript what `fixtures/seed.php` writes, and
 * a restatement nothing checks is just a comment that compiles — so the values
 * are read back out of the fixture's own `--report` and compared.
 *
 * The second is that the reset actually resets. Isolation is not a property of
 * writing `beforeEach`; it is the property that a test which mutates the
 * database leaves nothing behind. So this test mutates it through the product's
 * own admin screen, resets, and reads the rows back.
 */
import { reportDatabase, resetDatabase } from './support/database';
import { ADMIN, CLIENT, MESSAGES } from './support/seed';
import { signIn } from './support/actions';
import { expect, test } from './support/test';

test.describe('fixture integrity', () => {
  test('the seeded state the assertions describe is the state the fixture writes', async () => {
    const report = reportDatabase();

    expect(report.schema).toBe('facet_test');
    expect(report.adminEmail).toBe(ADMIN.email);
    expect(report.clientEmail).toBe(CLIENT.email);
    expect(report.password).toBe(ADMIN.password);
    expect(report.password).toBe(CLIENT.password);

    expect(report.users).toEqual([
      { id: 1, email: ADMIN.email, role: 'admin', status: 'active' },
      { id: 2, email: CLIENT.email, role: 'client', status: 'active' },
    ]);

    expect(report.messages).toEqual(
      MESSAGES.map((message) => ({
        id: message.id,
        email: message.email,
        subject: message.subject,
        status: message.status,
      })),
    );
  });

  test('a reset undoes a real mutation, ids included', async ({ page }) => {
    const before = reportDatabase();

    // A mutation and an insert, both through the product rather than through
    // SQL: a reset that only undid what the fixture itself wrote would prove
    // nothing about a test that used the application.
    await page.goto('/contact');
    await page.getByLabel('Name').fill('Fixture Integrity');
    await page.getByLabel('Email').fill('integrity@example.test');
    await page.getByLabel('Subject').fill('Written to be undone');
    await page.getByLabel('Message').fill('This row must not survive the reset.');
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect(page.getByRole('status')).toBeVisible();

    await signIn(page, ADMIN.email, ADMIN.password, '/admin');
    await page.goto(`/admin/messages?id=${MESSAGES[0].id}`);
    await page.getByLabel('Status').selectOption('archived');
    await page.getByRole('button', { name: 'Update status' }).click();
    await expect(page.getByRole('article').getByLabel('Status')).toHaveValue('archived');

    const dirty = reportDatabase();
    expect(dirty.messages).toHaveLength(MESSAGES.length + 1);
    expect(dirty.messages[0].status).toBe('archived');

    resetDatabase();

    // Identical, and identical down to the ids: TRUNCATE resets AUTO_INCREMENT,
    // so the next test's `?id=1` is the same message this test's was.
    expect(reportDatabase()).toEqual(before);
  });
});
