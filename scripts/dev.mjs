#!/usr/bin/env node
/**
 * `npm run dev` - the whole local development runtime, in one command.
 *
 * Facet is a server-rendered PHP application with Vite as its asset pipeline,
 * so "the app is running" means two processes, not one. This supervisor starts
 * both on fixed ports, hands PHP the Vite origin so hot module replacement
 * works without anyone editing `.env`, and makes the pair behave like a single
 * process: one Ctrl+C stops both, and either one dying takes the other with it.
 *
 * Everything here fails loudly. A busy port is not worked around by choosing
 * another one - a development URL that moves is a development URL you cannot
 * put in a bookmark, a test or a bug report - and a missing prerequisite is
 * reported with the command that fixes it rather than surfacing later as a
 * blank page.
 *
 * Node built-ins only, by design: the development runtime must not add a
 * process supervisor, a dotenv package or a dependency of any kind to a project
 * whose production runtime has none.
 */
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { resolveEnvironment } from './dev-environment.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const HOST = '127.0.0.1';
const PHP_PORT = 8000;
const VITE_PORT = 5173;
const PHP_ORIGIN = `http://${HOST}:${PHP_PORT}`;
const VITE_ORIGIN = `http://${HOST}:${VITE_PORT}`;

const READY_TIMEOUT_MS = 30_000;
const SHUTDOWN_GRACE_MS = 5_000;

/* ------------------------------------------------------------------ output */

const ESC = String.fromCharCode(27);
const colour = (code, text) => (process.stderr.isTTY ? `${ESC}[${code}m${text}${ESC}[0m` : text);

const PREFIX = {
  php: colour('35', '[php] '),
  vite: colour('36', '[vite]'),
  dev: colour('33', '[dev] '),
};

function note(message) {
  process.stderr.write(`${PREFIX.dev} ${message}\n`);
}

function fail(message, remedy) {
  process.stderr.write(`${PREFIX.dev} ${colour('31', 'startup failed')}: ${message}\n`);

  if (remedy !== undefined) {
    process.stderr.write(`${PREFIX.dev} ${remedy}\n`);
  }
}

/**
 * Forward a child's output line by line, tagged with its origin.
 *
 * Both streams are kept and neither is filtered: PHP's built-in server writes
 * its access log, its warnings and its fatals all to stderr, and a warning this
 * script swallowed would be a defect nobody saw.
 */
function forward(stream, prefix, sink) {
  let buffered = '';

  stream.setEncoding('utf8');
  stream.on('data', (chunk) => {
    buffered += chunk;

    const lines = buffered.split('\n');
    buffered = lines.pop() ?? '';

    for (const line of lines) {
      sink.write(`${prefix} ${line}\n`);
    }
  });
  stream.on('end', () => {
    if (buffered !== '') {
      sink.write(`${prefix} ${buffered}\n`);
    }
  });
}

/* -------------------------------------------------------------- preflight */

/** Is the port free? Asked by binding it, which is the only honest answer. */
function isPortFree(port) {
  return new Promise((settle) => {
    const probe = createServer();

    probe.once('error', () => settle(false));
    probe.once('listening', () => probe.close(() => settle(true)));
    probe.listen(port, HOST);
  });
}

function commandExists(command) {
  return new Promise((settle) => {
    const probe = spawn(command, ['--version'], { stdio: 'ignore' });

    probe.once('error', () => settle(false));
    probe.once('close', (code) => settle(code === 0));
  });
}

function viteBin() {
  return resolve(ROOT, 'node_modules/vite/bin/vite.js');
}

async function preflight() {
  const problems = [];

  if (!existsSync(resolve(ROOT, 'public/index.php'))) {
    problems.push(['public/index.php is missing.', 'This script must run from a Facet checkout.']);
  }

  if (!existsSync(resolve(ROOT, 'vendor/autoload.php'))) {
    problems.push(['PHP dependencies are not installed.', 'Run: composer install']);
  }

  if (!existsSync(viteBin())) {
    problems.push(['Vite is not installed.', 'Run: npm ci']);
  }

  if (!(await commandExists('php'))) {
    problems.push(['php was not found on PATH.', 'Install PHP >= 8.2, or add it to PATH.']);
  }

  const { values, environment, usedLocalOverride } = resolveEnvironment(ROOT);

  if (!existsSync(resolve(ROOT, '.env'))) {
    problems.push(['.env is missing.', 'Run: cp .env.example .env, then set APP_KEY.']);
  } else if (environment === 'production') {
    problems.push([
      'APP_ENV resolves to production.',
      'This supervisor starts a development runtime and refuses to serve a production '
        + 'configuration. Set APP_ENV=local in .env.',
    ]);
  } else if (values.APP_KEY === undefined || values.APP_KEY === '') {
    problems.push([
      'APP_KEY is not set.',
      'Generate one: php -r \'echo "APP_KEY=", bin2hex(random_bytes(32)), PHP_EOL;\' >> .env',
    ]);
  }

  for (const [port, name] of [[PHP_PORT, 'PHP'], [VITE_PORT, 'Vite']]) {
    if (!(await isPortFree(port))) {
      problems.push([
        `Port ${port} (${name}) is already in use.`,
        "Facet's development ports are fixed, so nothing silently moves. Free it "
          + `- e.g. lsof -ti tcp:${port} | xargs kill - and try again.`,
      ]);
    }
  }

  if (problems.length > 0) {
    for (const [message, remedy] of problems) {
      fail(message, remedy);
    }

    process.exit(1);
  }

  return { environment, usedLocalOverride };
}

