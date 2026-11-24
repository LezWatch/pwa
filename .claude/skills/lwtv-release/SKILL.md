---
name: lwtv-release
description: Cut a LezWatch.TV PWA release PR (release/x.y.z into production). Runs rad-shipshape-pr's prepare phase, then the checks CI runs (Pint, PHPUnit on the host, the gate tests, the Vite build) so a broken release never reaches the PR, then ships. Use when the user says lwtv-release, "cut the release", "ship 0.x.y", or wants a release PR for the PWA.
---

# LWTV Release (PWA)

A thin wrapper around `rad-shipshape-pr`. ShipShape bumps `package.json` (the only version
source here; `npm version` keeps `package-lock.json` in step) and writes `CHANGELOG.md`. This
wrapper runs CI's checks in between, so a release PR only opens on a tree that already passes.

Unlike the theme, nothing the build makes is committed: Vite writes to `public/build/`, which
is git-ignored, and no other file carries the version. So the build here is a check, not a
stamping step, and the ship step normally has no `--include` files.

Invocation: `lwtv-release [<version>] [shipshape flags]`. Pass the version and any ShipShape
flags (`--no-bump`, `--no-changelog`, `--no-testing`, `--draft`, …) straight through. Don't
pass `--phase` or `--include`; this skill sets those.

## Before you start

- **Branch.** Run from the release branch (`release/<version>`), not `production`. The PR
  goes from that branch into `production`, the base in `.claude/shipshape.json`. ShipShape
  refuses to run when the branch is the base.
- **Clean tree.** ShipShape's commit holds only what it wrote. Commit or set aside other
  work first, or the ship step stops and names it.
- **The version must go up.** ShipShape refuses a version equal to the current one. If
  `package.json` already says the version being released, set it back to the previous one
  (`0.0.0` for the first release) and let ShipShape bump it, so the lockfile follows.

## Steps

### 1. Prepare

Invoke `rad-shipshape-pr` with the user's arguments plus `--phase prepare`. Follow it fully,
including its discovery confirmation (the config in `.claude/shipshape.json` usually answers
it). If prepare stops for any reason, stop here too.

### 2. Check

Run what the CI `PHPUnit` job runs, from the repo root:

```bash
cd "$(git rev-parse --show-toplevel)"
vendor/bin/pint --test
php artisan test
python3 -m unittest discover -s .github/scripts -p 'test_*.py'
source ~/.nvm/nvm.sh && nvm use 22
npm run build:assets
```

- **Run the tests on the host, never in the app container.** `docker compose exec app php
  artisan test` once pointed the suite at the live SQLite and wiped it. `phpunit.xml` and
  `tests/TestCase.php` guard against that now, but don't lean on the guard.
- **Use `build:assets`, not `npm run build`.** Here `build` rebuilds and restarts the Docker
  stack. A release check doesn't want that, and CI's `Docker build` job covers the image.
- Node 22 matches CI. There's no `.nvmrc` yet, so name the version.

If any check fails, stop and report the output. Don't ship, and don't try to fix it here:
the fix is its own PR into the release branch.

### 3. Confirm the tree

```bash
cd "$(git rev-parse --show-toplevel)"
source "$(git rev-parse --absolute-git-dir)/shipshape/env"
{ git -c core.quotePath=false diff --name-only HEAD; git -c core.quotePath=false ls-files --others --exclude-standard; } \
  | sort -u | comm -23 - <(sort -u "$STATE/files")
```

These are files the checks changed outside what ShipShape wrote. Expect **none**: Pint runs
with `--test`, and Vite's output is ignored.

Anything listed (for example `public/build/` showing up because `.gitignore` changed, or a
file the tests wrote) → show it to the human and ask whether it belongs in the release commit
before going on. Never revert, stash or delete it yourself.

### 4. Ship

Invoke `rad-shipshape-pr --phase ship`, with one `--include <path>` per file from step 3 that
the human said to keep (usually none). In the PR body's Notes, say that Pint, PHPUnit, the gate
tests and the Vite build passed locally on this commit.

### 5. Report

ShipShape's report, plus:

- which checks ran and passed, and any `--include` files;
- **the risk gate.** A release PR into `production` is high risk by this repo's own lists
  (it carries `.github/`, `.claude/`, config and app code), so `risk-tier` stays red until
  Mika adds the `human-reviewed` label. Say so, and **never add that label yourself**, even
  though `gh` runs as Mika: the gate can't tell an agent's label from hers.
