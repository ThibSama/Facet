/**
 * The one place that decides where the suite's server and database live.
 *
 * Both halves are deliberately explicit. The database credentials come from
 * `FACET_TEST_DB_*` — the keys the PHPUnit database gates already use, and not
 * the application's own `DB_*` keys — so an E2E run can only ever reach the
 * disposable `facet_test` schema. The application is then booted with those
 * values *as* its `DB_*`, which is what makes the suite exercise the real
 * database code path rather than a stub.
 *
 * A missing credential is a hard stop, not a skip: the point of this suite is
 * that it either ran against a real server and a real database or it did not.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(import.meta.dirname, '../../..');

/** Read `.env.testing` the way the PHP side does: real environment wins. */
function fromDotEnvTesting(): Record<string, string> {
  const values: Record<string, string> = {};

  let contents: string;

  try {
    contents = readFileSync(resolve(ROOT, '.env.testing'), 'utf8');
  } catch {
    return values;
  }

  for (const line of contents.split(/\r?\n/)) {
    const trimmed = line.trim();

    if (trimmed === '' || trimmed.startsWith('#') || !trimmed.includes('=')) {
      continue;
    }

    const separator = trimmed.indexOf('=');
    const name = trimmed.slice(0, separator).trim();
    let value = trimmed.slice(separator + 1).trim();

    if (value.length >= 2 && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))) {
      value = value.slice(1, -1);
    }

    if (name !== '') {
      values[name] = value;
    }
  }

  return values;
}

const dotEnvTesting = fromDotEnvTesting();

function required(key: string): string {
  const value = process.env[key] ?? dotEnvTesting[key];

  if (value === undefined || value === '') {
    throw new Error(
      `${key} is not set. Export FACET_TEST_DB_DSN / FACET_TEST_DB_USER / ` +
        'FACET_TEST_DB_PASSWORD, or create .env.testing, before running the E2E suite. ' +
        'A missing database is a blocked run, not a passing one.',
    );
  }

  return value;
}

const dsn = required('FACET_TEST_DB_DSN');

if (!/(?:^|;)\s*dbname\s*=\s*facet_test\s*(?:;|$)/i.test(dsn)) {
  throw new Error('Refusing to run: FACET_TEST_DB_DSN does not name the facet_test schema.');
}

/** The port the suite's own server listens on. Overridable for a second run. */
const port = Number.parseInt(process.env.FACET_E2E_PORT ?? '8788', 10);

const baseURL = `http://127.0.0.1:${port}`;

/**
 * The server is booted in `production`, the same configuration PORT-111
 * audited: strict error disclosure, no debug diagnostics, manifest-backed
 * assets. `APP_URL` stays the real canonical origin — it decides SEO output and
 * is never derived from a request — while the transport is this loopback port,
 * exactly as the Lighthouse gate recorded it.
 */
const serverEnvironment: Record<string, string> = {
  APP_NAME: 'Facet',
  APP_ENV: 'production',
  APP_URL: 'https://facet.thibaultpaul.com',
  APP_LOCALE: 'en',
  APP_DEBUG: 'false',
  APP_KEY: 'e2e-application-key-not-a-production-secret',
  DB_DSN: dsn,
  DB_USERNAME: required('FACET_TEST_DB_USER'),
  DB_PASSWORD: required('FACET_TEST_DB_PASSWORD'),
  // The built-in server hands one request to one process at a time; a document
  // that pulls a stylesheet, a script and two fonts would otherwise serialise
  // behind itself. Concurrency here removes a source of timing flakiness that
  // has nothing to do with the product.
  PHP_CLI_SERVER_WORKERS: '8',
  PATH: process.env.PATH ?? '/usr/bin',
};

export { ROOT, baseURL, port, serverEnvironment };
