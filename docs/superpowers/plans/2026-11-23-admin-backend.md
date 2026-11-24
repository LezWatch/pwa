# Admin Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A staff-only page, behind a secret path and Google Group membership, that lists sync runs and can start a sync.

**Architecture:** Sync logic moves from the command into `App\Catalogue\CatalogueSync`, which takes a cross-container lock and writes a `sync_runs` row. The admin is a route group under `config('admin.path')`, with its own session cookie, Google sign-in via Socialite, and a `GroupMembership` interface whose Google implementation calls the Cloud Identity Groups API with a service-account token.

**Tech Stack:** Laravel 13, PHP 8.4, SQLite, `laravel/socialite` ^5, `google/auth` ^1, Blade.

**Spec:** `docs/superpowers/specs/2026-11-23-admin-backend-design.md`

## Global Constraints

- No `env()` outside `config/*.php` (config is cached in production).
- Tests never call Google, lezwatchtv.com or lwtv.local; `Http::preventStrayRequests()` where HTTP is involved.
- `tests/TestCase.php` in-memory guard stays untouched.
- No staff emails or names in the database. `sync_runs.trigger` is one of `schedule`, `cli`, `admin`.
- Defaults: `ADMIN_GROUP=pwa-admins@lezwatchtv.com`, `ADMIN_RECHECK_MINUTES=1440`, `GOOGLE_WORKSPACE_DOMAIN=lezwatchtv.com`, `ADMIN_PATH` empty means admin off.
- Lock: `Cache::store('database')->lock('lwtv:sync', 900)`.
- Fail closed on any Google API error.

## Review Focus

1. Google returns a user with no `hd`, or `hd` of another domain, with an otherwise valid token → refused (Task 4 test `test_wrong_or_missing_domain_is_refused`).
2. Groups API returns 5xx or times out, at sign-in or at recheck → refused / signed out, never let in (Task 3 + Task 4 tests).
3. A visitor to `/` or `/api/shows` after an admin signed in → no admin cookie on the response (Task 4 `test_public_pages_do_not_set_admin_cookie`).
4. Sync now pressed while the scheduler holds the lock → no second run, no `sync_runs` row, a clear message (Task 5).
5. A `running` row whose process died → shown as stalled after 30 minutes, not as running forever (Task 5 view test).

---

### Task 1: Bring in `release/0.0.1`

- [ ] Merge `origin/release/0.0.1` into the branch (clean merge, adds `docs/design/system/`).
- [ ] `php artisan test` passes. Commit is the merge commit.

### Task 2: `sync_runs` and `CatalogueSync`

**Files:**
- Create: `database/migrations/2026_11_23_200000_create_sync_runs_table.php`, `app/Models/SyncRun.php`, `app/Catalogue/CatalogueSync.php`, `app/Catalogue/SyncAlreadyRunning.php`
- Modify: `app/Console/Commands/SyncCatalogue.php`, `routes/console.php`
- Test: `tests/Feature/SyncCatalogueTest.php`, `tests/Feature/CatalogueSyncTest.php`

**Interfaces:**
- Produces: `CatalogueSync::run(string $trigger): SyncRun` — throws `SyncAlreadyRunning` if the lock is held (no row written). Returns the finished run (status `succeeded` or `failed`). Non-`CatalogueUnavailable` throwables: row marked `failed` with message, then rethrown.
- Produces: `SyncRun` columns `id, trigger, status, started_at, finished_at, total, added, updated, removed, error, timestamps`; casts datetimes; constants `STATUS_RUNNING|SUCCEEDED|FAILED`; method `isStalled(): bool` (running and `started_at` older than 30 minutes).
- Command gains `--trigger=cli` option; `routes/console.php` schedules `lwtv:sync --trigger=schedule`. Command output and exit codes unchanged.

