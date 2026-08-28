/**
 * The suite's only way to touch the database.
 *
 * Every call shells out to `tests/E2E/fixtures/seed.php`, which is the single
 * definition of what a known state is. Nothing here writes SQL: a TypeScript
 * copy of the fixture would be a second source of truth, and the two would
 * disagree the first time a column moved.
 */
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

import { ROOT } from './environment';

const SEED = resolve(ROOT, 'tests/E2E/fixtures/seed.php');

function run(...args: string[]): string {
  return execFileSync('php', [SEED, ...args], {
    cwd: ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

/** Drop every table, apply every migration, then seed. Once, before the run. */
function rebuildDatabase(): string {
  return run('--migrate');
}

/**
 * Return the database to the known state, for one test.
 *
 * TRUNCATE resets AUTO_INCREMENT, so the seeded rows carry the same ids in
 * every test — which is what lets a test assert `/admin/messages?id=1` without
 * depending on what ran before it.
 */
function resetDatabase(): string {
  return run();
}

/** The current rows, as the fixture sees them. */
function reportDatabase(): DatabaseReport {
  return JSON.parse(run('--report')) as DatabaseReport;
}

interface DatabaseReport {
  schema: string;
  users: { id: number; email: string; role: string; status: string }[];
  messages: { id: number; email: string; subject: string; status: string }[];
  adminEmail: string;
  clientEmail: string;
  password: string;
}

export { rebuildDatabase, reportDatabase, resetDatabase };
export type { DatabaseReport };
