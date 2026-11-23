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

The gate can't be changed by the PR it judges. `risk-tier.yml` runs on
`pull_request_target`, so GitHub uses the workflow, the scripts and both lists
as they are on the **base** branch. The PR's commits are only read as data.
A change to the gate itself only takes effect after it merges.

> **Remaining gap: a copycat `risk-tier` check.** A PR can add its own
> workflow with a job named `risk-tier` that always passes. GitHub matches
> required checks by name, so check how the ruleset treats two checks with the
> same name (Task 6's proof PRs include this case). That PR touches `.github/`,
> so the real gate still calls it high. When a PR adds or changes a workflow,
> read the workflow before merging, even if everything is green. Fully closing
> this needs an org ruleset that requires `risk-tier.yml` from a protected ref.

> **Biggest gap: agents that use Mika's login.** The gate tells people apart by
> GitHub login. An agent working under Mika's own account (her `gh` login, a
> token she made) *is* Mika as far as GitHub knows. It can add the label
> itself, and the gate counts it. It could even edit `HUMAN_REVIEWERS`. While
> agents run as Mika, the gate holds back other people and bots, **not those
> agents**. To close it, give agents their own GitHub account or App, never
> list it in `HUMAN_REVIEWERS`, and keep its rights below admin.

## 2. Approve a high-risk PR

1. Open the PR. The `risk-tier` job summary lists the files and packages that
   made it high.
2. Read the diff. Really read it: that's the whole point.
3. Add the `human-reviewed` label.

The gate re-runs and goes green. It doesn't trust the label itself. It reads
GitHub's own records of **who** added the label and **when**, and of when the
PR's latest commit was pushed. Your label counts only if you added it after
that push. A PR can't edit those records.

**A new push starts over.** A label from before the latest push doesn't count,
so any new commit needs a new review. The workflow removes the stale label on
each push; add it again once you've read the new changes. Removing the label
withdraws the review.

**Whose label counts:** only logins in the `HUMAN_REVIEWERS` repository
variable, and only real user accounts. A label added by anyone else counts for
nothing; if a person added it, the workflow removes it. A label added by a
workflow token doesn't trigger the gate, so it stays visible on the PR, but it
still counts for nothing. An agent using Mika's own login is the exception: see
the biggest gap above.

**PRs from forks** can't be approved by label: their pushes aren't in this
repo's records. Push the branch to this repo instead.

## 3. Move a path between lists

Edit `.github/risk-tiers.txt` or `.github/low-risk.txt` in **its own PR**. That
PR touches `.github/`, so it's high risk and needs your review, and the new
lists only take effect after it merges (they're read from the base).

Patterns use fnmatch: `*` also crosses `/`. List folders (`app/Models/*`), never
extensions (`*.php`), or you'll allow far more than you meant.

## 4. One-time setup

Done once, after the gate's own PR merges. Each step changes repo settings.

**Protect `production` only after the gate reaches it.** The gate runs from the
base branch, so until `release/0.0.1` merges into `production`, PRs into
`production` get no `risk-tier` check at all. `production` also has no
`.github/osv-scanner.toml` yet, so the OSV PR scan errors on every PR into it,
Dependabot's included. So the ruleset covers `release/**` first, and
`production` is added once the gate is on it.

* Repository variable `HUMAN_REVIEWERS` = `ipstenu` (Settings → Secrets and
  variables → Actions → Variables; comma-separated for more than one).
* Labels `human-reviewed` and `risk: high`.
* One ruleset on `release/**` (then `production`, see above): no deletion, no force-push,
  changes through a PR (merge commit only), and required checks `PHPUnit`,
  `Docker build`, `security`, `risk-tier`, `scan-pr / osv-scan`. No bypass
  actors. GitHub only offers checks it has already seen run, so add the ruleset
  after the workflows have reported at least once.

## 5. Troubleshooting by symptom

**`risk-tier` is red and the `human-reviewed` label is on.**
The label was added before the last push, or by someone not in
`HUMAN_REVIEWERS`. Remove it and add it again yourself. The job summary's
**Human review** line says which. It also shows in the "Check for a human
review" step.

**The reason says "Couldn't tell when … was pushed".**
The newest push in the repository's activity records for the PR's branch isn't
the PR's head commit. That happens with a PR from a fork, if the branch moved
by some route GitHub doesn't log as a push, or if the API failed. It fails
closed. Re-run the job; if it's still red, push a new commit and review that.

**The label vanished right after I added it.**
Your login isn't in `HUMAN_REVIEWERS`, or the variable is unset (unset means
nobody counts). Fix the variable, then add the label again.

**The reason says "risk check failed, treating as high risk".**
The script hit an error (bad lockfile JSON, a git problem). It fails closed, so
the PR is high. Read the error in the "Classify" step; if the PR is fine, review
it and add the label as usual.

**I retargeted a PR from `release/*` to `production` and the checks re-ran.**
That's on purpose. The diff is recomputed against the new base. Your review
still counts if there has been no push since, because it covers the commit you
read. The diff against `production` can be bigger than what you read, but
everything else in it already passed this gate when it merged into the release
branch. That only holds while every `release/**` branch is protected by the
ruleset.