- [ ] **Write failing tests** in `CatalogueSyncTest`: `test_success_records_counts` (trigger `admin`, status `succeeded`, total/added/updated/removed match, `finished_at` set, `error` null); `test_unavailable_records_failure_and_keeps_shows` (status `failed`, `error` = exception message, shows untouched, `Log::error('Catalogue sync failed', ...)` still sent); `test_unexpected_exception_is_recorded_then_rethrown`; `test_held_lock_throws_and_writes_nothing` (acquire `Cache::store('database')->lock('lwtv:sync')` first; expect `SyncAlreadyRunning`, `SyncRun::count() === 0`); `test_lock_is_released_after_failure`. In `SyncCatalogueTest` add `test_cli_run_is_recorded_as_cli` and update the schedule test to expect `--trigger=schedule`.
- [ ] Run `php artisan test --filter=Sync` → FAIL.
- [ ] Implement migration, model, exception, service (move the transaction from the command verbatim); command delegates and maps `SyncAlreadyRunning` to an error line + `FAILURE`.
- [ ] Run `php artisan test` → PASS.
- [ ] Commit `feat: record every sync run in sync_runs`.

### Task 3: Group membership check

**Files:**
- Create: `config/admin.php`, `app/Admin/GroupMembership.php`, `app/Admin/GoogleGroupMembership.php`, `app/Admin/GroupCheckFailed.php`
- Modify: `config/services.php` (`google` => client_id, client_secret, redirect null), `app/Providers/AppServiceProvider.php`, `composer.json`
- Test: `tests/Unit/GoogleGroupMembershipTest.php`

**Interfaces:**
- `config('admin.path')`, `admin.group`, `admin.recheck_minutes` (int), `admin.domain`, `admin.service_account_key` (path).
- `GroupMembership::isMember(string $email): bool` — throws `GroupCheckFailed` on any API/credential error.
- `GoogleGroupMembership`: token from `Google\Auth\Credentials\ServiceAccountCredentials` with scope `https://www.googleapis.com/auth/cloud-identity.groups.readonly`; `GET https://cloudidentity.googleapis.com/v1/groups:lookup?groupKey.id={group}` → `name`; then `GET https://cloudidentity.googleapis.com/v1/{name}/memberships:checkTransitiveMembership?query=member_key_id=='{email}'` → `hasMembership`. Uses Laravel `Http` with a 10 s timeout. Token fetching sits behind a protected `accessToken(): string` so tests override it.

- [ ] **Write failing tests** with `Http::fake` and a subclass overriding `accessToken()`: `test_member_returns_true`, `test_non_member_returns_false`, `test_lookup_error_throws`, `test_membership_error_throws`, `test_email_is_quoted_in_query`.
- [ ] Run → FAIL. `composer require laravel/socialite google/auth`.
- [ ] Implement. Bind `GroupMembership` → `GoogleGroupMembership` in the provider.
- [ ] Run `php artisan test` → PASS. Commit `feat: check Google Group membership`.

### Task 4: Admin routes, session and sign-in

**Files:**
- Create: `routes/admin.php`, `app/Http/Middleware/UseAdminSession.php`, `app/Http/Middleware/RequireAdmin.php`, `app/Http/Controllers/Admin/AuthController.php`
- Modify: `bootstrap/app.php` (register `routes/admin.php` only when `config('admin.path')` is non-empty, with prefix = that path, middleware group `admin` = `UseAdminSession` before the `web` group)
- Test: `tests/Feature/AdminAccessTest.php`

**Interfaces:**
- Routes (names): `admin.login` GET `/{path}/login` (redirects to Google with `hd`), `admin.callback` GET `/{path}/callback`, `admin.logout` POST `/{path}/logout`, `admin.dashboard` GET `/{path}` (Task 5). Unknown subpaths → 404 via a fallback in the group.
- `UseAdminSession` sets `session.cookie=lwtv-admin`, `session.path='/'.admin.path`, `session.lifetime=admin.recheck_minutes`.
- Session keys: `admin.email`, `admin.checked_at` (unix timestamp). `RequireAdmin`: no email → redirect to `admin.login`; `checked_at` older than `recheck_minutes` → re-run `isMember`; false or `GroupCheckFailed` → flush session, redirect to login with a flash error; true → refresh `checked_at`.
- Callback: `Socialite::driver('google')->user()`; refuse (403 page, session flushed) unless `$user->user['hd'] === admin.domain` and `isMember` is true.