/* ---------------------------------------------------------------- children */

const children = [];
let shuttingDown = false;
let exitCode = 0;

function signalGroup(child, signal) {
  try {
    process.kill(-child.pid, signal);
  } catch {
    try {
      child.kill(signal);
    } catch {
      // Already gone.
    }
  }
}

function shutdown() {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;

  const alive = children.filter(({ child }) => child.exitCode === null && child.signalCode === null);

  if (alive.length === 0) {
    process.exit(exitCode);
  }

  for (const { child } of alive) {
    signalGroup(child, 'SIGTERM');
  }

  // SIGTERM is the request; SIGKILL is the guarantee. The process only exits
  // once every child has actually been reaped, so `npm run dev` returning means
  // the ports are free - an immediate restart is expected to work.
  const deadline = setTimeout(() => {
    for (const { child } of alive) {
      signalGroup(child, 'SIGKILL');
    }
  }, SHUTDOWN_GRACE_MS);

  let remaining = alive.length;

  for (const { child } of alive) {
    child.once('close', () => {
      remaining -= 1;

      if (remaining === 0) {
        clearTimeout(deadline);
        process.exit(exitCode);
      }
    });
  }
}

function start(name, command, args, env) {
  const child = spawn(command, args, {
    cwd: ROOT,
    env,
    stdio: ['ignore', 'pipe', 'pipe'],
    // Its own process group: Vite and PHP both fork, and signalling the group
    // is what makes "stopped" mean no listener is left behind.
    detached: true,
  });

  forward(child.stdout, PREFIX[name], process.stdout);
  forward(child.stderr, PREFIX[name], process.stderr);

  child.on('error', (error) => {
    fail(`${name} could not be started: ${error.message}`);
    exitCode = 1;
    shutdown();
  });

  child.on('exit', (code, signal) => {
    if (shuttingDown) {
      return;
    }

    // An unexpected exit is terminal for the pair: half a development runtime
    // is worse than none, because it still looks like it is working.
    fail(`${name} exited unexpectedly (${signal ?? `code ${code}`}). Stopping the other process.`);
    exitCode = code === 0 || code === null ? 1 : code;
    shutdown();
  });

  children.push({ name, child });

  return child;
}

/* ------------------------------------------------------------- readiness */

async function waitForHttp(url, label) {
  const deadline = Date.now() + READY_TIMEOUT_MS;

  while (Date.now() < deadline) {
    if (shuttingDown) {
      return false;
    }

    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(2_000) });
      await response.arrayBuffer();

      return true;
    } catch {
      await new Promise((settle) => setTimeout(settle, 200));
    }
  }

  fail(`${label} did not become reachable at ${url} within ${READY_TIMEOUT_MS / 1000}s.`);

  return false;
}

/* ------------------------------------------------------------------- main */

const { environment, usedLocalOverride } = await preflight();

for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
  process.on(signal, () => {
    note('stopping...');
    shutdown();
  });
}

start('vite', process.execPath, [viteBin(), '--host', HOST, '--port', String(VITE_PORT), '--strictPort'], {
  ...process.env,
});

start(
  'php',
  'php',
  [
    // $_ENV is what the application falls back to for values not exported
    // through getenv(); asking for E here keeps a hand-run server identical.
    '-d',
    'variables_order=EGPCS',
    '-S',
    `${HOST}:${PHP_PORT}`,
    '-t',
    'public',
    // The router script is what makes /projects, /contact and /login reach the
    // application instead of 404ing as missing files. It is the same invocation
    // the PHPUnit smoke tests and the E2E suite use.
    'public/index.php',
  ],
  {
    ...process.env,
    // The point of the supervisor: HMR is on because both halves are running,
    // not because someone remembered to edit a file. The process environment is
    // the highest-precedence source, so this wins without touching `.env`.
    VITE_DEV_SERVER_ORIGIN: VITE_ORIGIN,
  },
);

const ready =
  (await waitForHttp(`${VITE_ORIGIN}/@vite/client`, 'Vite')) && (await waitForHttp(`${PHP_ORIGIN}/`, 'PHP'));

if (!ready) {
  exitCode = 1;
  shutdown();
} else if (!shuttingDown) {
  note('');
  note(`  Facet is running in "${environment}".`);
  note(`  app   ${PHP_ORIGIN}`);
  note(`  vite  ${VITE_ORIGIN}  (injected into PHP as VITE_DEV_SERVER_ORIGIN)`);

  if (usedLocalOverride) {
    note('  .env.local overrides are in effect.');
  }

  note('');
  note('  Ctrl+C stops both.');
  note('');
}
