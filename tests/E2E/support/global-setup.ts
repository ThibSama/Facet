/**
 * Build the schema once, before any browser starts.
 *
 * Dropping and re-migrating is separated from the per-test reset on purpose:
 * the schema is proved once, from the project's own migrations, and each test
 * then only truncates and re-seeds. A run therefore starts from migrations
 * rather than from whatever a previous run happened to leave behind.
 */
import { rebuildDatabase } from './database';

async function globalSetup(): Promise<void> {
  process.stdout.write(rebuildDatabase());
}

export default globalSetup;
