# Risk gate and security checks for the PWA

**Status:** draft, waiting for Mika's approval
**Date:** 2026-11-23
**Source:** ported from LezWatch/lwtv-underscores (`.github/`), commit `0e9ae462` of `risk_tier.py`

## Why

Most code in this repo will be written by agents. lwtv-underscores already has a gate that
stops a high-risk change from merging until Mika has looked at it, plus security scans that
run without executing PR code. The PWA has only PHPUnit and a Docker build, and no branch
rules at all, so anything that passes the tests can merge.

## Goals

- A PR that touches anything risky can't merge until Mika records a review on that exact commit.
- Anything nobody thought to classify counts as risky (fail closed).
- A PR can't loosen the rules it is judged by.
- Secrets, vulnerable dependencies and new packages get caught before merge.
- Both `release/*` and `production` are protected, since agent PRs land in `release/*`.

## Non-goals

- Deploy workflows. The PWA doesn't deploy from GitHub yet; when it does, that work adds
  its own `workflow_run` gate like `production.yaml` in lwtv-underscores.
- Static analysis (PHPStan/Larastan) and the PHP-next early-warning job. Worth doing later,
  in their own PR.
- Sharing one copy of the gate between repos. Mika chose to copy it.

## Anti-goals

- A gate an agent can satisfy on its own (adding a label, editing the lists, editing the
  workflow in the same PR).
- A gate so noisy every PR is high risk and the label gets added without reading.

## Approaches considered

1. **Copy the gate into this repo (chosen).** Each repo stays self-contained, and a change
   to one gate can't break both sites. Cost: a fix to `risk_tier.py` has to be applied twice.
2. **One reusable workflow in a shared repo, called by both.** One copy to maintain, but a
   bad change blocks or opens both repos at once, and each caller has to pin it by SHA anyway.
3. **GitHub's built-in CODEOWNERS plus required reviews.** No script, but required reviews
   can't count Mika's own PRs, can't fail closed on unlisted paths, and can't spot a new
   transitive package in a lockfile.

## Design

### Files

| File | What it is |
|------|-----------|
| `.github/scripts/risk_tier.py` | Copied unchanged from lwtv-underscores. It is already repo-agnostic. |
| `.github/scripts/test_risk_tier.py` | Copied. The generic tests stay; the tests that assert on real list entries are rewritten for this repo's paths. |
| `.github/risk-tiers.txt` | High-risk patterns (below). |
| `.github/low-risk.txt` | Low-risk allowlist (below). |
| `.github/workflows/risk-tier.yml` | Copied, with `branches: [production, 'release/**']`. |
| `.github/workflows/ci.yml` | Extended (below). |
| `.github/workflows/osv-scanner-pr.yml`, `osv-scanner-scheduled.yml`, `.github/osv-scanner.toml` | Copied, PR scan on both protected branches. |
| `.github/gitleaks.toml` | Copied (default rules). |
| `.github/dependabot.yml` | composer, npm, github-actions, docker. Weekly. |

### High-risk list (`risk-tiers.txt`)

- **CI and the gate:** `.github/*`, `.githooks/*`.
- **Image, runtime and server:** `Dockerfile`, `.dockerignore`, `compose*.yaml`, `docker/*`,
  `artisan`, `*.sh`, `public/index.php`, `public/.htaccess`, `*.htaccess`.
- **Boot and config:** `bootstrap/*`, `config/*`, `.env*`, `*/.env*`, `secrets/*`, `vite.config.*`.
- **Routes, auth, admin, scheduled jobs:** `routes/*`, `app/Admin/*`, `app/Http/Middleware/*`,
  `app/Providers/*`, `app/Console/*`.
- **Schema:** `database/migrations/*`, `database/seeders/*`.
- **Test harness:** `phpunit.xml*`, `tests/TestCase.php`, `tests/Concerns/*`. A bad change
  here once pointed the tests at the live database and wiped it.
- **Service worker and web manifest** (not written yet, listed now so they start high):
  `public/sw*.js`, `resources/js/sw*`, `*.webmanifest`. A service worker can cache or
  rewrite every page.
