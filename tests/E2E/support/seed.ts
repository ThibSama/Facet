/**
 * The seeded state, restated for the assertions.
 *
 * These are a copy of what `tests/E2E/fixtures/seed.php` writes, and a copy is
 * a liability unless something checks it: `fixture-integrity.spec.ts` reads the
 * fixture's own `--report` and fails if any value here has drifted.
 */
const ADMIN = {
  email: 'e2e-admin@facet.test',
  password: 'e2e-fixture-password',
} as const;

const CLIENT = {
  email: 'e2e-client@facet.test',
  password: 'e2e-fixture-password',
} as const;

const MESSAGES = [
  { id: 1, name: 'Ada Fixture', email: 'ada@example.test', subject: 'Seeded new message', status: 'new' },
  { id: 2, name: 'Grace Fixture', email: 'grace@example.test', subject: 'Seeded read message', status: 'read' },
  { id: 3, name: 'Alan Fixture', email: 'alan@example.test', subject: 'Seeded archived message', status: 'archived' },
] as const;

export { ADMIN, CLIENT, MESSAGES };
