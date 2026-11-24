# LWTV PWA

The LezWatch.TV filter app. A Laravel app that serves the PWA, keeps a cached
copy of the LezWatch.TV catalogue, and will later host the ratings API.

It runs as a small Docker stack (PHP-FPM, nginx and a scheduler) so it can sit
on the server behind the host nginx, the same way it runs locally.

## Run locally

Requires Docker Desktop, plus PHP and Composer on the host: the local override
mounts the source (including `vendor/`) into the containers.

1. Copy the environment file:

   ```bash
   cp .env.example .env
   ```

   `.env.example` is production-safe. For local work, change these in `.env`:

   ```
   APP_ENV=local
   APP_DEBUG=true
   LWTV_API_URL=https://lwtv.local
   LWTV_API_VERIFY_TLS=false
   ```

2. Install PHP dependencies and generate an app key:

   ```bash
   composer install
   ```

   ```bash
   php artisan key:generate
   ```

3. Start the stack:

   ```bash
   docker compose up -d --build
   ```

4. Open http://localhost:8069. The port comes from `APP_PORT` in `.env`.

`compose.override.yaml` is loaded automatically. It mounts the source for
live editing and maps `lwtv.local` to your Mac so the containers can reach the
Local site. It is for local use only.

### npm shortcuts

| Command | Runs | Use it to |
| --- | --- | --- |
| `npm run installer` | `npm install && composer install` | Install Node and PHP dependencies. |
| `npm run build` | `docker compose up -d --build` | Rebuild the images and start the stack. |
| `npm run start` | `docker compose up -d` | Start the stack without rebuilding. |
| `npm run stop` | `docker compose down` | Stop the stack and remove its containers. |

`npm run stop` uses `down`, not `down -v`, so the `lwtv-data` volume that holds
the SQLite database is kept. `down` is used instead of `stop` so the next
`npm run start` gets fresh containers, with no leftovers from the last run.

These wrap the default compose files, so they include `compose.override.yaml`.
They are for local use. On a server, use the `-f compose.yaml` commands below.

Because the override mounts your checkout into the containers, the app uses
your local `vendor/`, not the one built into the image. Run `npm run installer`
before the first `npm run build`, and again whenever `composer.json` or
`composer.lock` changes. If you skip it, the app and scheduler containers stop
straight away, and `docker compose logs app` says `vendor/autoload.php is
missing`.

The old Vite build is now `npm run build:assets`. Nothing uses it yet.

## Run on a server

No PHP is needed on the host; everything runs in the containers.

1. Copy `.env.example` to `.env` and keep its production defaults:
   `APP_ENV=production`, `APP_DEBUG=false`,
   `LWTV_API_URL=https://lezwatchtv.com`, `LWTV_API_VERIFY_TLS=true`.
   Set `APP_URL` to the public address, for example `https://app.lezwatchtv.com`.

2. Build, then generate an app key and paste it into `.env` as `APP_KEY=`:

   ```bash
   docker compose -f compose.yaml build
   ```

   ```bash
   docker compose -f compose.yaml run --rm --no-deps app php artisan key:generate --show
   ```

   The key encrypts the admin's session cookie. The public pages set no
   cookies, so `/` and `/api/shows` work without it, but the admin won't.

3. Start the stack with only the production file, so the override is skipped:

   ```bash
   docker compose -f compose.yaml up -d
   ```

4. Load the catalogue once instead of waiting for the first scheduled sync:

   ```bash
   docker compose -f compose.yaml exec app php artisan lwtv:sync
   ```

The stack listens on `127.0.0.1:${APP_PORT}`. Proxy to it from the host nginx,
which keeps handling the domain and TLS. With `APP_ENV=production`, the app
container caches config, routes and views on start, so restart it after
changing `.env`:

```bash
docker compose -f compose.yaml up -d --force-recreate app scheduler
```

