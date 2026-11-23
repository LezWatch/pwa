# Admin backend: sync log and Sync now

## Intent

Staff need to see whether the six-hourly catalogue sync is working, and why it
failed when it didn't, without shelling into the server. They also need to
re-run a sync on demand after fixing whatever broke. Today a failed sync only
reaches stderr, so there is nothing to look at after the fact.

## Goals

- A staff-only page listing past sync runs, with failures easy to spot and
  their reason visible.
- A "Sync now" button that does the same work as `php artisan lwtv:sync`.
- Only members of the Google Group `pwa-admins@lezwatchtv.com` can get in.

## Non-goals

- Editing shows, or any other admin action beyond syncing.
- Admin user accounts, roles or a users table.
- A queue worker. Sync now runs inside the request.

## Anti-goals (a failure even if it works)

- Anything that touches the public app's privacy promise: the public pages
  must not gain cookies, accounts or tracking because the admin exists.
- Storing staff personal data (emails, names) in the database.
- The app ever seeing a Google password.

## Constraints

- Laravel 13, PHP 8.4, SQLite on the `lwtv-data` volume, cookie sessions,
  file cache. Config is cached in production, so all settings go through
  `config/*.php`, never `env()` in code.
- Tests run on in-memory SQLite; the guard in `tests/TestCase.php` stays.
- Tests never call Google, lezwatchtv.com or lwtv.local.
- lezwatchtv.com is on paid Google Workspace.

## Approaches considered

1. **Google sign-in + Cloud Identity Groups API, service account holding the
   Groups Reader admin role.** Chosen. Passwords stay at Google, 2-step
   verification applies, and the service account can only read groups. No
   domain-wide delegation.
2. Google sign-in + Admin SDK Directory API via domain-wide delegation. Same
   result with broader standing access (impersonates an admin). Rejected.
3. Google sign-in + an email allowlist in `.env`. Cheapest, but ignores the
   group, so membership is managed in two places. Rejected.
4. Google Secure LDAP. Tier-dependent, and the app would collect Google
   passwords in its own form, bypassing 2-step. Rejected.

## Decided

### Where it lives

- Under a secret path on the same host: `https://pwa.lezwatchtv.com/{ADMIN_PATH}`.
  `ADMIN_PATH` is set in `.env`, not committed. If it is empty, no admin
  routes are registered at all (the admin is off).
- The secret path is a filter against scanners, not the lock. Google sign-in
  plus the group check is the lock.
- Anything unknown under the path is a plain 404.

### Access

- Sign-in with Google via Laravel Socialite, `hd=lezwatchtv.com`, and the
  `hd` claim is verified server-side after the callback (the URL parameter
  alone can be stripped).
- After sign-in, the app asks the Cloud Identity Groups API whether the
  signed-in email is a member (transitive membership counts) of
  `ADMIN_GROUP`. Not a member, or the API errors: access denied (fail closed),
  nothing kept in the session.
- Membership is rechecked every `ADMIN_RECHECK_MINUTES` (default `1440`, daily).
  A failed recheck signs the person out.
- The admin uses its own session cookie (its own name, `path` scoped to the
  admin path), so public pages neither see nor set it. The session holds only
  the email and the time of the last membership check, inside Laravel's
  encrypted cookie in the staff member's own browser. Nothing about staff is
  written to the database. The admin session lifetime must be at least the
  recheck interval, or the daily recheck never comes into play.
- Sign out clears the admin cookie.

### Sync runs table

`sync_runs`, written by every sync regardless of trigger:

| column | notes |
| --- | --- |
| id | |
| trigger | `schedule`, `cli` or `admin` (no who) |
| status | `running`, `succeeded`, `failed` |
| started_at, finished_at | finished null while running |
| total, added, updated, removed | null on failure |
| error | the failure reason, null on success |

The existing `Log::error` on failure stays, so stderr logging keeps working.

### Sync now

- The sync logic moves out of the command into one service that the command
  and the button both call, and that writes the `sync_runs` row.
- A cache lock around the sync, so a button press can't run at the same time
  as a scheduled run (the scheduler's `withoutOverlapping` doesn't cover a
  manual run). If the lock is held, the button reports "a sync is already
  running" and does nothing.
- POST only, CSRF-protected. Redirects back to the log with the outcome.

### Settings (`.env`)

```
ADMIN_PATH=
ADMIN_GROUP=pwa-admins@lezwatchtv.com
ADMIN_RECHECK_MINUTES=1440
GOOGLE_WORKSPACE_DOMAIN=lezwatchtv.com
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_SERVICE_ACCOUNT_KEY=   # path to the JSON key inside the container
```

### Tests (required)

Access control: admin off when `ADMIN_PATH` is empty; signed-out visitor is
sent to sign-in; wrong domain refused; non-member refused; API error refused;
member let in; recheck after the interval signs out someone removed from the
group, and doesn't call the API before the interval; public pages set no admin
cookie. Sync now: POST runs the sync and records an `admin` run; a held lock
skips it; failures are recorded with their reason; GET doesn't run anything;
the CLI records a `cli` run.

The Google group check sits behind an interface so tests swap in a fake.

## Calls made after review (not discussed, open to change)

- **Shared lock store.** The app and scheduler containers share only the
  `lwtv-data` volume; the file cache lives in each container's `storage/`, so
  a file-cache lock wouldn't be shared. The sync lock uses the `database`
  cache store (the skeleton's `cache_locks` table in SQLite) explicitly. It
  writes to SQLite only when a sync starts and ends, not on page views. Lock
  TTL 15 minutes, so a crashed run can't block syncs for longer than that.
- **Admin session lifetime** equals `ADMIN_RECHECK_MINUTES`, set by the
  admin middleware alongside the cookie name and path, so the daily recheck
  actually happens. Public routes are unchanged (their existing `web` session
  cookie is outside this work).
- **OAuth callback** lives under the secret path and keeps Socialite's normal
  state check, stored in the admin session. The callback URL registered in
  Google Cloud therefore contains the secret path; that's accepted, since the
  path is a filter, not the lock.
- **Request time.** The sync is one HTTP call (30 s timeout) plus an upsert of
  roughly 2,300 rows. Sync now raises the PHP time limit to 120 s for that
  request; nginx's default 60 s read timeout covers the expected time.
- **Unexpected exceptions** during a sync are recorded as `failed` with their
  message, then rethrown so they still reach the log.
- **Design tokens.** `docs/design/system/` is on `release/0.0.1` but not on
  the PR #20 branch; this branch merges `release/0.0.1` in (it merges cleanly).

## Left to the builder

- Which Google library to use for the service-account token (prefer
  `google/auth` over the full `google/apiclient`).
- How the key file reaches the container in production (a read-only bind
  mount of a gitignored file is the expected answer), plus README setup steps
  for the Google Cloud and Workspace side.
- Telling a scheduled run from a CLI run.
- Page layout. Plain Blade, styled with the tokens from
  `docs/design/system/tokens.css`; no Vite.
- How many runs to show, and whether to filter to failures.
- What to show for a `running` row that is clearly stale (the process died).
- Retention: about 1,500 rows a year, so keeping everything is fine for now.
