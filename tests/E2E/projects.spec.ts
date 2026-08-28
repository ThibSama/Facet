/**
 * The project catalogue and its case studies.
 *
 * The corpus is the authority on what exists, so the index is asserted against
 * the corpus rather than against a hand-written list that would go stale the
 * day a project is added — but the *shape* of each entry (a real link, to a
 * canonical slug URL, carrying the project's name) is asserted exactly.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { ROOT } from './support/environment';
import { expect, test } from './support/test';

interface CorpusProject {
  slug: string;
  name: string;
  summary: string;
}

const projects: CorpusProject[] = (
  JSON.parse(readFileSync(resolve(ROOT, 'content/projects.json'), 'utf8')) as { projects: CorpusProject[] }
).projects;

test.describe('projects', () => {
  test('the index lists every documented project, each linking to its case study', async ({ page }) => {
    await page.goto('/projects');

    await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();

    expect(projects.length).toBeGreaterThan(0);

    for (const project of projects) {
      const link = page.getByRole('link', { name: project.name, exact: true });

      await expect(link).toBeVisible();
      await expect(link).toHaveAttribute('href', `/projects/${project.slug}`);
    }

    // Each project is announced once, as its own region, rather than as a
    // paragraph a screen reader has to infer a boundary for.
    await expect(page.getByRole('article')).toHaveCount(projects.length);
  });

  test('a project reached from the index shows its own case study', async ({ page }) => {
    const first = projects[0];

    await page.goto('/projects');
    await page.getByRole('link', { name: first.name, exact: true }).click();

    await expect(page).toHaveURL(`/projects/${first.slug}`);
    await expect(page.getByRole('heading', { level: 1, name: first.name })).toBeVisible();
    await expect(page.getByRole('main')).toContainText(first.summary);

    // Projects stays the current section on a detail URL: the navigation model
    // decides that once, and every page inherits it.
    await expect(
      page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Projects', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
  });

  test('every case study is reachable directly by its canonical URL', async ({ page }) => {
    for (const project of projects) {
      const response = await page.goto(`/projects/${project.slug}`);

      expect(response?.status(), `GET /projects/${project.slug}`).toBe(200);
      await expect(page.getByRole('heading', { level: 1, name: project.name })).toBeVisible();
    }
  });

  test('an undocumented slug is a 404 rather than an empty case study', async ({ page }) => {
    const response = await page.goto('/projects/no-such-project');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible();
  });

  test('a malformed slug is refused rather than repaired', async ({ page }) => {
    const response = await page.goto('/projects/Not%20A%20Slug');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible();
  });
});
