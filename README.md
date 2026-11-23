# LWTV PWA

The LezWatch.TV filter app. A Laravel app that serves the PWA, keeps a cached
copy of the LezWatch.TV catalogue, and will later host the ratings API.

It runs as a small Docker stack (PHP-FPM, nginx and a scheduler) so it can sit
on the server behind the host nginx, the same way it runs locally.

## Run locally

Requires Docker Desktop. PHP and Composer on the host are only needed for
running tests outside Docker.

1. Copy the environment file and point it at your Local site:

   ```bash
   cp .env.example .env
   ```

   In `.env`, set:

   ```
   LWTV_API_URL=https://lwtv.local
   LWTV_API_VERIFY_TLS=false
   ```

2. Install PHP dependencies (the local override mounts the source, including
   `vendor/`, into the containers):

   ```bash
   composer install
   ```

3. Generate an app key:

   ```bash
   php artisan key:generate
   ```

4. Start the stack:

   ```bash
   docker compose up -d --build
   ```

5. Open http://localhost:8069. The port comes from `APP_PORT` in `.env`.

`compose.override.yaml` is loaded automatically. It mounts the source for
live editing and maps `lwtv.local` to your Mac so the containers can reach the
Local site. It is for local use only.

## Run on a server

Use only the production file, so the override is skipped:

```bash
docker compose -f compose.yaml up -d --build
```

The stack listens on `127.0.0.1:${APP_PORT}`. Proxy to it from the host nginx,
which keeps handling the domain and TLS.
