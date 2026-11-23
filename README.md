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

   Without a key, `/` returns a 500 error (it can't encrypt cookies), while
   `/api/shows` keeps working.

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

Logs, including sync failures, go to `docker compose -f compose.yaml logs`.

## Sync the catalogue

The scheduler runs `php artisan lwtv:sync` every six hours. To run it now:

```bash
docker compose exec app php artisan lwtv:sync
```

It reads `GET {LWTV_API_URL}/wp-json/lwtv/v1/list/shows`. If the fetch fails,
or comes back empty or in a shape with no usable shows, the command logs the
reason, exits with an error, and leaves the cached shows as they were.

## API

`GET /api/shows` returns the cached catalogue for the PWA. It sets no cookies.

```json
{
  "schema_version": 1,
  "count": 2266,
  "shows": [
    {"id": 27002, "title": ".hack//SIGN", "slug": "hack-sign", "url": "https://lezwatchtv.com/show/hack-sign/", "on_air": false}
  ]
}
```

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
