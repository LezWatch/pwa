# Runbook: the risk gate

Every pull request into `production` or a `release/*` branch gets a `risk-tier`
check. Low-risk PRs pass on their own. High-risk PRs stay red until Mika reads
them and adds the `human-reviewed` label. This page is for doing that, or fixing
it, without having to think too hard.

**Jump to:**

* [High and low](#1-high-and-low) ·
* [How the gate works](#2-how-the-gate-works) ·
* [Approve a high-risk PR](#3-approve-a-high-risk-pr) ·
* [Move a path between lists](#4-move-a-path-between-lists) ·
* [One-time setup](#5-one-time-setup) ·
* [Troubleshooting](#6-troubleshooting-by-symptom) ·
* [Rebuild and recovery](#7-rebuild-and-recovery) ·
* [Later: a GitHub App](#8-later-post-the-verdict-as-a-github-app)

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

## 2. How the gate works

Two workflows:

* **`risk-tier-trigger.yml`** runs on the PR (`pull_request`) and does
  nothing. Its job is to finish, which starts the real gate.
* **`risk-tier.yml`** runs on `workflow_run`. GitHub always runs that file, and
  the gate checks out its scripts and both lists, from the **default branch**
  (`production`), never from the PR. The PR's commits are only read as data. A
  change to the gate only takes effect once it reaches `production`.

The verdict is a `risk-tier` **commit status** on the PR's head commit, posted
with the run's own `GITHUB_TOKEN`. The ruleset requires `risk-tier` by **name**.

> **Known gap: a PR can fake `risk-tier`.** A PR that adds its own job or status
> called `risk-tier` can make itself green, because GitHub matches required
> checks by name. Doing that means editing `.github/workflows/`, which shows up
> plainly in the diff, and every PR gets read before it merges anyway. Posting
> the verdict as a GitHub App closes this gap. It's set aside for now:
> [§8](#8-later-post-the-verdict-as-a-github-app) has the steps.

Each run judges exactly the commit the trigger ran on. It marks that commit
`pending` first, then posts `success` or `failure`, or `error` if it breaks.
It posts `failure` when the commit heads no open PR into a gated branch, or
heads more than one (a status belongs to a commit, so one verdict can't fit two
PRs).

Where it can't post at all, an older status on that commit stands:
* **The trigger didn't run.** The PR deleted or broke `risk-tier-trigger.yml`,
  or dropped `unlabeled` from its triggers so removing your label starts
  nothing. That edit is in `.github/`, so it's high risk and you'll have read it.
* **The run couldn't post.** For example, GitHub gave it a read-only token (see
  the Dependabot entry in [§6](#6-troubleshooting-by-symptom)).

A new commit always starts with no status, so it's blocked until the gate
judges it.

Changing `HUMAN_REVIEWERS` or the lists doesn't re-judge open PRs. Their
current statuses stand until something triggers the gate again (a push, or a
label change).

> **Biggest gap: agents that use Mika's login.** The gate tells people apart by
> GitHub login. An agent working under Mika's own account (her `gh` login, a
> token she made) *is* Mika as far as GitHub knows. It can add the label
> itself, and the gate counts it. It could even edit `HUMAN_REVIEWERS`. While
> agents run as Mika, the gate holds back other people and bots, **not those
> agents**. To close it, give agents their own GitHub account or App, never
> list it in `HUMAN_REVIEWERS`, and keep its rights below admin.

## 3. Approve a high-risk PR

1. Open the PR. Follow the `risk-tier` status's **Details** link: the run's
   summary lists the files and packages that made it high.
2. Read the diff. Really read it: that's the whole point.
3. Add the `human-reviewed` label.

The gate re-runs (give it a minute) and goes green. It doesn't trust the label
itself. It reads GitHub's own records of **who** added the label and **when**,
and of when the PR's latest commit was pushed. Your label counts only if you
added it after that push. A PR can't edit those records.

**A new push starts over.** A label from before the latest push doesn't count,
so any new commit needs a new review. The gate takes the stale label off; add
it again once you've read the new changes. Removing the label withdraws the
review.

**Whose label counts:** only logins in the `HUMAN_REVIEWERS` repository
variable, and only real user accounts. A label added by anyone else, a bot or a
workflow token counts for nothing, and the gate takes it off the next time it
runs. An agent using Mika's own login is the exception: see the biggest gap
above.

**PRs from forks** get no `risk-tier` status at all, so they stay blocked.
Push the branch to this repo instead.

## 4. Move a path between lists

Edit `.github/risk-tiers.txt` or `.github/low-risk.txt` in **its own PR**. That
PR touches `.github/`, so it's high risk and needs your review. The gate reads
the lists from `production`, so the change takes effect once it reaches
`production`, not when it merges into a release branch.

Patterns use fnmatch: `*` also crosses `/`. List folders (`app/Models/*`), never
extensions (`*.php`), or you'll allow far more than you meant.

## 5. One-time setup

Each step changes repo settings.

**The gate only runs once it's on `production`.** Until `release/0.0.1` merges
into `production`, no PR gets a `risk-tier` status. `production` also has no
`.github/osv-scanner.toml` until then, so the OSV PR scan errors on every PR
into it, Dependabot's included.

1. **Repository variable** `HUMAN_REVIEWERS` = `ipstenu` (Settings → Secrets
   and variables → Actions → Variables; comma-separated for more than one).
2. **Labels** `human-reviewed` and `risk: high`.
3. **Merge `release/0.0.1` into `production`**, so the gate is on the default
   branch.
4. **The ruleset**, on `release/**` and `production`: no deletion, no
   force-push, changes through a PR (merge commit only), and required checks
   `PHPUnit`, `Docker build`, `security`, `scan-pr / osv-scan` and
   `risk-tier`. No bypass actors.
   * Leave **"Do not require status checks on creation"** unticked (API:
     `do_not_enforce_on_create: false`). Otherwise someone could push a new
     `release/x` branch full of unreviewed commits, and the gate's retarget
     logic assumes everything on a release branch was reviewed.
   * The whole ruleset is written down in
     [`risk-gate-ruleset.json`](risk-gate-ruleset.json), so it can be set with
     one command (see [§7](#7-rebuild-and-recovery), "Put the ruleset back").
     If you change the ruleset in the UI, change that file to match, in a PR.

## 6. Troubleshooting by symptom

**`risk-tier` says "Expected — Waiting for status to be reported".**
The gate hasn't posted anything for this commit yet. Give it a minute after a
push or a label change. If it stays:
* Is the gate on `production`? It only runs from there.
* Did "Risk tier trigger" run on the PR? If the PR deleted or broke
  `risk-tier-trigger.yml`, the gate never starts. That's a red flag in itself.
* Did "Risk tier" run (Actions tab, on `production`)? If it ran but posted
  nothing, its log says why: PR from a fork, PR not into a gated branch, or a
  `403` posting the status (a read-only token).

**`risk-tier` is red and the `human-reviewed` label is on.**
The gate hasn't re-run since your label, or it ran and the label didn't count.
The status description and the run summary's **Human review** line say which.

**The reason says "Couldn't tell when … was pushed".**
The newest push in the repository's activity records for the PR's branch isn't
the PR's head commit. That happens if the branch moved by some route GitHub
doesn't log as a push, or if the API failed. It fails closed. Re-run the gate;
if it's still red, push a new commit and review that.

**The label vanished right after I added it.**
Your login isn't in `HUMAN_REVIEWERS`, or the variable is unset (unset means
nobody counts), or there was a push just before your label. Fix the variable if
needed, then add the label again.

**A Dependabot PR sits at "Expected — Waiting for status to be reported".**
Check the "Risk tier" run on `production`. If "Mark pending" failed with a
`403`, GitHub gave a run that Dependabot started a read-only token, so it
couldn't post. The ruleset has no bypass, so it can't be merged as is: close
it and open the same bump yourself, from a branch in this repo.

**`risk-tier` says "No open PR into a gated branch has this commit as its head".**
The branch moved on (a newer run judges the newer commit), the PR is from a
fork, or it isn't into `production` or `release/*`.

**`risk-tier` says "This commit heads 2 gated PRs".**
The same commit is the head of two PRs into gated branches. Close one, or push
a new commit to one of them.

**`risk-tier` shows "error: The risk tier gate failed".**
Something in the gate broke after it started. Open the run from the status's
Details link. It fails closed; re-run it once the cause is fixed.

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

## 7. Rebuild and recovery

For when a piece of the gate is lost or broken. Each fix changes settings, so
do it yourself or tell an agent "go ahead" for that one step.

The gate itself is all in the repo: the two workflows, the scripts in
`.github/scripts/`, and the two lists. Outside the repo there are only the
`HUMAN_REVIEWERS` variable, the two labels and the ruleset, all in
[§5](#5-one-time-setup).

### Every PR is blocked and nothing can merge

The ruleset wants `risk-tier`, and the gate isn't posting it (say, a broken
workflow on `production`). With no bypass actors, the PR that would fix the
gate is blocked too. A repo admin can always edit the ruleset, so take
`risk-tier` out, fix the gate, then put it back.

1. Find the ruleset's ID (it's `27944135` unless it was recreated):

   ```bash
   gh api repos/LezWatch/pwa/rulesets --jq '.[] | {id, name}'
   ```

2. Drop `risk-tier`, keeping every other rule:

   ```bash
   jq '(.rules[] | select(.type == "required_status_checks") | .parameters.required_status_checks) |= map(select(.context != "risk-tier"))' docs/runbooks/risk-gate-ruleset.json | gh api -X PUT repos/LezWatch/pwa/rulesets/<id> --input -
   ```

3. While it's out, high-risk PRs aren't gated. Merge only the fix, then put
   the ruleset back (below) straight away.

### Put the ruleset back, or recreate it

* **Update the existing ruleset:**

  ```bash
  gh api -X PUT repos/LezWatch/pwa/rulesets/<id> --input docs/runbooks/risk-gate-ruleset.json
  ```

* **Recreate it after it was deleted:**

  ```bash
  gh api -X POST repos/LezWatch/pwa/rulesets --input docs/runbooks/risk-gate-ruleset.json
  ```

Check it: `gh api repos/LezWatch/pwa/rulesets/<id> --jq '.rules[] | select(.type == "required_status_checks") | .parameters.required_status_checks[].context'`
should list all five checks.

### Check the gate works

1. Re-run the gate: on any open PR into `production` or `release/*`, open the
   **Risk tier trigger** run and choose **Re-run all jobs**. Then watch the
   **Risk tier** run on `production` in the Actions tab.
2. If **Risk tier** didn't start after the re-run, add and remove any label
   other than `human-reviewed` on the PR. That fires the trigger too.
3. Confirm the status landed on the PR's head commit:

   ```bash
   gh api repos/LezWatch/pwa/commits/<PR head SHA>/statuses --jq '.[] | select(.context == "risk-tier") | {state, description}' | head -2
   ```

## 8. Later: post the verdict as a GitHub App

Set aside for now (one developer, who reads every PR). This is the fix for the
known gap in [§2](#2-how-the-gate-works): a PR faking its own `risk-tier`. The
verdict gets posted by an App, and the ruleset accepts `risk-tier` from that
App only, so a copycat from GitHub Actions doesn't count. The workflow code
for it existed once: `.github/workflows/risk-tier.yml` as merged in PR #31
(`git show 44be675:.github/workflows/risk-tier.yml`).

**This is a different App from the agents' one** in
`docs/plans/2026-11-24-agent-github-app.md`. Never give agents this App's key,
or they could post `risk-tier` themselves.

1. **Make the App.** In the LezWatch org: Settings → Developer settings →
   GitHub Apps → New GitHub App.
   * Name: `LezWatch risk gate`. Homepage: the repo URL.
   * Leave everything under "Identifying and authorizing users" empty and
     unticked.
   * Webhook: untick **Active**.
   * Repository permissions: **Commit statuses: Read and write**. Nothing else.
   * Where can it be installed: **Only on this account**.
   * Create it, then generate a private key. Make one 1Password item, **LWTV
     PWA risk gate App**, holding the App ID, the Client ID and the `.pem`.
     Delete the download.
   * Install App → LezWatch → **Only select repositories** → `pwa`.
2. **The environment.** Repo Settings → Environments → New environment →
   `risk-gate`. Deployment branches: **Selected branches and tags** → one rule,
   type **Branch**, name `production`. Then:

   ```bash
   gh variable set RISK_GATE_APP_CLIENT_ID --env risk-gate --repo LezWatch/pwa --body "<Client ID>"
   ```

   ```bash
   op read "<1Password reference to the .pem>" | gh secret set RISK_GATE_APP_PRIVATE_KEY --env risk-gate --repo LezWatch/pwa
   ```

3. **The workflow**, in a PR: put back from PR #31's version the
   `environment: risk-gate` line, the "Token for the risk-gate App" step
   (`actions/create-github-app-token`), and `GH_TOKEN="$APP_TOKEN"` on the four
   status posts. Drop `statuses: write` from `permissions`.
4. **The ruleset.** Once the App has posted a status, add `"integration_id":
   <App ID>` to the `risk-tier` entry in
   [`risk-gate-ruleset.json`](risk-gate-ruleset.json), and put the ruleset
   back (§7). Statuses from anything else then don't count.
5. **Check it:** after a re-run, the status's creator is the App's bot, not
   `github-actions[bot]`:

   ```bash
   gh api repos/LezWatch/pwa/commits/<PR head SHA>/statuses --jq '.[] | select(.context == "risk-tier") | .creator.login' | head -1
   ```

**Once it's in, the App needs upkeep.** If the App is deleted or recreated, it
gets a new App ID, and the ruleset waits for the old one forever: every PR is
blocked until you change `integration_id` (§7). To rotate the key, generate a
new one in the App's settings, replace the secret, then delete the old key. If
the key leaked, delete the old key first.
