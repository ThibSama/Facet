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

For HMR, set `VITE_DEV_SERVER_ORIGIN=http://localhost:5173`, run `npm run dev`,
and run PHP separately. PHP only constructs the development URLs; it does not
probe or require the Vite server during application boot. With no origin set,
local development uses an existing manifest when present and otherwise keeps
server-rendered HTML available without enhancement.

### `composer quality`

The single aggregate gate. It runs, in order and **stopping at the first
failure**:

1. `vendor/` presence guard — fails immediately if dependencies are not installed
2. `composer lint:php` — PHP syntax
3. `composer analyse` — PHPStan level 8
4. `npm run lint` — ESLint
5. `npm run build` — TypeScript check + production Vite build
6. `composer test` — PHPUnit

The build runs **before** the tests on purpose: the skin isolation tests assert
against the real Vite manifest, so they must see freshly built artefacts rather
than a stale or absent `public/build/`.

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
built `public/build/` directory; no Node runtime is involved. Production boot
requires a valid manifest, and a selected skin with a missing manifest entry
fails the request explicitly instead of rendering an assetless page.

---

## Layout

```
config/           Resolved application settings
content/          Canonical content (versioned JSON, no database)
database/         Ordered SQL migrations (plain .sql, no framework)
public/           Document root — index.php and built assets (public/build/)
resources/css/    Shared Tailwind entry stylesheet
resources/fonts/  Licensed local WOFF2 pipeline (no font selected yet)
resources/images/ Manifest-addressable local responsive image sources
resources/js/     Shared TypeScript entrypoint (progressive enhancement only)
resources/skins/  One directory per skin — views + isolated entrypoints
src/              PSR-4 application code (Facet\)
tests/            PHPUnit — Unit/, Content/, Smoke/ and Database/
tools/            Dependency-free maintenance scripts
```

---

## Content and routes

Facet separates *what the site says* from *how it looks*, before any skin
exists.

**Routes** are declared as data in `Facet\Routing\RouteCatalog`. Each of the
ten canonical routes states its path, accepted methods, visibility, data source
and a *logical* template id — never a file path. Nothing in routing knows how a
page is rendered. The catalog carries a contract version (`1.2.0`) that is
bumped whenever a route is added, removed or re-scoped.

| Path                | Methods    | Visibility    | Data source    |
| ------------------- | ---------- | ------------- | -------------- |
| `/`                 | GET        | public        | content corpus |
| `/projects`         | GET        | public        | content corpus |
| `/projects/{slug}`  | GET        | public        | content corpus |
| `/about`            | GET        | public        | content corpus |
| `/contact`          | GET, POST  | public        | message store  |
| `/login`            | GET, POST  | guest         | auth session   |
| `/logout`           | POST       | authenticated | auth session   |
| `/admin`            | GET        | admin         | content corpus |
| `/admin/messages`   | GET, POST  | admin         | message store  |
| `/client`           | GET        | client        | auth session   |

