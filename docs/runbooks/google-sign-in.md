# Runbook: Google sign-in for the admin

The admin lives at `{APP_URL}/{ADMIN_PATH}`. Staff sign in with Google, and the
app checks that they're in the `pwa-admins@lezwatchtv.com` Google Group. This
page is for doing that setup, or fixing it, without having to think too hard.

**Jump to:** [First-time setup](#1-first-time-setup) ·
[Test locally first](#2-test-locally-first) ·
[Add or remove an admin](#3-add-or-remove-an-admin) ·
[Rotate secrets](#4-rotate-secrets) ·
[Troubleshooting](#5-troubleshooting-by-symptom)

## Before you start

- On the server, every `docker compose` command uses `-f compose.yaml`.
  Locally, leave it off so `compose.override.yaml` is included.
- In production, config and routes are cached when the `app` container starts.
  **Any `.env` change needs a restart** (step 1.6), or the app keeps the old
  values.
- Step 1.4 needs a Google Workspace **super admin**.
- Production logs:

  ```bash
  docker compose -f compose.yaml logs app --since 30m
  ```

---

## 1. First-time setup

### 1.1 Pick the secret path

- [ ] Generate a value:

  ```bash
  openssl rand -hex 24
  ```

- [ ] Write it down somewhere private, such as the team password manager. It
  goes in `.env` as `ADMIN_PATH` in step 1.5, and in the redirect URIs in
  step 1.2.

**Done when:** you have a 48-character hex string saved somewhere other than
the repo.

### 1.2 Google Cloud: enable the API and create the OAuth client

Go to https://console.cloud.google.com and pick (or create) the LWTV project
from the project picker at the top.

- [ ] **APIs & Services → Library**, search for **Admin SDK API**, then
  **Enable**.
- [ ] **Google Auth Platform → Audience** (older consoles: **APIs & Services →
  OAuth consent screen**). Set **User type** to **Internal**. If it asks for
  branding first, the app name `LWTV PWA admin` and a support email are enough.
- [ ] **Google Auth Platform → Clients → Create client**:
  - Application type: **Web application**
  - Name: `LWTV PWA admin`
  - **Authorized redirect URIs**, one line each:
    - `https://pwa.lezwatchtv.com/<ADMIN_PATH>/callback`
    - `http://localhost:8069/<ADMIN_PATH>/callback` (for step 2)
  - **Create**
- [ ] Copy the **Client ID** and **Client secret**. The secret is shown once.

**Done when:** the Admin SDK API shows as enabled, and you have a client ID
ending in `.apps.googleusercontent.com` plus its secret.

### 1.3 Google Cloud: service account and JSON key

- [ ] **IAM & Admin → Service accounts → Create service account**. Name it
  `lwtv-pwa-groups`. Skip both optional steps: it needs **no project roles**.
  Then **Done**.
- [ ] Open the new account, then **Keys → Add key → Create new key → JSON →
  Create**. A `.json` file downloads.
- [ ] Copy the service account's email, for example
  `lwtv-pwa-groups@<project>.iam.gserviceaccount.com`. Step 1.4 needs it.
- [ ] Put the key on the server, next to `compose.yaml`:

  ```bash
  mkdir -p secrets
  ```

  ```bash
  mv ~/Downloads/<downloaded-key>.json secrets/google-service-account.json
  ```

  ```bash
  chmod 644 secrets/google-service-account.json
  ```

  `644` lets the `www-data` user inside the container read it. Keep the
  `secrets/` folder itself away from anyone who shouldn't see it. It is
  gitignored and kept out of the Docker image.

**Done when:** `ls -l secrets/` shows `google-service-account.json` with
`-rw-r--r--`.

### 1.4 Google Admin console: let the service account read groups

Go to https://admin.google.com as a super admin.

- [ ] **Account → Admin roles → Groups Reader → Admins assigned → Assign
  service accounts**.
- [ ] Paste the service account email from 1.3, then **Add → Assign role**.

**Done when:** the service account email is listed under Groups Reader's
assigned admins.

> Domain-wide delegation is **not** needed. Don't set it up.

### 1.5 Fill in `.env`

- [ ] On the server, in `.env`:

  ```
  APP_URL=https://pwa.lezwatchtv.com
  ADMIN_PATH=<from 1.1>
  GOOGLE_CLIENT_ID=<from 1.2>
  GOOGLE_CLIENT_SECRET=<from 1.2>
  GOOGLE_SERVICE_ACCOUNT_KEY=/run/secrets/google-service-account.json
  ```

  Leave these at their defaults unless you mean to change them:
  `ADMIN_GROUP=pwa-admins@lezwatchtv.com`,
  `GOOGLE_WORKSPACE_DOMAIN=lezwatchtv.com`, `ADMIN_RECHECK_MINUTES=1440`.

`APP_URL` matters: the Google redirect URI and the cookie's Secure flag are
both built from it. It must be the public `https://` address, with no trailing
path.

**Done when:** all five lines are in `.env` with real values.

### 1.6 Restart

- [ ] Recreate the containers so they read the new `.env` and re-cache
  config and routes:

  ```bash
  docker compose -f compose.yaml up -d --force-recreate app scheduler
  ```

- [ ] Check that the app sees the key file:

  ```bash
  docker compose -f compose.yaml exec -u www-data app test -r /run/secrets/google-service-account.json && echo readable
  ```

**Done when:** the second command prints `readable`, and
`https://pwa.lezwatchtv.com/<ADMIN_PATH>/login` shows a **Sign in with
Google** button. If that URL is a 404, the restart didn't pick up
`ADMIN_PATH`; see [5.6](#56-changed-env-but-nothing-changed-production).

Then sign in the way step 2.3 describes.

---

## 2. Test locally first

Worth doing before touching production, because it uses the same Google
setup.

### 2.1 Local `.env`

- [ ] Same values as 1.5, except:

  ```
  APP_URL=http://localhost:8069
  ```

- [ ] Put a copy of the key at `secrets/google-service-account.json` in your
  checkout. It's gitignored.
- [ ] Check that `http://localhost:8069/<ADMIN_PATH>/callback` is in the OAuth
  client's redirect URIs (from 1.2).

### 2.2 Start the stack

- [ ] Run:

  ```bash
  npm run build
  ```

### 2.3 Sign in

- [ ] Open `http://localhost:8069/<ADMIN_PATH>/login` and click **Sign in
  with Google**.
- [ ] Pick your `@lezwatchtv.com` account.

**Done when:** you land on a page headed **Sync log**, with your email and a
**Sign out** button in the top bar. Press **Sync now** once; a green
"Synced N shows…" message means the whole chain works.

If you get anything else, go to [Troubleshooting](#5-troubleshooting-by-symptom).

---

## 3. Add or remove an admin

Access comes from the Google Group, not from the app. There's nothing to
change on the server.

### Add

- [ ] https://admin.google.com → **Directory → Groups → pwa-admins → Members →
  Add members**, then add their `@lezwatchtv.com` address.
  (Or https://groups.google.com → **pwa-admins → Members**, if you manage the
  group there.)

**Done when:** they can sign in. Nested groups count: adding a group that
they're in works too.

### Remove

- [ ] Same place: select them, then **Remove member**.

**They aren't locked out straight away.** A signed-in admin is rechecked every
`ADMIN_RECHECK_MINUTES` (default 1440, which is 24 hours). They lose access on
their first page load after the next recheck is due.

**Need them out now?**

1. In `.env`, set `ADMIN_RECHECK_MINUTES=1`.
2. Restart as in 1.6.
3. Their next page load rechecks the group and signs them out.
4. Set it back to `1440`, then restart again.

**Done when:** they're no longer in the group (and, if urgent, you've done the
four steps above).

---

## 4. Rotate secrets

### 4.1 Service account key

The key file is read on every check, so swapping it needs no restart.

- [ ] Google Cloud → **IAM & Admin → Service accounts → lwtv-pwa-groups → Keys
  → Add key → Create new key → JSON**.
- [ ] On the server, replace the file in place:

  ```bash
  mv ~/<new-key>.json secrets/google-service-account.json
  ```

  ```bash
  chmod 644 secrets/google-service-account.json
  ```

- [ ] Sign in to the admin, or wait for someone's recheck, to confirm it
  works.
- [ ] Back in **Keys**, delete the **old** key. Check the key ID against the
  `private_key_id` in the new file, so you don't delete the new one.

**Done when:** a fresh sign-in works and only one key is listed.

### 4.2 OAuth client secret

- [ ] Google Cloud → **Google Auth Platform → Clients → LWTV PWA admin → Add
  secret**. The old secret keeps working for now.
- [ ] Put the new value in `.env` as `GOOGLE_CLIENT_SECRET`.
- [ ] Restart as in 1.6.
- [ ] Sign out and sign in again, to prove the new secret works.
- [ ] Back on the client page, **disable**, then **delete**, the old secret.

**Done when:** a fresh sign-in works and only the new secret is enabled.

---

## 5. Troubleshooting by symptom

First, look at the logs:

```bash
docker compose -f compose.yaml logs app --since 30m | grep -iE "google|admin|group"
```

### 5.1 Google shows `Error 400: redirect_uri_mismatch`

Google got a callback URL that isn't on the OAuth client's list.

- [ ] Click **error details** on Google's page. It shows the `redirect_uri`
  the app sent.
- [ ] Compare it with 1.2's list, character by character: `https` versus
  `http`, the host, the port, and the `ADMIN_PATH` value.
- [ ] If the app sent `http://` or the wrong host, fix `APP_URL` in `.env`
  (1.5), then restart (1.6).
- [ ] If the app's value is right, add exactly that URI to the OAuth client.
  Google can take a few minutes to apply it.

**Done when:** sign-in gets past Google's account picker.

### 5.2 Page says "We could not check your access right now", and the log shows `403` from `admin.googleapis.com`

The service account isn't allowed to read the group. Work down this list:

- [ ] Is the **Admin SDK API** enabled in the same Cloud project as the
  service account? (1.2)
- [ ] Is the service account assigned **Groups Reader** in the Admin console?
  (1.4) Role changes can take a few minutes to apply.
- [ ] Is `ADMIN_GROUP` in `.env` spelled exactly right?
- [ ] Still 403? Groups Reader without domain-wide delegation hasn't been
  confirmed against a real Workspace yet. Google's docs don't say outright
  that it covers this Directory API call. In the Admin console, under
  **Account → Admin roles**, assign the service account **Groups Admin**
  instead. It can also edit groups, so remove Groups Reader once it works.
  No restart needed.

**Done when:** a fresh sign-in reaches the Sync log.

### 5.3 Page says "Your account is not on the admin list", for someone who is a real member

Google answered "not a member".

- [ ] Are they signing in with the same address that's in the group? Aliases
  and personal accounts don't count.
- [ ] Are they a member of `pwa-admins@lezwatchtv.com` itself, or of a group
  inside it that's in the `lezwatchtv.com` domain? Groups from other domains
  don't count.
- [ ] Do the defaults match? `ADMIN_GROUP` should be
  `pwa-admins@lezwatchtv.com`.
- [ ] Were they just added? Wait a few minutes, then try again.

**Done when:** they reach the Sync log.

### 5.4 Page says "We could not check your access right now", and the log says the key is "missing or unreadable"

Look for `Google service account key is missing or unreadable` or
`Could not get a Google access token`.

- [ ] Is the file at `secrets/google-service-account.json`, next to
  `compose.yaml`?
- [ ] Check its permissions:

  ```bash
  ls -l secrets/google-service-account.json
  ```

  If it isn't `-rw-r--r--`, run `chmod 644 secrets/google-service-account.json`.
- [ ] Can `www-data` read it inside the container?

  ```bash
  docker compose -f compose.yaml exec -u www-data app test -r /run/secrets/google-service-account.json && echo readable
  ```

  No output means it can't. Check that the folder mount exists with
  `docker compose -f compose.yaml exec app ls -l /run/secrets`. If it's
  empty, recreate the container (1.6).
- [ ] Does `GOOGLE_SERVICE_ACCOUNT_KEY` in `.env` say
  `/run/secrets/google-service-account.json`?
- [ ] "Could not get a Google access token": the JSON is damaged, or the key
  was deleted in Google Cloud. Make a new one (4.1).

**Done when:** `readable` prints, and a fresh sign-in works.

### 5.5 Page says "Sign in with your lezwatchtv.com account"

The account Google returned isn't a verified `lezwatchtv.com` Workspace
account.

- [ ] Did they choose their personal Gmail in Google's account picker? Sign
  out, then pick the `@lezwatchtv.com` account.
- [ ] Is `GOOGLE_WORKSPACE_DOMAIN` set to the right domain? It should be
  `lezwatchtv.com`, without `@` and without `www`.
- [ ] If Google shows "access blocked" or "app not available" **before** the
  app's message appears: the consent screen is Internal to a different
  Workspace, or the OAuth client is in a project outside the lezwatchtv.com
  organization. Recreate it inside the right one (1.2).

**Done when:** they reach the Sync log.

### 5.6 Changed `.env` but nothing changed (production)

Config and routes were cached when the container started.

- [ ] Restart:

  ```bash
  docker compose -f compose.yaml up -d --force-recreate app scheduler
  ```

- [ ] Confirm the app sees the new values:

  ```bash
  docker compose -f compose.yaml exec app php artisan config:show admin
  ```

**Done when:** `config:show admin` shows the values that are in `.env`.

### 5.7 Other messages

| You see | Means | Do |
| --- | --- | --- |
| A 404 at `/<ADMIN_PATH>` | `ADMIN_PATH` is empty, wrong, or not yet picked up | Check `.env`, then 5.6 |
| "Google sign-in did not complete" | The sign-in was cancelled, the tab was left open too long, or the cookie was blocked | Start again from `/<ADMIN_PATH>/login`, in one tab |
| "Your access needs to be checked again" | A recheck said no, or Google errored during a recheck | Sign in again; if that's refused, use the matching section above |
| "A sync is already running" on Sync now | The scheduler, or another click, holds the sync lock | Wait a minute and refresh; the lock clears itself after 15 minutes at most |