- **Toolchain:** `.npmrc`, `.nvmrc`, `pint.json`.
- **Agent instructions:** `.claude/*`, `.superpowers/*`, `CLAUDE.md`, `AGENTS.md`,
  `GEMINI.md` (case-insensitive patterns, as in lwtv-underscores).
- **Repo plumbing:** `.gitignore`, `*/.gitignore`, `.gitattributes`, `.gitmodules`,
  `.gitleaks.toml`, `.gitleaksignore`.
- **SVG:** `*.svg`, since it can carry script.

### Low-risk allowlist (`low-risk.txt`)

- **Docs:** `docs/*`, `*.md`, `LICENSE`.
- **Views and front end:** `resources/views/*`, `resources/css/*`, `resources/js/*`.
- **Static files:** `public/favicon.ico`, `public/robots.txt`, `*.png`, `*.jpg`, `*.gif`, `*.webp`.
- **Tests:** `tests/*` (except the harness files above, which win).
- **Dependencies:** `composer.json`, `composer.lock`, `package.json`, `package-lock.json`.
  The script still makes any non-version manifest change, and any new package, high.

**Not on either list, so high by default:** the rest of `app/` (catalogue, models,
controllers), factories, and anything new. That's deliberately strict, matching how
lwtv-underscores treats its plugin PHP. Moving a folder onto the allowlist later is its own
PR, which is itself high risk.

### CI (`ci.yml`)

- Keep `PHPUnit` and `Docker build`, and add `persist-credentials: false` to every checkout.
- Keep the PR trigger on all branches. Add `edited`, so retargeting a PR to `production`
  re-runs everything against the new base.
- Add **Pint** (`vendor/bin/pint --test`) to the PHPUnit job.
- Add `composer validate` (lockfile matches manifest) and `npm ci` (same for npm).
- Add **gate script tests** (`python3 -m unittest`) to the PHPUnit job, which runs PR code anyway.
- Add a **`security`** job that runs no PR code: `composer audit --locked --no-plugins --no-scripts`,
  `npm audit --package-lock-only --omit=dev --audit-level=high`, and gitleaks (pinned
  version and SHA-256) over the PR's commits only.

### Approval

- Repo variable `HUMAN_REVIEWERS=ipstenu`.
- Labels `human-reviewed` and `risk: high`.
- Same mechanism as lwtv-underscores. Only a listed human's label counts; it is turned
  into a `human-review` commit status on the head SHA, and a new commit needs a new review.

### Branch rules (one ruleset, set by hand after merge)

Applies to `production` and `release/**`:

- No deletion, no force-push.
- Changes come through a PR, merge commit only.
- Required checks: `PHPUnit`, `Docker build`, `security`, `risk-tier`, `scan-pr / osv-scan`.
- No bypass actors.

This differs from lwtv-underscores on purpose. There, the `production` ruleset requires
`lint-test`, `security` and `scan-pr / osv-scan` but not `risk-tier`, and the
`development` ruleset requires no checks. A red risk tier shows up there but doesn't block
the merge button. Here `risk-tier` is required, so the gate actually holds.

Setting a ruleset changes the repo's settings, so it's done by Mika, or by Claude with
Mika's go-ahead, once the workflows are merged and have reported at least once (GitHub
only offers checks it has seen).

## Calls I made (Mika to check)

- **`app/*` is high by default.** Strict, so most feature PRs will need a review. The
  alternative is putting `app/Catalogue/*`, `app/Models/*` and `app/Http/Controllers/*`
  on the allowlist.
- **Dependabot targets `production`,** the default branch, as in lwtv-underscores. While a
  release branch is open, its bumps reach `release/*` only when production is merged back.
- **Pint is added now.** If the code isn't Pint-clean yet, the same PR formats it, in its
  own commit.
- **The gate lands in one PR into `release/0.0.1`.** That PR is high risk by its own rules,
  but the rules don't exist on the base yet, so it can't gate itself. Mika reviews it by hand.

## Left to the builder

- Exact job layout in `ci.yml`, as long as the check names above match.
- Which generic tests in `test_risk_tier.py` need new example paths.
