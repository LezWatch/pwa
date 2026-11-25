# LezWatch.TV PWA Roadmap

**Goal:** Build the LezWatch.TV filter app as one Laravel 13 app in Docker on Mika's server. It serves the PWA, syncs the catalogue from lezwatchtv.com, and will later host the ratings API in this same app.

**Privacy rule:** the server never receives a user's filters, watched list or ratings choices. Filtering runs on the phone.

**Type:** a roadmap, not an implementation plan. Each item below gets its own spec and plan in `docs/superpowers/` when work starts.

## Decisions so far

- **It's a PWA, not an iOS app** (2026-11-24, recorded on #14). iOS Safari can delete a site's storage after about 7 days without use, unless the app is installed to the home screen. So the app:
  - asks for persistent storage (`navigator.storage.persist()`);
  - prompts people to add it to the home screen;
  - treats the encrypted backup (#12) as the safety net;
  - sends the user into guided setup when storage comes back empty, pointing at Backup > Import.
- **iOS 17 and later.** The anonymous key is Ed25519 only, with no P-256 fallback.
- **Meilisearch** (already installed at search.lezwatchtv.com) is for title search only, later. The catalogue stays on the phone.
- **The catalogue contract** is `docs/api/app-catalogue-v1.md`, agreed in 0.0.2. The samples are `tests/Fixtures/app/*.json`.

## Done

- **0.0.1** (#37): the Docker skeleton, CI, the staff admin with Google sign-in, the design screens and the risk gate.
- **0.0.2 "API Contract"** (#40): the agreed contract, the trigger levels (`low`, `med`, `high`) and the contract tests.
- **0.0.3 "Hardening"** (#41, #42): sync retries, trusted proxies and compose healthchecks.
- **On-device storage, #13**: [PR #43](https://github.com/LezWatch/pwa/pull/43) into `release/0.0.4`. Spec: `docs/superpowers/specs/2026-11-24-on-device-storage-design.md`.

## Next, in order

- [ ] **Merge #43** after the iPhone checks in its description. Then cut the 0.0.4 release (`lwtv-release` skill).
- [ ] **Setup screens, #3 to #6**, built against `tests/Fixtures/app/*.json` and the store's public API (`resources/js/store/index.ts`).
- [ ] **Backup screens, #12.**
  - "Delete my data" calls the server delete (#19) first, with a short timeout, then `wipeEverything()`.
  - After a wipe, a tab still waiting on the delete shows "Close other LezWatch tabs to finish." after 3 seconds.
  - Known gap: if a wipe lands while `getOrCreateIdentity()` is running, the call can resolve with the key the wipe just destroyed. Nothing is saved, but a screen must not sign with that key.
- [ ] **WordPress builds** `lwtv/v2/app/catalogue` and `/availability` (lwtv-underscores#755) from the agreed contract.
- [ ] **A v2 `CatalogueSource`** for those routes, with a wider `shows` table and `/api/shows`. It can be built against the samples now.
- [ ] **Results, filters and show detail screens, #7 to #9.**
  - Empty-state suggestions (B3) are computed on the phone, by testing each loosening of the filters.
- [ ] **The ratings service, #15 to #19**, whenever. It signs with the anonymous key from #13.

## Server and ops chores

- [ ] **Host nginx:** send `X-Forwarded-For $proxy_add_x_forwarded_for` and `X-Forwarded-Proto $scheme` (README, "Run on a server"). Until it does, 0.0.3's trusted-proxy fix has no effect.
- [ ] **`git pull` the main checkout** at `/Users/ipstenu/Development/lezwatch/pwa`, so its `compose.yaml` has the healthchecks.

## Open questions

- **lwtv-underscores#755:** who maintains the services and region lists.

## Deferred by Mika

**The risk gate's gaps** (`docs/runbooks/risk-gate.md`). Don't push these; mention them only if the gate comes up.
- A PR can fake `risk-tier` by name.
- Agents using her login can add `human-reviewed`.
- The `base_ref_changed` reset is live but has never been seen working.

The `lezwatch-agents` App (`docs/plans/2026-11-24-agent-github-app.md`) would close the second gap. It's on hold.

## Working notes

**Releases:**
- Cut `release/x.y.z` from `production` and PR work into it. Release branches require no checks.
- Run the `lwtv-release` skill on the release branch.
- Mika adds `human-reviewed` and merges.
- Milestones: the title is the version, and the description is a short name ("API Contract", "Hardening").

**Tests:**
- Run tests on the host only, never inside the app container.
- Don't run `npm run build`: it rebuilds Docker. Use `npm run build:assets`, `npm test` and `npm run typecheck`.
- A stale `vendor/` in a worktree fails the admin tests. Run `composer install` before trusting a red run.
- `Sleep::fake()` with `Http::retry()` makes retry tests fast, and `Sleep::assertSequence` checks the backoff.

**Live stack:**
- To test compose changes without touching its code or secrets:
  `docker compose -f <worktree>/compose.yaml --project-directory /Users/ipstenu/Development/lezwatch/pwa up -d --no-build`
- When only `depends_on` changes, `scheduler` needs `--force-recreate scheduler`.

**Shell and GitHub:**
- zsh doesn't word-split variables and fails on an unquoted `?` in a URL. Write flags out, and quote API paths.
- There's no `timeout` command on this Mac. Use a bounded loop.
- `gh event listen` returns 403 on this account. Ask Mika to say when a PR merges.
- Check claims about the WordPress side against the lwtv-underscores source at `/Users/ipstenu/Development/lezwatch/lwtv-underscores/plugins/lwtv-plugin/php`.