Logs, including sync failures, go to `docker compose -f compose.yaml logs`. The
admin page (see [Admin](#admin)) keeps a record of every sync run.

## Sync the catalogue

The scheduler runs `php artisan lwtv:sync` every six hours. To run it now:

```bash
docker compose exec app php artisan lwtv:sync
```

It reads `GET {LWTV_API_URL}/wp-json/lwtv/v1/list/shows`. If the fetch fails,
or comes back empty or in a shape with no usable shows, the command logs the
reason, exits with an error, and leaves the cached shows as they were. Every
run, whether it succeeds or fails, is recorded in the `sync_runs` table, which
the admin shows.

## API

`GET /api/shows` returns the cached catalogue for the PWA.

No public page sets cookies: `/`, `/api/shows` and `/up` run without a session
or CSRF token. Only the admin has a session, and its cookie is scoped to the
admin path. Tests in `tests/Feature/PublicCookiesTest.php` hold this in place.

```json
{
  "schema_version": 1,
  "count": 2266,
  "shows": [
    {"id": 27002, "title": ".hack//SIGN", "slug": "hack-sign", "url": "https://lezwatchtv.com/show/hack-sign/", "on_air": false}
  ]
}
```

## Admin

A staff-only page at `{APP_URL}/{ADMIN_PATH}` shows the sync log (every run,
whether scheduled, from the command line or from the admin) with the reason
for each failure. It also has a **Sync now** button that does the same work
as `php artisan lwtv:sync`. If a sync is already running, the button says so
and does nothing.

Only members of the Google Group `pwa-admins@lezwatchtv.com` can sign in,
nested groups included. Staff sign in with Google, so the app never sees a
password. The app stores no staff details in the database: the email lives
only in the admin's own encrypted session cookie, which is scoped to the admin
path and never sent to the public pages.

The admin is off until `ADMIN_PATH` is set. Choose a long random value and keep
it out of the repo. It keeps scanners away, but it is not the lock: Google
sign-in plus the group check is.

### Set it up

You need a Google Workspace super admin for step 4.

For the click-by-click version, plus a local test, adding and removing admins,
rotating secrets, and troubleshooting, use the runbook:
[docs/runbooks/google-sign-in.md](docs/runbooks/google-sign-in.md).

1. In Google Cloud, create or pick a project and enable the
   **Admin SDK API**. The app uses its Directory API `members.hasMember`
   check, which follows nested groups and works on every Workspace edition.
2. Set up the OAuth consent screen as **Internal**, then create an
   **OAuth client ID** (type: web application). Add this authorized redirect
   URI, once for each place the app runs:

   ```
   {APP_URL}/{ADMIN_PATH}/callback
   ```

   For example `https://pwa.lezwatchtv.com/<secret>/callback`, and
   `http://localhost:8069/<secret>/callback` locally.
3. Create a **service account** with no project roles, and download a JSON key
   for it. Save it as `secrets/google-service-account.json` next to
   `compose.yaml`. The `secrets/` folder is gitignored, kept out of the image,
   and mounted read-only at `/run/secrets`. The PHP-FPM workers run as
   `www-data`, so the file must be readable by that user. On the server,
   `chmod 644 secrets/google-service-account.json` (keep the folder itself
   readable only by people who should see it). If the key can't be read,
   nobody can sign in, and the log says why.
4. In the Google Admin console, go to **Account > Admin roles > Groups Reader >
   Admins assigned**, and assign the role to the service account's email. That
   lets it read group membership. It needs no domain-wide delegation.
5. In `.env`:

   ```
   ADMIN_PATH=<long random string>
   GOOGLE_CLIENT_ID=<from step 2>
   GOOGLE_CLIENT_SECRET=<from step 2>
   GOOGLE_SERVICE_ACCOUNT_KEY=/run/secrets/google-service-account.json
   ```

   `ADMIN_GROUP` and `GOOGLE_WORKSPACE_DOMAIN` default to
   `pwa-admins@lezwatchtv.com` and `lezwatchtv.com`.
   The Google return address is built from `APP_URL`, so `APP_URL` must be
   the public `https://` address. The admin cookie is marked Secure when it is.
6. Restart the app container. In production, routes and config are cached on
   start, so changing `ADMIN_PATH` always needs a restart.

### How access is checked

- Sign-in only accepts verified accounts whose Google token says they belong
  to `GOOGLE_WORKSPACE_DOMAIN`.
- Group membership is checked at sign-in, then rechecked every
  `ADMIN_RECHECK_MINUTES`, which defaults to `1440` (daily). The admin session
  lasts that long too. Someone removed from the group loses access at their
  next recheck.
- If Google can't be reached or answers with an error, access is refused, and
  someone already signed in is signed out.
- Sign-in not working? See
  [Troubleshooting in the runbook](docs/runbooks/google-sign-in.md#5-troubleshooting-by-symptom).

## Point at production

In `.env`:

```
LWTV_API_URL=https://lezwatchtv.com
LWTV_API_VERIFY_TLS=true
```

## Tests

```bash
php artisan test
```

Or inside the PHP 8.4 container:

```bash
docker compose exec app php artisan test
```

Tests never call lezwatchtv.com or lwtv.local.

## Pull request checks

Every pull request runs `PHPUnit` (with Pint, lockfile checks and the risk
gate's own tests), `Docker build`, and `security` (composer and npm audit, plus
gitleaks over the PR's commits). PRs into `production` or `release/*` also get
an OSV-Scanner dependency scan.

PRs into `production` or a `release/*` branch also get `risk-tier` (required
to merge into `production`; advice only on release branches). Anything
risky (CI, Docker, config, routes, auth, migrations, the test harness, most of
`app/`, or anything not on the low-risk list) stays red until Mika reads it and
adds the `human-reviewed` label. A new push needs a new review. The gate runs
from `production` (on `workflow_run`), so a PR can't change the gate that
judges it.

How it works, how to approve a PR, and how to move a path between lists:
[docs/runbooks/risk-gate.md](docs/runbooks/risk-gate.md).
