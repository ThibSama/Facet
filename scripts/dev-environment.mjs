/**
 * The environment the development supervisor sees.
 *
 * This is Node's half of the convention PORT-123 fixed in PHP: the same three
 * sources in the same order — process environment, then the optional
 * `.env.local`, then `.env` — with the same rule that `.env.local` neither
 * decides `APP_ENV` nor is read at all in production.
 *
 * It exists so the supervisor can answer two questions before it starts
 * anything (is this a local checkout, and is it configured?) without booting
 * PHP to ask. `tests/Unit/Config/` asserts the two implementations agree.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** Parse a dotenv file. Returns an empty map when the file is not there. */
export function readEnvFile(path) {
  let contents;

  try {
    contents = readFileSync(path, 'utf8');
  } catch {
    return {};
  }

  const values = {};

  for (const line of contents.split(/\r?\n/)) {
    const trimmed = line.trim();

    if (trimmed === '' || trimmed.startsWith('#') || !trimmed.includes('=')) {
      continue;
    }

    const separator = trimmed.indexOf('=');
    const name = trimmed.slice(0, separator).trim();

    if (name === '') {
      continue;
    }

    values[name] = unquote(trimmed.slice(separator + 1).trim());
  }

  return values;
}

function unquote(value) {
  if (
    value.length >= 2 &&
    ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))
  ) {
    return value.slice(1, -1);
  }

  const comment = value.indexOf(' #');

  return comment === -1 ? value : value.slice(0, comment).trimEnd();
}

/** Names the test suite owns. The supervisor must never propagate one. */
export const TEST_ONLY_PREFIX = 'FACET_TEST_';

/**
 * Resolve application configuration exactly as `Config::fromEnvironment()`
 * does.
 *
 * @returns {{ values: Record<string, string>, environment: string, usedLocalOverride: boolean }}
 */
export function resolveEnvironment(root, processEnv = process.env) {
  const base = readEnvFile(resolve(root, '.env'));
  const override = readEnvFile(resolve(root, '.env.local'));

  // APP_ENV is decided without .env.local, so "is this production?" cannot be
  // answered by the very file production is not allowed to read.
  const declared = processEnv.APP_ENV || base.APP_ENV || '';
  const environment = declared === '' ? 'production' : declared;
  const usedLocalOverride = environment !== 'production' && Object.keys(override).length > 0;

  const values = { ...base };

  if (environment !== 'production') {
    for (const [name, value] of Object.entries(override)) {
      if (name !== 'APP_ENV') {
        values[name] = value;
      }
    }
  }

  for (const [name, value] of Object.entries(processEnv)) {
    if (value !== undefined && value !== '' && !name.startsWith(TEST_ONLY_PREFIX)) {
      values[name] = value;
    }
  }

  for (const name of Object.keys(values)) {
    if (name.startsWith(TEST_ONLY_PREFIX)) {
      delete values[name];
    }
  }

  return { values, environment, usedLocalOverride };
}
