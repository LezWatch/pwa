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
* [Rebuild and recovery](#7-rebuild-and-recovery)

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
as the **risk-gate GitHub App**. The ruleset requires `risk-tier` *from that
App only*. A PR could add its own job or status called `risk-tier`, but it
would come from GitHub Actions, not the App, so it doesn't count. The App's key
is stored in the `risk-gate` environment, which only `production` can use, so
a workflow in a PR can't reach it.

Each run judges exactly the commit the trigger ran on. It marks that commit
`pending` first, then posts `success` or `failure`, or `error` if it breaks.
It posts `failure` when the commit heads no open PR into a gated branch, or
heads more than one (a status belongs to a commit, so one verdict can't fit two
PRs).

Where it can't post at all, an older status on that commit stands:
* **The trigger didn't run.** The PR deleted or broke `risk-tier-trigger.yml`,
  or dropped `unlabeled` from its triggers so removing your label starts
  nothing. That edit is in `.github/`, so it's high risk and you'll have read it.
* **The App token failed.** Check the environment's variable and secret.

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

Each step changes repo or org settings.

**The gate only runs once it's on `production`.** Until `release/0.0.1` merges
into `production`, no PR gets a `risk-tier` status. `production` also has no
`.github/osv-scanner.toml` until then, so the OSV PR scan errors on every PR
into it, Dependabot's included.

1. **Repository variable** `HUMAN_REVIEWERS` = `ipstenu` (Settings → Secrets
   and variables → Actions → Variables; comma-separated for more than one).
2. **Labels** `human-reviewed` and `risk: high`.
3. **The GitHub App.** In the LezWatch org: Settings → Developer settings →
   GitHub Apps → New GitHub App.
   * Name: `LezWatch risk gate` (any name). Homepage: the repo URL.
   * Webhook: untick **Active**.
   * Repository permissions: **Commit statuses: Read and write**. Nothing else
     (Metadata: read is added automatically).
   * Where can it be installed: **Only on this account**.
   * Create it. Note the **App ID** (a number) and the **Client ID**.
   * Generate a private key (it downloads a `.pem`). Make one 1Password item,
     **LWTV PWA risk gate App**, holding all three things a rebuild needs: the
     App ID, the Client ID, and the `.pem` as an attached file.
   * Install App → LezWatch → **Only select repositories** → `pwa`.
4. **The environment.** Repo Settings → Environments → New environment →
   `risk-gate`.
   * Deployment branches and tags: **Selected branches and tags** → add a rule,
     ref type **Branch** (not Tag), name `production`. Nothing else.
   * Environment variable `RISK_GATE_APP_CLIENT_ID` = the Client ID.
   * Environment secret `RISK_GATE_APP_PRIVATE_KEY` = the whole `.pem` file.
   * Then delete the downloaded `.pem`.
5. **Merge `release/0.0.1` into `production`**, so the gate is on the default
   branch.
6. **The ruleset**, on `release/**` and `production`: no deletion, no
   force-push, changes through a PR (merge commit only), and required checks
   `PHPUnit`, `Docker build`, `security`, `scan-pr / osv-scan`, plus
   `risk-tier` **from the risk-gate App** (in the UI, pick the App as the
   source; in the API, `integration_id` = the App ID). No bypass actors.
   * Leave **"Do not require status checks on creation"** unticked (API:
     `do_not_enforce_on_create: false`). Otherwise someone could push a new
     `release/x` branch full of unreviewed commits, and the gate's retarget
     logic assumes everything on a release branch was reviewed.
   * The UI may only offer the App as a source once it has posted a
     `risk-tier` status. If it isn't listed, open one test PR first, or set it
     with the API.
   * The whole ruleset is written down in
     [`risk-gate-ruleset.json`](risk-gate-ruleset.json), so the API route is one
     command (see [§7](#7-rebuild-and-recovery), "Put the ruleset back"). If you
     change the ruleset in the UI, change that file to match, in a PR.

## 6. Troubleshooting by symptom

**`risk-tier` says "Expected — Waiting for status to be reported".**
The gate hasn't posted anything for this commit yet. Give it a minute after a
push or a label change. If it stays:
* Is the gate on `production`? It only runs from there.
* Did "Risk tier trigger" run on the PR? If the PR deleted or broke
  `risk-tier-trigger.yml`, the gate never starts. That's a red flag in itself.
* Did "Risk tier" run (Actions tab, on `production`)? If it ran but posted
  nothing, its log says why: PR from a fork, PR not into a gated branch, or the
  App token failed (check the environment's variable and secret).

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
Check the "Risk tier" run on `production`. If it failed at "Token for the
risk-gate App", GitHub didn't give the environment's secret to a run that
Dependabot started. The ruleset has no bypass, so it can't be merged as is:
close it and open the same bump yourself, from a branch in this repo.

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

For when a piece of the gate is lost or broken. Every value you need is in
the 1Password item **LWTV PWA risk gate App** (App ID, Client ID, `.pem`). In
the commands below, `<op ref>` is that item's `.pem` file reference
(1Password → the file → Copy Secret Reference). Each fix changes settings, so
do it yourself or tell an agent "go ahead" for that one step.

### Every PR is blocked and nothing can merge

The ruleset wants `risk-tier` from the App, and the App isn't posting. With
no bypass actors, the PR that would fix the gate is blocked too. A repo admin
can always edit the ruleset, so take `risk-tier` out, fix the gate, then put
it back.

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

The file has `"integration_id": 0` where the App ID goes. Fill it in on the
way to the API.

* **Update the existing ruleset:**

  ```bash
  jq --argjson app <App ID> '(.rules[] | select(.type == "required_status_checks") | .parameters.required_status_checks[] | select(.context == "risk-tier") | .integration_id) = $app' docs/runbooks/risk-gate-ruleset.json | gh api -X PUT repos/LezWatch/pwa/rulesets/<id> --input -
  ```

* **Recreate it after it was deleted:** the same command, ending in
  `gh api -X POST repos/LezWatch/pwa/rulesets --input -` instead.

Check it: `gh api repos/LezWatch/pwa/rulesets/<id> --jq '.rules[] | select(.type == "required_status_checks") | .parameters.required_status_checks'`
should list all five checks, `risk-tier` with the App ID.

### Rotate the key (leaked, or lost)

An App can have several keys at once, so swap them without downtime:

1. App settings (LezWatch org → Settings → Developer settings → GitHub Apps
   → LezWatch risk gate) → **Private keys** → **Generate a private key**.
2. Attach the new `.pem` to the 1Password item, replacing the old one, and
   delete the download.
3. Put it in the environment, straight from 1Password, so it never sits on
   disk:

   ```bash
   op read "<op ref>" | gh secret set RISK_GATE_APP_PRIVATE_KEY --env risk-gate --repo LezWatch/pwa
   ```

4. Re-run the gate on an open PR (below) and check it posts.
5. Back in **Private keys**, delete the old one.

**If the key leaked,** delete the old key first, before step 1. The gate then
errors until the new secret is in, which is safe: it fails closed.

### The environment was deleted

The secret and variable went with it. Recreate all of it from 1Password:

```bash
gh api -X PUT repos/LezWatch/pwa/environments/risk-gate --input - <<'JSON'
{"deployment_branch_policy": {"protected_branches": false, "custom_branch_policies": true}}
JSON
```

```bash
gh api -X POST repos/LezWatch/pwa/environments/risk-gate/deployment-branch-policies -f name=production -f type=branch
```

```bash
gh variable set RISK_GATE_APP_CLIENT_ID --env risk-gate --repo LezWatch/pwa --body "<Client ID>"
```

```bash
op read "<op ref>" | gh secret set RISK_GATE_APP_PRIVATE_KEY --env risk-gate --repo LezWatch/pwa
```

Check that only `production` can use it:
`gh api repos/LezWatch/pwa/environments/risk-gate/deployment-branch-policies --jq '.branch_policies[] | {name, type}'`
should print exactly one line, `production` and `branch`.

### The App was deleted

A new App gets a **new App ID and Client ID**, so it touches everything:

1. Make it again: [§5](#5-one-time-setup), step 3. Update the 1Password item
   with the new App ID, Client ID and `.pem`.
2. Replace the environment's variable and secret (the two `gh variable` /
   `gh secret` commands above).
3. The ruleset still names the **old** App ID, so every PR is blocked. Point
   it at the new one: "Put the ruleset back" above, with the new App ID.

### Check a rebuild worked

1. Re-run the gate: on any open PR into `production` or `release/*`, open the
   **Risk tier trigger** run and choose **Re-run all jobs**. Then watch the
   **Risk tier** run on `production` in the Actions tab.
2. Confirm the status came from the App, not from GitHub Actions:

   ```bash
   gh api repos/LezWatch/pwa/commits/<PR head SHA>/statuses --jq '.[] | select(.context == "risk-tier") | {state, by: .creator.login}' | head -1
   ```

   `by` should be the App's bot name (`lezwatch-risk-gate[bot]` for an App
   named "LezWatch risk gate"), never `github-actions[bot]`.
3. If **Risk tier** didn't start after the re-run, add and remove any label
   other than `human-reviewed` on the PR. That fires the trigger too.
