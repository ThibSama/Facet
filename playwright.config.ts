/**
 * The end-to-end suite's configuration.
 *
 * It boots the real entrypoint behind PHP's built-in server, in `production`,
 * against the disposable `facet_test` schema, and drives it with three engines.
 * Nothing here is conditional on the machine: the same command produces the
 * same suite, and a missing database or a missing browser stops the run rather
 * than quietly reducing it.
 *
 * Three settings are deliberate rather than default:
 *
 * - `workers: 1` and `fullyParallel: false`. One server and one schema are
 *   shared by the whole run, and each test truncates and re-seeds that schema
 *   before it starts. Running two tests at once would make them observe each
 *   other's rows, which is the exact property the suite is supposed to have.
 * - `retries: 0`, everywhere, CI included. A retry turns a flaky test into a
 *   passing one, and the gate this suite serves asks whether the suite is
 *   green without retries.
 * - `reuseExistingServer: false`. A server left over from an earlier run may
 *   hold a different build, a different environment or a different database;
 *   starting a fresh one is what makes a run mean what it says.
 */
import { defineConfig, devices } from '@playwright/test';

import { baseURL, port, serverEnvironment } from './tests/E2E/support/environment';

export default defineConfig({
  testDir: './tests/E2E',
  testMatch: '**/*.spec.ts',

  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: true,

  // `list` is what a person reads. A machine-readable run is asked for
  // explicitly, by setting PLAYWRIGHT_JSON_OUTPUT_NAME, so an ordinary run does
  // not scatter reports nobody reads.
  reporter: process.env.PLAYWRIGHT_JSON_OUTPUT_NAME === undefined ? [['list']] : [['list'], ['json']],
  outputDir: 'tests/E2E/.results/artifacts',

  globalSetup: './tests/E2E/support/global-setup.ts',

  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],

  webServer: {
    // The router script matters: without it the built-in server answers static
    // 404s for /projects/{slug} and the application is never asked. It is the
    // same invocation the PHPUnit smoke test and the Lighthouse gate used.
    command: `php -d variables_order=EGPCS -S 127.0.0.1:${port} -t public public/index.php`,
    url: baseURL,
    env: serverEnvironment,
    reuseExistingServer: false,
    // Both streams are kept. PHP's built-in server writes its access log to
    // stderr alongside every warning and fatal it raises, and a warning the
    // suite hid would be a defect nobody saw.
    stdout: 'pipe',
    stderr: 'pipe',
    timeout: 30_000,
  },
});
