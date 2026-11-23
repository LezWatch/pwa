# Runbook: the risk gate

Every pull request into `production` or a `release/*` branch gets a `risk-tier`
check. Low-risk PRs pass on their own. High-risk PRs stay red until Mika reads
them and adds the `human-reviewed` label. This page is for doing that, or fixing
it, without having to think too hard.

**Jump to:**

* [High and low](#1-high-and-low) ·
* [Approve a high-risk PR](#2-approve-a-high-risk-pr) ·
* [Move a path between lists](#3-move-a-path-between-lists) ·
* [One-time setup](#4-one-time-setup) ·
* [Troubleshooting](#5-troubleshooting-by-symptom)

---

## 1. High and low

`.github/scripts/risk_tier.py` reads the PR's changed files and sorts the PR
into **high** or **low**.

A PR is **high** if any of these is true:

* It touches a path in `.github/risk-tiers.txt`: CI and this gate, Docker and
  nginx, shell scripts, `bootstrap/`, `config/`, `.env*`, routes, admin and auth
  code, migrations, the test harness, the service worker, agent instructions,
  SVGs, and so on.
* It touches a path that isn't in `.github/low-risk.txt`. Anything nobody
  thought to list is high. That includes most of `app/` (catalogue, models,
  controllers) and factories.
* It adds a symlink or a submodule.
* A lockfile gains a package that wasn't there before, transitive ones included.
* A `composer.json` or `package.json` change goes beyond version numbers:
  scripts, config, new or removed dependencies, git or tarball sources.

A PR is **low** only when every file is on the low-risk list (docs, views, CSS,
front-end JS, images, tests, plain dependency bumps) and none is on the
high-risk list. The high-risk list always wins, so `tests/TestCase.php` is high
even though `tests/*` is low.

Both lists are read from the PR's **base** commit. A PR can't loosen the rules
it is judged by.

## 2. Approve a high-risk PR

1. Open the PR. The `risk-tier` job summary lists the files and packages that
   made it high.
2. Read the diff. Really read it: that's the whole point.
3. Add the `human-reviewed` label.

The workflow turns your label into a `human-review` commit status on the PR's
current head commit, then the gate goes green.

**A new push starts over.** The status belongs to one commit, so any new commit
needs a new review. The workflow removes the stale label on each push; add it
again once you've read the new changes.

**Why an agent's label gets removed:** only logins in the `HUMAN_REVIEWERS`
repository variable count. A label from anyone else, a bot, or an agent using a
repo token is removed and nothing is recorded. Otherwise an agent could approve
its own work.

## 3. Move a path between lists

Edit `.github/risk-tiers.txt` or `.github/low-risk.txt` in **its own PR**. That
PR touches `.github/`, so it's high risk and needs your review, and the new
lists only take effect after it merges (they're read from the base).

Patterns use fnmatch: `*` also crosses `/`. List folders (`app/Models/*`), never
extensions (`*.php`), or you'll allow far more than you meant.

## 4. One-time setup

Done once, after the gate's own PR merges. Each step changes repo settings.

* Repository variable `HUMAN_REVIEWERS` = `ipstenu` (Settings → Secrets and
  variables → Actions → Variables; comma-separated for more than one).
* Labels `human-reviewed` and `risk: high`.
* One ruleset on `production` and `release/**`: no deletion, no force-push,
  changes through a PR (merge commit only), and required checks `PHPUnit`,
  `Docker build`, `security`, `risk-tier`, `scan-pr / osv-scan`. No bypass
  actors. GitHub only offers checks it has already seen run, so add the ruleset
  after the workflows have reported at least once.

## 5. Troubleshooting by symptom

**`risk-tier` is red and the `human-reviewed` label is on.**
The label was added before the last push, or by someone not in
`HUMAN_REVIEWERS`. Remove it and add it again yourself. Check the "Record human
review" step for an error naming who added it.

**The label vanished right after I added it.**
Your login isn't in `HUMAN_REVIEWERS`, or the variable is unset (unset means
nobody counts). Fix the variable, then add the label again.

**The reason says "risk check failed, treating as high risk".**
The script hit an error (bad lockfile JSON, a git problem). It fails closed, so
the PR is high. Read the error in the "Classify" step; if the PR is fine, review
it and add the label as usual.

**I retargeted a PR from `release/*` to `production` and the checks re-ran.**
That's on purpose. The diff is recomputed against the new base, so a green
check from the old base can't carry over.
