# Facet

Server-rendered PHP portfolio foundation.

Facet is a plain PHP 8 application that renders HTML on the server. Vite,
Tailwind CSS and TypeScript form the frontend build chain — there is no SPA and
no frontend framework. **Node is a development and build dependency only: it is
never required to run Facet in production.**

---

## Requirements

| Tool     | Version   | Needed for            |
| -------- | --------- | --------------------- |
| PHP      | >= 8.2    | runtime + development |
| Composer | 2.x       | PHP dependencies      |
| Node.js  | >= 20.19  | development / build   |
| npm      | 10.x / 11.x | development / build |

PHP extensions: `json`, `mbstring` (both bundled with a standard PHP 8 build).

---

## Fresh checkout

```bash
git clone <repository-url> facet
cd facet

# 1. PHP dependencies (from composer.lock)
composer install

# 2. Frontend dependencies (from package-lock.json)
npm ci

# 3. Local environment
cp .env.example .env
php -r 'echo "APP_KEY=", bin2hex(random_bytes(32)), PHP_EOL;' >> .env

# 4. Build assets
npm run build

# 5. Verify everything
composer quality
```

`.env` is git-ignored and must never be committed. `APP_KEY` has **no fallback**:
the application throws `MissingConfigurationException` if it is unset or empty.

---

## Everyday commands

| Command             | What it does                                        |
| ------------------- | --------------------------------------------------- |
| `composer quality`  | **Aggregate gate** — see below                       |
| `composer test`     | PHPUnit                                              |
| `composer analyse`  | PHPStan (level 8)                                    |
| `composer lint:php` | Dependency-free PHP syntax check                     |
| `npm run dev`       | Vite dev server with HMR                             |
| `npm run build`     | `tsc --noEmit` + production Vite build               |
| `npm run lint`      | ESLint over TypeScript/JavaScript                    |
| `npm run typecheck` | TypeScript, no emit                                  |

### `composer quality`

The single aggregate gate. It runs, in order and **stopping at the first
failure**:

1. `vendor/` presence guard — fails immediately if dependencies are not installed
2. `composer lint:php` — PHP syntax
3. `composer analyse` — PHPStan level 8
4. `composer test` — PHPUnit
5. `npm run lint` — ESLint
6. `npm run build` — TypeScript check + production Vite build

Composer aborts a script array on the first non-zero exit code, so a failing or
**missing** sub-tool always fails the gate. There is no `|| true`, no ignored
exit code and no path that reports green while a required tool is absent.

---

## Running locally

```bash
php -S localhost:8000 -t public
```

Then open <http://localhost:8000>.

In production, point the web server's document root at `public/` and serve
`public/index.php`. Deploy `vendor/` (via `composer install --no-dev`) and the
built `public/build/` directory; no Node runtime is involved.

---

## Layout

```
config/         Resolved application settings
public/         Document root — index.php and built assets (public/build/)
resources/css/  Tailwind entry stylesheet
resources/js/   TypeScript entrypoint (progressive enhancement only)
src/            PSR-4 application code (Facet\)
tests/          PHPUnit — Unit/ and Smoke/
tools/          Dependency-free maintenance scripts
```

---

## Configuration

`Facet\Config\Config` reads real environment variables first and falls back to
`.env` only for values the environment does not already define.

- Non-sensitive keys may carry a safe default (`APP_NAME`, `APP_URL`, …).
- Sensitive keys (`APP_KEY`) have **no fallback** — `Config::get()` on them
  ignores any caller-supplied default and throws when the value is missing.
- `APP_DEBUG` is forced off whenever `APP_ENV=production`.

Every recognised key is documented in `.env.example`, which contains
placeholders only.

---

## Scope

This is the foundation checkpoint. Routing, portfolio content, database, auth,
SEO, visual design, the skin architecture, deployment and CI are deliberately
out of scope and land in later packages.