- [ ] **Write failing tests** (mock Socialite with `Socialite::shouldReceive`, bind a fake `GroupMembership`; set `admin.path` to `secret-door` in `setUp` and re-register routes, or define routes always but gate in a test-friendly way — builder picks): `test_admin_is_off_when_path_empty` (404), `test_signed_out_visitor_is_sent_to_login`, `test_wrong_or_missing_domain_is_refused`, `test_non_member_is_refused`, `test_group_api_error_is_refused`, `test_member_is_let_in`, `test_recheck_not_called_before_interval`, `test_recheck_after_interval_signs_out_removed_member`, `test_recheck_api_error_signs_out`, `test_logout_clears_session`, `test_unknown_admin_path_is_404`, `test_public_pages_do_not_set_admin_cookie` (assert no `lwtv-admin` cookie on `/` and `/api/shows`), `test_admin_cookie_is_scoped_to_admin_path`.
- [ ] Run → FAIL. Implement. Run `php artisan test` → PASS.
- [ ] Commit `feat: admin sign-in with Google Group check`.

### Task 5: Sync log page and Sync now

**Files:**
- Create: `app/Http/Controllers/Admin/SyncController.php`, `resources/views/admin/layout.blade.php`, `resources/views/admin/dashboard.blade.php`
- Test: `tests/Feature/AdminSyncTest.php`

**Interfaces:**
- `admin.dashboard` GET: latest 50 runs, newest first; `?failed=1` shows only failures. Each row: started, trigger, status (stalled if `isStalled()`), counts or error. Last success and last failure summarised at the top.
- `admin.sync` POST `/{path}/sync`: `set_time_limit(120)`, `CatalogueSync::run('admin')`; redirect to dashboard with flash `status` text: success counts, failure reason, or "A sync is already running." for `SyncAlreadyRunning`.
- Styling: inline the needed custom properties from `docs/design/system/tokens.css` into a `<style>` block in the layout.

- [ ] **Write failing tests** (signed-in session helper): `test_dashboard_lists_runs_with_failure_reason`, `test_failed_filter_hides_successes`, `test_stalled_run_is_labelled`, `test_sync_now_runs_and_records_admin_run`, `test_sync_now_reports_failure_reason`, `test_sync_now_with_held_lock_does_nothing`, `test_sync_now_requires_post` (GET → 405), `test_sync_now_requires_csrf` (without the test CSRF bypass), `test_sync_now_requires_admin` (signed out → redirect, no run).
- [ ] Run → FAIL. Implement. Run `php artisan test` → PASS.
- [ ] Commit `feat: admin sync log and Sync now`.

### Task 6: Setup docs and deploy wiring

**Files:** `README.md`, `.env.example`, `compose.yaml`, `.gitignore`

- [ ] `.env.example`: the settings block from the spec, `ADMIN_PATH` empty, key path `/run/secrets/google-service-account.json`.
- [ ] `compose.yaml`: `app` mounts the `./secrets` directory read-only at `/run/secrets` (short syntax, so a missing directory doesn't stop the stack); `secrets/` in `.gitignore`.
- [ ] README "Admin" section: what it does; Google Cloud steps (project, enable Cloud Identity API, OAuth client with callback `{APP_URL}/{ADMIN_PATH}/callback`, service account + JSON key); Workspace step (assign Groups Reader role to the service account); `.env` settings; recheck default; that the secret path is a filter, not the lock.
- [ ] `php artisan test` → PASS; `docker compose -f compose.yaml config` validates. Commit `docs: admin setup`.
