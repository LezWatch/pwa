# Laravel on Docker: local skeleton (design)

Date: 2026-11-23
Status: draft, awaiting approval

## Intent

LWTV PWA will be one Laravel app that serves the PWA, fetches and caches the
LezWatch.TV catalogue, and later hosts the ratings API (#2, #15-#19). It must
install on the production server as a self-contained Docker stack, the same way
it runs locally. This first step is a throwaway-light skeleton that proves the
stack and the data path work on a laptop.

## Goals

- `docker compose up` brings the app up locally at `http://localhost:8080`.
- An artisan command fetches show data from lezwatchtv.com and caches it.
- The scheduler runs that command on a timer.
- One plain page lists the cached shows; `GET /api/shows` returns them as JSON
  (the endpoint the PWA will later read).
- The same images run on the server behind the existing host nginx.

## Non-goals

- PWA UI, service worker, manifest, IndexedDB, signing (#1, #3-#13).
- Ratings service (#2, #15-#19).
- Picking the frontend stack (Blade/Alpine vs Vue via Vite).
- Deploying to the server, CI, backups.

## Anti-goals

- The server must never receive a user's filters, watched list or ratings
  history. Filtering stays on the device. Nothing in this skeleton may assume
  server-side filtering.
- No local-only Docker setup that has to be rebuilt for production.

## Constraints

- PHP-FPM, for consistency with the WordPress stack (Mika's decision).
- Host nginx terminates TLS and proxies to the stack; the stack only listens
  on a local port.
- The compact app API (LezWatch/lwtv-underscores#755) does not exist yet.

## Approaches considered

1. Laravel Sail locally, separate production setup later. Rejected: Sail is
   dev-only, so Docker would be built and debugged twice.
2. **One production-shaped Compose stack used locally and on the server.
   Chosen.** Same images everywhere; a dev override adds live code mounts.
3. FrankenPHP single container. Rejected in favour of PHP-FPM for consistency
   with WordPress.

## Design

Containers (one process each):

- `app`: PHP 8.4 FPM (official image) running current Laravel. Code baked into
  the image.
- `web`: nginx (official image). Serves `public/` static files, passes PHP to
  `app:9000`. Publishes `127.0.0.1:8080`.
- `scheduler`: same image as `app`, runs `php artisan schedule:work`.

Data: SQLite file on a named volume (`storage/`). Postgres is a later swap if
rating writes need it.

Files:

- Laravel at the repo root (it is the whole product, so #2's `server/`
  directory no longer applies).
- `docker/php/Dockerfile`, `docker/nginx/default.conf`.
- `compose.yaml` (production shape) + `compose.override.yaml` (local: bind
  mount source, `APP_DEBUG=true`).

Data path:

- A `CatalogueSource` interface with one implementation reading today's
  lezwatchtv.com REST routes (verify which: `lwtv/v1/export/shows` or `wp/v2`).
  When #755 ships, add a second implementation and switch.
- `php artisan lwtv:sync` calls the source, normalises to a small Show shape
  (id, title, year, slug), stores in SQLite.
- Scheduled every few hours.

## Left to the builder

- Exact Laravel and nginx image tags.
- Which existing LWTV route to read, and the fallback to a checked-in fixture
  if it is unsuitable.
- Sync frequency and HTTP timeout/retry details.
