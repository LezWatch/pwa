# Laravel on Docker Skeleton Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel app at the repo root that runs in a PHP-FPM + nginx Docker stack on `localhost:8069`, syncs the LWTV show list into SQLite on a schedule, and serves it as a page and as `GET /api/shows`.

**Architecture:** One multi-stage `Dockerfile` builds two images: `app` (PHP 8.4 FPM, code and vendor baked in) and `web` (nginx with `public/` baked in). `compose.yaml` runs `app`, `web` and `scheduler` (the `app` image running `schedule:work`). `compose.override.yaml` is local only: it bind-mounts the source and maps `lwtv.local` to the Mac. Show data comes through a `CatalogueSource` interface so the future app API (#755) is a second implementation, not a rewrite.

**Tech Stack:** Laravel (latest from `composer create-project`), PHP 8.4 FPM (`php:8.4-fpm-bookworm`), nginx (`nginx:1.28-alpine`), SQLite, Pest or PHPUnit (whichever the Laravel skeleton ships with).

**Spec:** `docs/superpowers/specs/2026-11-23-laravel-docker-skeleton-design.md`

## Global Constraints

- PHP-FPM, not FrankenPHP. Container PHP is 8.4; composer platform pinned to `8.4.0` because the Mac runs PHP 8.6.
- Published port from `.env` `APP_PORT`, default `8069`, bound to `127.0.0.1` only.
- `LWTV_API_URL` default `https://lezwatchtv.com`; local `.env` uses `https://lwtv.local`.
- `LWTV_API_VERIFY_TLS` default `true`; `false` only in local `.env`.
- Shows route: `GET {LWTV_API_URL}/wp-json/lwtv/v1/list/shows`.
- Show shape: `id` (int), `title`, `slug`, `url`, `on_air` (bool).
- The server never receives user filters, watched lists or ratings. No routes accept them.
- Tests never call lezwatchtv.com or lwtv.local; they use `Http::fake()` and the fixture.

## Review Focus

1. **Upstream returns an error, times out, or returns `{}`.** Sync must fail with a non-zero exit and leave the existing shows untouched. A bad fetch must never empty the catalogue. (Task 3 and Task 4 tests.)
2. **Titles with HTML entities** (WordPress often sends `&#8217;` or `&amp;`). Store decoded text. (Task 3 test.)
3. **`onair` not exactly `"yes"`/`"no"`** (missing, `"Yes"`, empty). Missing or unknown counts as `false`, case-insensitive. (Task 3 test.)
4. **URL without a trailing slash, or not under `/show/`.** The slug is the last non-empty path segment either way. (Task 3 test.)
5. **The scheduler and the app both touch SQLite.** The database file must live on the shared volume, owned by `www-data`, and migrations run once from `app` only. (Task 2 smoke check.)

---

### Task 1: Laravel scaffold and LWTV config

**Files:**
- Create: Laravel skeleton at the repo root (merge its `.gitignore` into the existing one; keep the existing `LICENSE` and `README.md`)
- Create: `config/lwtv.php`
- Modify: `.env.example`, `composer.json` (platform pin)
- Test: `tests/Unit/LwtvConfigTest.php`

**Interfaces:**
- Produces: `config('lwtv.api_url')` (string, no trailing slash), `config('lwtv.verify_tls')` (bool), `config('lwtv.timeout')` (int seconds, default `30`).

- [ ] **Step 1:** `composer create-project laravel/laravel <scratch dir>`, then copy everything except `README.md` into the repo root. Append the skeleton's `.gitignore` lines that are missing from ours.
- [ ] **Step 2:** `composer config platform.php 8.4.0 && composer update`. Expected: completes with no platform errors.
- [ ] **Step 3: Write the failing test** in `tests/Unit/LwtvConfigTest.php`: with no env overrides, `config('lwtv.api_url') === 'https://lezwatchtv.com'`, `config('lwtv.verify_tls') === true`, `config('lwtv.timeout') === 30`; with `LWTV_API_URL=https://lwtv.local/` set, `api_url` is `https://lwtv.local` (trailing slash trimmed).
- [ ] **Step 4:** Run `php artisan test --filter=LwtvConfig`. Expected: FAIL (config missing).
- [ ] **Step 5:** Create `config/lwtv.php` reading `LWTV_API_URL`, `LWTV_API_VERIFY_TLS` (use `filter_var(..., FILTER_VALIDATE_BOOL)`), `LWTV_API_TIMEOUT`. Add all three to `.env.example` with the production defaults, plus `APP_PORT=8069`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/var/www/html/database/data/database.sqlite`, `LOG_CHANNEL=stderr`.
- [ ] **Step 6:** Run `php artisan test`. Expected: all pass, including the skeleton's own tests.
- [ ] **Step 7: Commit** `feat: scaffold Laravel with LWTV API config`.

### Task 2: Docker stack

**Files:**
- Create: `Dockerfile`, `docker/nginx/default.conf`, `docker/php/entrypoint.sh`, `compose.yaml`, `compose.override.yaml`, `.dockerignore`
- Modify: `README.md` (how to run locally)

**Interfaces:**
- Consumes: `.env` keys from Task 1.
- Produces: services `app`, `web`, `scheduler`; named volume `lwtv-data` mounted at `/var/www/html/database/data`; site on `http://localhost:${APP_PORT:-8069}`.

Design notes for the builder (these are decisions, follow them):
- `Dockerfile` stages: `vendor` (`composer:2`, `composer install --no-dev --no-scripts --prefer-dist`), `app` (`php:8.4-fpm-bookworm`, extensions `pdo_sqlite` and `opcache` via `install-php-extensions`, copies code and vendor, creates `database/data` owned by `www-data`), `web` (`nginx:1.28-alpine`, copies `public/` and `docker/nginx/default.conf`). Build with `target:` per service.
- `default.conf`: root `/var/www/html/public`, `try_files $uri $uri/ /index.php?$query_string`, `fastcgi_pass app:9000`, and `SCRIPT_FILENAME` set to `/var/www/html/public$fastcgi_script_name` (the path inside the `app` container).
- `entrypoint.sh`: when `RUN_MIGRATIONS=true`, touch the SQLite file and run `php artisan migrate --force`, then `php artisan config:cache` unless `APP_ENV=local`, then `exec "$@"`. Only `app` sets `RUN_MIGRATIONS=true`. `scheduler` runs `php artisan schedule:work` and `depends_on: app`.
- `compose.yaml`: `web` publishes `127.0.0.1:${APP_PORT:-8069}:80`. All services `restart: unless-stopped`. `env_file: .env`.
- `compose.override.yaml`: bind-mount `./` into `app` and `scheduler` at `/var/www/html`, and `./public` into `web`. Keep the `lwtv-data` volume mounted on top. Add `extra_hosts: ["lwtv.local:host-gateway", "www.lwtv.local:host-gateway"]` to `app` and `scheduler`. Set `APP_ENV=local`, `APP_DEBUG=true`.

- [ ] **Step 1:** Write the files above. Copy `.env.example` to `.env`, run `php artisan key:generate`, and set `LWTV_API_URL=https://lwtv.local` and `LWTV_API_VERIFY_TLS=false` in `.env`.
- [ ] **Step 2:** `docker compose up -d --build`. Expected: three containers `running` in `docker compose ps`.
- [ ] **Step 3: Smoke check the HTTP path.** `curl -s -o /dev/null -w '%{http_code}' http://localhost:8069/up`. Expected: `200`.
- [ ] **Step 4: Smoke check the host mapping.** `docker compose exec app curl -sk -o /dev/null -w '%{http_code}' https://lwtv.local/wp-json/`. Expected: `200`.
- [ ] **Step 5: Smoke check the shared database** (Review Focus 5). `docker compose exec scheduler ls -l /var/www/html/database/data/`. Expected: `database.sqlite` owned by `www-data`.
- [ ] **Step 6: Smoke check production shape.** `docker compose -f compose.yaml up -d --build` (no override), then `curl` `/up` again. Expected: `200`. Return to the default with `docker compose up -d`.
- [ ] **Step 7:** Add a "Run locally" section to `README.md`: copy `.env.example`, set the two local LWTV values, `docker compose up -d --build`, open `http://localhost:8069`.
- [ ] **Step 8: Commit** `feat: add PHP-FPM and nginx Docker stack`.

### Task 3: Catalogue source for `lwtv/v1/list/shows`

**Files:**
- Create: `app/Catalogue/CatalogueSource.php`, `app/Catalogue/ShowRecord.php`, `app/Catalogue/CatalogueUnavailable.php`, `app/Catalogue/LwtvListShowsSource.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind interface)
- Create: `tests/Fixtures/list-shows.json` (five entries, real shape, include one title with `&#8217;`, one `onair` of `"Yes"`, one URL without a trailing slash)
- Test: `tests/Unit/LwtvListShowsSourceTest.php`

**Interfaces:**
- Consumes: `config('lwtv.*')` from Task 1.
- Produces:
  - `interface CatalogueSource { /** @return list<ShowRecord> */ public function shows(): array; }`
  - `final readonly class ShowRecord { __construct(public int $id, public string $title, public string $slug, public string $url, public bool $onAir) }`
  - `class CatalogueUnavailable extends RuntimeException`, thrown on non-2xx, timeout, connection error, invalid JSON, or an empty object.

- [ ] **Step 1: Write the failing tests** using `Http::fake()` with the fixture:
  - `it_maps_each_entry_to_a_show_record`: returns 5 records; record for key `"27002"` has `id === 27002`, `title === '.hack//SIGN'`, `slug === 'hack-sign'`, `onAir === false`.
  - `it_decodes_html_entities_in_titles`: the `&#8217;` title comes back with `’`.
  - `it_treats_onair_case_insensitively_and_defaults_false`: `"Yes"` → true; missing or `""` → false.
  - `it_takes_the_slug_from_the_last_path_segment`: works with and without a trailing slash.
  - `it_requests_the_configured_url`: `Http::assertSent` URL is `https://lwtv.local/wp-json/lwtv/v1/list/shows` when `lwtv.api_url` is `https://lwtv.local`.
  - `it_throws_when_upstream_fails`: 500 response, `{}` body, and `Http::fake` throwing `ConnectionException` each throw `CatalogueUnavailable`.
- [ ] **Step 2:** Run `php artisan test --filter=LwtvListShowsSource`. Expected: FAIL (classes missing).
- [ ] **Step 3:** Implement `LwtvListShowsSource::shows()`: `Http::withOptions(['verify' => config('lwtv.verify_tls')])->timeout(config('lwtv.timeout'))->acceptJson()->get(...)`, then map each `id => entry` through `toShowRecord(int $id, array $entry): ShowRecord`.
- [ ] **Step 4 (Mika writes this one):** the body of `toShowRecord()`. This is where the real data meets our shape: slug from the URL, `on_air` from the text, entity decoding. The tests in Step 1 are the spec for it.
- [ ] **Step 5:** Bind `CatalogueSource` to `LwtvListShowsSource` in `AppServiceProvider::register()`.
- [ ] **Step 6:** Run `php artisan test`. Expected: all pass.
- [ ] **Step 7: Commit** `feat: read shows from lwtv/v1/list/shows`.

### Task 4: Show model and `lwtv:sync` command

**Files:**
- Create: migration `create_shows_table`, `app/Models/Show.php`, `app/Console/Commands/SyncCatalogue.php`
- Modify: `routes/console.php` (schedule)
- Test: `tests/Feature/SyncCatalogueTest.php`

**Interfaces:**
- Consumes: `CatalogueSource`, `ShowRecord`, `CatalogueUnavailable` from Task 3.
- Produces: table `shows` (`id` unsigned int primary key, not auto-increment; `title` string; `slug` string indexed; `url` string; `on_air` boolean; timestamps). `App\Models\Show` with `$incrementing = false` and `on_air` cast to bool. Command `lwtv:sync`.

- [ ] **Step 1: Write the failing tests**, binding a fake `CatalogueSource` in the container:
  - `it_inserts_new_shows`: source returns 3 records; after `artisan('lwtv:sync')->assertExitCode(0)`, `Show::count() === 3`.
  - `it_updates_changed_shows`: an existing show with an old title gets the new title.
  - `it_removes_shows_missing_from_the_feed`: an existing show not in the source is deleted.
  - `it_leaves_data_alone_when_the_source_fails` (Review Focus 1): 3 shows exist, the source throws `CatalogueUnavailable`; the exit code is non-zero and `Show::count() === 3`.
  - `it_is_scheduled`: `Schedule` events include a `lwtv:sync` command with expression `0 */6 * * *`.
- [ ] **Step 2:** Run `php artisan test --filter=SyncCatalogue`. Expected: FAIL.
- [ ] **Step 3:** Implement the migration, model and command. The command fetches first, then does the upsert and delete inside one `DB::transaction`. On `CatalogueUnavailable` it reports the message with `$this->error()` and returns `self::FAILURE`. On success it prints `Synced N shows (A added, U updated, R removed).`
- [ ] **Step 4:** In `routes/console.php`: `Schedule::command('lwtv:sync')->cron('0 */6 * * *')->withoutOverlapping();`
- [ ] **Step 5:** Run `php artisan test`. Expected: all pass.
- [ ] **Step 6: Commit** `feat: sync LWTV shows into SQLite every six hours`.

### Task 5: Shows page and `GET /api/shows`

**Files:**
- Create: `routes/api.php`, `app/Http/Controllers/ShowController.php`, `resources/views/shows.blade.php`
- Modify: `bootstrap/app.php` (add `api: __DIR__.'/../routes/api.php'` to `withRouting`; do not run `install:api`, because Sanctum isn't needed), `routes/web.php` (`/` uses the shows page)
- Test: `tests/Feature/ShowsEndpointTest.php`

**Interfaces:**
- Consumes: `App\Models\Show` from Task 4.
- Produces: `GET /api/shows` → `{"schema_version": 1, "count": N, "shows": [{"id", "title", "slug", "url", "on_air"}]}`, sorted by title (case-insensitive). `GET /` → HTML list of titles linking to `url`, with an "On air" text label (not colour alone, per #7).

- [ ] **Step 1: Write the failing tests:**
  - `api_returns_shows_sorted_by_title`: seed `"beta"`, `"Alpha"`; JSON `shows.0.title === 'Alpha'`, `count === 2`, `schema_version === 1`, `shows.0.on_air` is a bool.
  - `api_returns_empty_list_when_not_synced`: `count === 0`, `shows === []`, status 200.
  - `api_sets_no_cookies`: the response has no `Set-Cookie` header.
  - `home_lists_show_titles`: `GET /` sees both titles and the text `On air` for an on-air show.
  - `home_explains_when_empty`: with no shows, it sees `No shows yet. Run php artisan lwtv:sync.`
- [ ] **Step 2:** Run `php artisan test --filter=ShowsEndpoint`. Expected: FAIL.
- [ ] **Step 3:** Implement the controller (`index()` for the API, `page()` for HTML), the routes, and a plain Blade view: semantic `<ul>` of `<a>` elements, no CSS framework.
- [ ] **Step 4:** Run `php artisan test`. Expected: all pass.
- [ ] **Step 5: Commit** `feat: serve shows as a page and as /api/shows`.

### Task 6: End-to-end check in Docker

**Files:**
- Modify: `README.md` (add sync and API sections)

- [ ] **Step 1:** `docker compose up -d --build`, then `docker compose exec app php artisan test`. Expected: all pass inside the PHP 8.4 container.
- [ ] **Step 2:** `docker compose exec app php artisan lwtv:sync` against `lwtv.local`. Expected: `Synced N shows`, with N above 2000.
- [ ] **Step 3:** `curl -s http://localhost:8069/api/shows | python3 -c "import json,sys; print(json.load(sys.stdin)['count'])"`. Expected: the same N.
- [ ] **Step 4:** Open `http://localhost:8069/` in the browser pane. Expected: the list renders.
- [ ] **Step 5:** `docker compose logs scheduler`. Expected: `schedule:work` is running with no errors.
- [ ] **Step 6:** README: how to run a sync by hand, the `/api/shows` shape, and how to point at production (`LWTV_API_URL=https://lezwatchtv.com`, `LWTV_API_VERIFY_TLS=true`).
- [ ] **Step 7: Commit** `docs: document sync and API in README`.