Visibility is enforced centrally, between routing and dispatch — see
[Authentication and access control](#authentication-and-access-control).

**Content** lives in `content/` as versioned JSON — outside any database and
outside any skin — and is loaded into the typed structures in `Facet\Content`
(`Profile`, `Project`, `Skill`, `Experience`). Those structures carry no
presentation field, so a skin consumes them without the content knowing the skin
exists. See `content/README.md` for the editing rules.

Slug grammar is defined once, in `Facet\Support\Slug`, and is enforced both by
the `/projects/{slug}` route parameter and at corpus load. Malformed and
duplicate slugs fail deterministically rather than resolving to the wrong entry.

Media is optional throughout: an entry with no image still carries a mandatory
text description and resolves to a shared fallback reference, so the site builds
and renders before any final asset exists.

---

## Skins

Facet renders through a *skin*: a named bundle of server-side views and build
assets. The boundary exists so a second skin is a new directory plus one
registry entry — never a change to routing, content or the shared runtime.

### The registry

`Facet\Skin\SkinRegistry` is the canonical list. It holds exactly one real
skin, `evolving-interface`, which is also the default. Each entry is a
`SkinDefinition` declaring four things:

| Declares          | Example                                          |
| ----------------- | ------------------------------------------------ |
| stable id         | `evolving-interface`                             |
| view namespace    | `evolving-interface`                             |
| view directory    | `resources/skins/evolving-interface/views`       |
| asset entrypoints | `resources/skins/evolving-interface/skin.ts`     |
| capabilities      | server-rendered views, progressive enhancement, isolated stylesheet |

Unknown ids behave deterministically: `has()` is false, `find()` returns `null`,
`get()` throws `UnknownSkinException`, and `findOrDefault()` — the single
sanctioned degradation — always lands on the registered default.

### Logical views

Routes name a *logical view* (`page.home`), never a file. `SkinViewLocator`
turns that identifier into a path owned by the selected skin, rejecting anything
that is not dot-separated lowercase, so a caller-shaped identifier cannot escape
the skin directory. No shared code outside `src/Skin` names a skin or a skin
directory — `tests/Smoke/SkinBoundaryTest.php` enforces that.

### Selection

`SkinSelectionPolicy` decides which skin renders a request.
`DefaultSkinSelectionPolicy` is the only implementation shipped, and its
precedence is: explicit request → choice carried over from earlier navigation →
registry default. It performs **no random selection**.

- **Development** may preview a skin explicitly: `?skin=evolving-interface`.
- **Production cannot.** `SkinSelectionContext::fromRequest()` never captures
  the query parameter or the carried value outside development, so no policy —
  present or future — can read a value production did not record.
- An unknown id falls through to the default instead of failing the request.
- A selection carries its source, so an explicit choice can be persisted and
  survive navigation **without any route being rewritten** to carry it.

A future strategy (a random skin, an A/B split, a stored preference) plugs into
the same interface. `tests/Support/FakeRandomSkinSelectionPolicy.php` proves
that seam without activating randomness in `src/`.

### Isolated assets

Vite builds one entrypoint per skin alongside the shared entrypoint:

| Manifest key                                     | Layer            |
| ------------------------------------------------ | ---------------- |
| `resources/js/app.ts`                            | shared           |
| `resources/skins/evolving-interface/skin.ts`     | selected skin    |
| `resources/skins/fixture-unselected/skin.ts`     | test fixture     |

`Facet\Asset\AssetManager` selects the delivery mode once. Production resolves
fingerprinted URLs from a required Vite manifest; development can emit the HMR
client plus source entrypoints from `VITE_DEV_SERVER_ORIGIN`, without a network
probe. `AssetResolver` returns shared entrypoints plus the selected skin's, and
nothing else. A rendered document can only reference what is in that bundle.

`fixture-unselected` is built on purpose and is **never registered as a skin**.
A build artefact that exists and is still absent from every rendered document is
what makes the isolation claim testable rather than merely plausible — see
`tests/Smoke/SkinAssetIsolationTest.php`.

### Verifying the boundary by hand

```bash
composer install && npm ci
composer quality

# 1. The manifest separates shared assets from each skin's
cat public/build/manifest.json

# 2. A rendered document references shared + evolving-interface only
APP_ENV=local APP_KEY=dev php -d variables_order=EGPCS public/index.php \
  | grep -o '/build/assets/[^"]*'

# 3. ... and never the unselected fixture
APP_ENV=local APP_KEY=dev php -d variables_order=EGPCS public/index.php \
  | grep -c 'fixture-unselected'   # expected: 0
```

---

## Configuration

`Facet\Config\Config` reads real environment variables first and falls back to
`.env` only for values the environment does not already define.

- Non-sensitive keys may carry a safe default (`APP_NAME`, `APP_URL`, …).
- Sensitive keys (`APP_KEY`, `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`) have **no
  fallback** — `Config::get()` on them ignores any caller-supplied default and
  throws when the value is missing. A database boundary that can invent its own
  credentials is one that silently connects somewhere unintended.
- `APP_DEBUG` is forced off whenever `APP_ENV=production`.

Every recognised key is documented in `.env.example`, which contains
placeholders only.

### Local media and caching

Fonts are project-local WOFF2 files imported through
`resources/fonts/fonts.css`. No final typeface or binary is currently selected,
so the repository intentionally contains no `@font-face` or preload. The
provenance and `font-display` requirements are recorded in
`resources/fonts/README.md`; remote runtime font services are prohibited.

Local fallback/AVIF/WebP image variants live under `resources/images/` and must
be declared as Vite inputs when PHP needs a direct URL. `ResponsiveImage`
resolves those manifest keys with intrinsic dimensions and accessible metadata,
without requiring final portfolio images today.

`AssetCachePolicy` grants one-year immutable caching only to hashed files under
`/build/assets/`. HTML responses and non-fingerprinted paths use `no-cache`.
Static-file headers remain the production web server's responsibility; this
repository deliberately contains no deployment-specific server configuration.

---

## Database

The public site does **not** use the database. Canonical content lives in
`content/` as versioned JSON, and every public page renders with `DB_DSN`,
`DB_USERNAME` and `DB_PASSWORD` unset — `DatabaseIndependenceTest` holds that
line. The database exists for what comes next: accounts and contact messages.

### The connection

`Facet\Database\Database` is the only place that talks to MariaDB. It connects
lazily, so nothing costs a connection until a query actually runs, and it fixes
the options the rest of the codebase is entitled to assume:

| Option | Value | Why |
| --- | --- | --- |
| `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | a failed statement can never look like an empty result |
| `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | rows are read by name, never by position |
| `ATTR_EMULATE_PREPARES` | `false` | parameters are bound by the server, not interpolated by the driver |
| `ATTR_STRINGIFY_FETCHES` | `false` | an `INT` column arrives as an `int` |

The DSN must name the `mysql` driver and use `utf8mb4`; an absent charset is
added and a conflicting one is rejected. Pinning the driver is what stops a
misconfigured environment from quietly pointing the suite at SQLite, where the
schema would not be exercised at all.

Credentials never reach an error message. MariaDB's own text for a rejected
login names the account (`Access denied for user 'facet'@'…'`), so
`DatabaseException` scrubs every driver string on the way in and deliberately
does **not** chain the raw `PDOException` — chaining would put the unscrubbed
message straight back into `(string) $exception`. `sqlState()` and
`driverDetail()` carry the causality forward in a form that is safe to log.

### Migrations

Migrations are plain `.sql` files in `database/migrations/`, named
`<version>_<name>.sql` and applied in version order. A `schema_migrations`
ledger records each one with a SHA-256 checksum.

```bash
php tools/migrate.php --status   # report; changes nothing
php tools/migrate.php            # apply everything pending
```

Re-running applies nothing. Editing a migration that has already been applied,
or deleting its file, **fails closed** rather than guessing.

> **On atomicity, honestly:** a migration is *not* atomic. MariaDB commits DDL
> implicitly, so wrapping `CREATE TABLE` in a transaction buys nothing. A
> migration that fails partway leaves the earlier statements applied and is not
> recorded in the ledger, so the next run fails loudly on the existing object
> instead of silently skipping the rest. Recovery is a human decision and the
> tool does not pretend to make it.

### The first administrator

```bash
php tools/create-admin.php --email=you@example.com
```

The password is prompted for with echo disabled — never passed as an argument,
because arguments are visible to every process through `ps` and land in shell
history. For an unattended provision, export `FACET_ADMIN_PASSWORD` instead.
It is hashed with `password_hash()` before it reaches the database.

There is **no `/install` route and no default account.** This repository
contains no password and no hash, so a fresh deployment has no credential at all
until someone with shell access runs this command.

### Applying the schema without shell access

Shared hosting — a Hetzner webhosting plan with phpMyAdmin and no SSH, for
example — can apply the same schema by hand. The migrations are deliberately
plain SQL so that this works:

1. In phpMyAdmin, select the target database (never `mysql` or
   `information_schema`) and open the **SQL** tab.
2. Paste the contents of each file in `database/migrations/` **in version
   order**, running one file at a time and confirming each succeeds before
   starting the next.
3. Record what you applied, so the ledger matches reality:

   ```sql
   CREATE TABLE IF NOT EXISTS schema_migrations (
       version    VARCHAR(50)  NOT NULL,
       name       VARCHAR(191) NOT NULL,
       checksum   CHAR(64)     NOT NULL,
       applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (version)
   ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
   ```

   Insert one row per applied file. The checksum is the SHA-256 of the file with
   CRLF normalised to LF and trailing whitespace stripped — `php -r` prints the
   value the migrator expects:

   ```bash
   php -r 'require "vendor/autoload.php";
     echo Facet\Database\Migration\Migration::fromFile($argv[1])->checksum(), PHP_EOL;' \
     database/migrations/0001_create_users_table.sql
   ```

   Get a checksum wrong and the next `php tools/migrate.php` will refuse to run
   rather than double-apply — which is the intended behaviour, not a bug.
4. Create the administrator. With no shell, insert the row directly, generating
   the hash on a machine you *do* control:

   ```bash
   php -r 'echo password_hash("the-password-you-chose", PASSWORD_DEFAULT), PHP_EOL;'
   ```

   ```sql
   INSERT INTO users (email, password_hash, role, status)
   VALUES ('you@example.com', '<paste the hash>', 'admin', 'active');
   ```

   The address must be lowercase and trimmed — a `CHECK` constraint rejects
   anything else, which is what keeps the unique index meaningful.

Never paste a plaintext password into phpMyAdmin, and never commit a generated
hash: both end up in server-side query logs and in this repository respectively.

---

## Authentication and access control

Signing in is a POST to `/login`; signing out is a POST to `/logout`. There is
no registration, no password reset and no remember-me: accounts are minted from
a shell (see [The first administrator](#the-first-administrator)), and a session
lasts until it is ended.

**The session stores an account id, and nothing else.** No role, no email, no
status. Every protected request re-reads the row that id names, so an account
that is disabled, deleted or given a different role loses or gains access on the
*next request*, with nobody having to hunt down an open session. A role that
lived in the session would be a role the application trusts without re-reading —
and requests carry no role at all: a `role=admin` field, cookie or header is
just another string in the request, and nothing reads it.

**Access is enforced once, between routing and dispatch.** `Facet\Http\AccessGuard`
asks `Facet\Auth\AccessPolicy` for a decision from the route's declared
visibility and the resolved principal, before any handler runs — so a route is
guarded whether or not its handler exists yet, and a template is never the
boundary. The matrix:

|                    | anonymous       | admin | client |
| ------------------ | --------------- | ----- | ------ |
| `/login`           | the form        | → `/admin` | → `/client` |
| `/admin`           | → `/login`      | allowed | `403` |
| `/admin/messages`  | → `/login`      | allowed | `403` |
| `/client`          | → `/login`      | `403` | allowed |
| `/logout`          | → `/login`      | allowed | allowed |

An anonymous visitor is redirected rather than refused, and the redirect is the
same for every protected route and carries no `?next=` — an attacker-suppliable
redirect target is a separate liability and nothing needs one. A signed-in
visitor asking for something that belongs to the other role gets `403`: there is
nothing they can do at a login form about their own role. There is deliberately
**no hierarchy** — an admin is refused `/client` exactly as a client is refused
`/admin`.

**Every POST to a non-public route must carry this session's CSRF token**, and
that rule is applied by the guard rather than by each handler, so a mutation
is defended by being declared in the catalog. `/logout` and the admin message
status mutation both exercise it. (`/contact` is a *public* POST and keeps its own token check, because the
order in which it interleaves that check with throttling and storage is part of
that form's design.)

**A failed sign-in says one thing.** An unknown address, a wrong password and a
disabled account produce the same status and the same sentence — anything else
turns the form into a way of asking whether an address has an account here. The
password is never redisplayed in any state.

**Session fixation** is handled where the privilege is created: `login()`
regenerates the session identifier *before* any authenticated state is written,
destroying the record the old identifier named. Logging out clears the data,
destroys the session server-side and expires the cookie, so replaying the old
cookie resumes nothing. `session_regenerate_id()`, `session_destroy()` and
`setcookie()` appear in exactly one file, `Facet\Session\PhpSession`, and a
structural test keeps it that way.

The session cookie is `HttpOnly`, `SameSite=Lax`, `Path=/`, and `Secure` only
when the request actually arrived over HTTPS — a forwarding header is
client-supplied and is not believed until a trusted-proxy policy exists.

---

## Scope

This checkpoint delivers the foundation, the canonical content and routes, the
**skin boundary**, the **MariaDB persistence foundation** — a strict PDO
connection, ordered migrations and a CLI admin bootstrap — the defended
**contact form**, **authentication with central role guards**, the bounded
**admin contact inbox and status lifecycle**, and a truthful private **client
shell**.

Message deletion, replies, email sending, search, analytics, account management,
client business features, password reset, registration, remember-me, MFA,
OAuth, SEO, the final visual design, a second skin, random skin selection,
deployment and CI remain deliberately out of scope.
