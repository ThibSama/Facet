/**
 * The suite's base test: a known database, a watched page, and nothing else.
 *
 * Two fixtures run for every test, automatically, because both are properties
 * of the suite rather than of any one case.
 *
 * **Isolation.** `database` returns the schema to its seeded state before the
 * test body runs. Playwright already gives each test its own browser context —
 * so its own cookie jar, and therefore its own PHP session — and the reset
 * supplies the other half: no test can observe a row another test wrote, and no
 * test depends on having run first. That is what makes the suite reorderable
 * and repeatable rather than merely passing today.
 *
 * **Silence.** `diagnostics` records what the page did wrong and fails the test
 * if it did anything: an uncaught exception, an unhandled promise rejection, a
 * console error, or a subresource the server refused.
 *
 * Two of those need explaining.
 *
 * Unhandled rejections are collected by an init script rather than read out of
 * console text, because the three engines neither phrase nor route them
 * identically, and a suite that only recognised Chromium's wording would be
 * silently blind on the other two.
 *
 * Failed subresources are watched directly, and the console's own
 * "Failed to load resource" line is dropped. That line is Chromium restating a
 * status code the network already reported — Firefox does not emit it at all —
 * so keeping it would mean a 404 page failed its own test in one engine and
 * passed in the others. Watching responses instead is both engine-independent
 * and stricter: a stylesheet, script or font the server refused is a defect
 * whatever the console decided to say about it.
 */
import { expect, test as base } from '@playwright/test';

import { resetDatabase } from './database';

interface Diagnostics {
  /** Console messages the page emitted at `error` level. */
  readonly consoleErrors: string[];
  /** Uncaught exceptions that reached the page. */
  readonly pageErrors: string[];
  /** Promise rejections nothing handled. */
  readonly unhandledRejections: string[];
  /** Subresources the server refused, or that never arrived. */
  readonly failedResources: string[];
}

declare global {
  interface Window {
    __facetUnhandledRejections?: string[];
  }
}

/**
 * Chromium's restatement of a network status the response watcher already has.
 * Dropped so the same 404 page is judged identically by all three engines.
 */
const RESOURCE_STATUS_NOISE = /^Failed to load resource/;

const test = base.extend<{ database: void; diagnostics: Diagnostics }>({
  database: [
    // eslint-disable-next-line no-empty-pattern
    async ({}, use) => {
      resetDatabase();
      await use();
    },
    { auto: true },
  ],

  diagnostics: [
    async ({ page }, use) => {
      const consoleErrors: string[] = [];
      const pageErrors: string[] = [];
      const failedResources: string[] = [];
      const unhandledRejections: string[] = [];

      await page.addInitScript(() => {
        window.__facetUnhandledRejections = [];
        window.addEventListener('unhandledrejection', (event) => {
          window.__facetUnhandledRejections?.push(String(event.reason));
        });
      });

      page.on('console', (message) => {
        if (message.type() !== 'error' || RESOURCE_STATUS_NOISE.test(message.text())) {
          return;
        }

        consoleErrors.push(`${message.location().url}: ${message.text()}`);
      });

      page.on('pageerror', (error) => {
        pageErrors.push(`${error.name}: ${error.message}`);
      });

      // A document's own status is the test's business — a 404 page is a
      // correct answer. Everything the document then pulls in is not: a
      // stylesheet, script or font the server refused is a broken page.
      page.on('response', (response) => {
        if (!response.request().isNavigationRequest() && response.status() >= 400) {
          failedResources.push(`${response.status()} ${response.url()}`);
        }
      });

      page.on('requestfailed', (request) => {
        if (!request.isNavigationRequest()) {
          failedResources.push(`${request.failure()?.errorText ?? 'failed'} ${request.url()}`);
        }
      });

      await use({ consoleErrors, pageErrors, unhandledRejections, failedResources });

      // Read whatever the last document accumulated. A page that navigated
      // away or was already closed simply has nothing to report.
      if (!page.isClosed()) {
        const collected = await page
          .evaluate(() => window.__facetUnhandledRejections ?? [])
          .catch((): string[] => []);

        unhandledRejections.push(...collected);
      }

      expect(pageErrors, 'the page threw an uncaught exception').toEqual([]);
      expect(unhandledRejections, 'a promise rejection went unhandled').toEqual([]);
      expect(consoleErrors, 'the page logged a console error').toEqual([]);
      expect(failedResources, 'the page requested something the server refused').toEqual([]);
    },
    { auto: true },
  ],
});

export { expect, test };
export type { Diagnostics };
