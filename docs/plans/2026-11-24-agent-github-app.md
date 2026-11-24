# Agent GitHub App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agents (Claude Code sessions) work on GitHub as `lezwatch-agents[bot]` instead of as Ipstenu. A label an agent adds then counts for nothing in the risk gate.

**Architecture:**
- **The App.** A GitHub App owned by the LezWatch org, with only the permissions agents need. It's separate from the risk-gate App, which must never be shared with agents.
- **The token helper.** `tools/agent-token/agent_token.py` reads the App's key from 1Password and creates a one-hour installation token. It caches the token, and acts as a git credential helper too.
- **Claude Code settings.** The `env` block points agent sessions at the helper and away from Mika's own `gh` and git logins.

**Tech Stack:**
- GitHub Apps (JWT, then an installation access token)
- Python 3 stdlib (`unittest`, `urllib`, `json`), plus the `openssl` CLI for RS256 signing
- 1Password CLI (`op`)
- `gh`, git credential helpers, and the `env` block in Claude Code's `settings.json`

**Spec:** None. The design comes from the 2026-11-24 conversation, in which Mika chose an App over a machine user. It closes the gap described under "Biggest gap" in `docs/runbooks/risk-gate.md` §2.

## Global Constraints

- **App name:** `lezwatch-agents`. GitHub shows it as `lezwatch-agents[bot]`.
- **App permissions:**
  - Repository permissions: Contents: Read and write; Pull requests: Read and write; Issues: Read and write; Metadata: Read.
  - Nothing else. **No** Administration, Secrets, Variables, Environments, Actions or Commit statuses.
  - **Workflows** stays off unless Mika decides otherwise in chat. Without it, GitHub refuses any push that changes `.github/workflows/`.
- **The rest of the App form:**
  - Webhook: **Active** unticked.
  - Every box under "Identifying and authorizing users" unticked, with the redirect URI blank.
  - Installable "Only on this account".
- **Installation:** Only select repositories. Start with `pwa`.
- **The private key** exists only in 1Password, as the item `LezWatch agents App key`, attached as a file. It's never written to disk outside 1Password. The helper hands it to `openssl` on stdin.
- **The token cache** is `~/.cache/lezwatch-agents/token.json`, mode `0600`. A new token is minted when the cached one has under 5 minutes left.
- **No App ID, Client ID, installation ID or token is committed to the repo.** The helper reads its settings from the environment:
  - `LEZWATCH_AGENT_APP_ID`
  - `LEZWATCH_AGENT_KEY_REF` (an `op://` reference)
  - `LEZWATCH_AGENT_OWNER` (defaults to `LezWatch`)
- **The helper runs from an installed copy** at `~/.local/bin/agent-token`, never straight from a checkout. That way a branch can't change what agents run.
- **The risk-gate App, `HUMAN_REVIEWERS` and the ruleset stay as they are.** The App is never listed in `HUMAN_REVIEWERS` and never made a bypass actor.
- Each change to GitHub settings or Claude Code settings needs Mika's go-ahead in chat (Tasks 1 and 3). Mika handles the private key herself.

## Review Focus

1. **A wrong or missing 1Password item, or a locked `op`.** Expected: `agent-token` exits non-zero with a one-line error on stderr and prints nothing on stdout. Git and `gh` then fail, and nothing falls back to Mika's login. Pinned by `test_key_failure_prints_no_token` (Task 2).
2. **A corrupt cache file, or one with the wrong permissions.** Expected: the helper ignores it and mints a new token. Pinned by `test_corrupt_cache_is_ignored` and `test_cache_written_0600` (Task 2).
3. **A token about to expire mid-command.** Expected: one with under 5 minutes left is never handed out. Pinned by `test_cache_near_expiry_refreshes` (Task 2).
4. **Git still pushes with Mika's keychain or SSH login.** Expected: in an agent session, a push goes over HTTPS through the helper, and the activity API shows the bot as the pusher. Checked in Task 3, step 5.
5. **The gate counts a bot label.** Expected: a `human-reviewed` label added by an agent is ignored, and the gate takes it off. Checked live in Task 4. It's already unit-covered, because `human_review.py` only accepts actor `type == "User"`.

---

### Task 1: Create and install the App (Mika, in the browser)

These are settings changes, so none of this is done without Mika's go-ahead. Mika makes the key herself.

- [ ] **Step 1:** Open LezWatch org → Settings → Developer settings → GitHub Apps → New GitHub App, and fill it in as Global Constraints says. The homepage is the repo URL.
- [ ] **Step 2:** Create the App. Note the **App ID**. The Client ID isn't needed.
- [ ] **Step 3:** Generate a private key. Attach the `.pem` to a new 1Password item, `LezWatch agents App key`, then delete the downloaded file.
- [ ] **Step 4:** Install the App → LezWatch → Only select repositories → `pwa`.
- [ ] **Step 5:** Note the bot user's id. It goes in the commit email in Task 3.

Run: `gh api users/lezwatch-agents%5Bbot%5D --jq .id`
Expected: a number.

### Task 2: The token helper

**Files:**
- Create: `tools/agent-token/agent_token.py`
- Create: `tools/agent-token/test_agent_token.py`
- Create: `tools/agent-token/README.md` (install and configure, in Mika's voice: short and plain)
- Modify: `.github/workflows/ci.yml:74`. Add a second unittest step: `python3 -m unittest discover -s tools/agent-token -p 'test_*.py' -v`

**Interfaces:**
- Produces:
  - **CLI:** `agent-token`, which prints a token on stdout.
  - **Git credential helper:** `agent-token git-credential get`. It reads git's request on stdin. When `host=github.com`, it prints `username=x-access-token` and `password=<token>`. For any other host, it prints nothing and exits 0. It ignores `store` and `erase`.
  - **Functions:**
    - `b64url(data: bytes) -> str`
    - `make_jwt(app_id: str, key_pem: bytes, now: int) -> str`
      - Header `{"alg":"RS256","typ":"JWT"}`.
      - Claims `iat = now - 60`, `exp = now + 540`, `iss = app_id`.
      - Signed by `openssl dgst -sha256 -sign /dev/stdin`, with the key on stdin.
    - `read_key(ref: str) -> bytes`, which runs `op read <ref>`.
    - `fetch_token(jwt: str, owner: str, opener=urllib.request.urlopen) -> dict`
      - `GET /orgs/{owner}/installation` returns `id`.
      - `POST /app/installations/{id}/access_tokens` returns `{"token", "expires_at"}`.
    - `cached_token(path: Path, now: datetime) -> str | None`, which returns None if the file is missing, corrupt, not `0600`, or has under 5 minutes left.
    - `save_token(path: Path, data: dict) -> None`, which writes the file as `0600`, creating the folder as `0700`.
    - `main(argv, env, stdin, stdout, stderr) -> int`

- [ ] **Step 1: Write the failing tests** in `test_agent_token.py`. `setUpClass` generates a throwaway RSA key with `openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048` into a temp folder.
  - `test_jwt_verifies_with_public_key`: split on `.`, then `openssl dgst -sha256 -verify pub.pem -signature sig` on `header.payload` gives `Verified OK`.
  - `test_jwt_claims`: decoded claims equal `{"iat": now-60, "exp": now+540, "iss": "123"}`.
  - `test_b64url_has_no_padding`: `b64url(b"a")` == `"YQ"`.
  - `test_fetch_token_calls_installation_then_access_tokens`: a fake opener records URLs and methods. It expects `.../orgs/LezWatch/installation` (GET), then `.../app/installations/42/access_tokens` (POST), each with `Authorization: Bearer <jwt>` and `Accept: application/vnd.github+json`.
  - `test_cache_hit_returns_token`: expiry 30 minutes ahead gives the token.
  - `test_cache_near_expiry_refreshes`: expiry 4 minutes ahead gives None.
  - `test_corrupt_cache_is_ignored`: file contents `not json` gives None.
  - `test_cache_wrong_mode_is_ignored`: mode `0644` gives None.
  - `test_cache_written_0600`: after `save_token`, `stat.S_IMODE` == `0o600`.
  - `test_key_failure_prints_no_token`: `read_key` raises. `main` returns non-zero, stdout is empty, and stderr has one line.
  - `test_missing_app_id_fails_closed`: with no `LEZWATCH_AGENT_APP_ID`, it returns non-zero and stdout is empty.
  - `test_git_credential_other_host_prints_nothing`: stdin `protocol=https\nhost=gitlab.com\n\n` gives empty stdout and exit 0.
  - `test_git_credential_github`: with a cached token, stdin `protocol=https\nhost=github.com\n\n` gives stdout `username=x-access-token\npassword=<tok>\n`.
  - `test_git_credential_store_is_ignored`: argv `git-credential store` gives empty stdout and exit 0.

- [ ] **Step 2: Run them to check they fail.**
Run: `python3 -m unittest discover -s tools/agent-token -p 'test_*.py' -v`
Expected: ERROR, `ModuleNotFoundError: No module named 'agent_token'`

- [ ] **Step 3: Implement `agent_token.py`** to the Interfaces above. Only the stdlib, plus `openssl` and `op` through `subprocess.run` with an argument list, never `shell=True`. Every error path prints one line to stderr and returns 1, and nothing reaches stdout. Shebang: `#!/usr/bin/env python3`.

- [ ] **Step 4: Run the tests to check they pass.**
Run: `python3 -m unittest discover -s tools/agent-token -p 'test_*.py' -v`
Expected: `OK`, 14 tests.

- [ ] **Step 5: Write `README.md`.** Cover:
  - **Install:** `install -m 755 tools/agent-token/agent_token.py ~/.local/bin/agent-token`
  - **The three env vars**
  - **A smoke test:** `agent-token >/dev/null && echo ok`
  - **What a 1Password prompt means:** Touch ID for `op`, at most about once an hour

- [ ] **Step 6: Commit**
```bash
git add tools/agent-token .github/workflows/ci.yml
git commit -m "feat: add the agent-token helper for the lezwatch-agents App"
```

### Task 3: Point agent sessions at the App (needs Mika's go-ahead)

This changes Mika's Claude Code user settings, `~/.claude/settings.json`. Show her the exact diff and wait for a yes before writing it.

- [ ] **Step 1: Install the helper.** Run `install -m 755 tools/agent-token/agent_token.py ~/.local/bin/agent-token`. Confirm `agent-token` prints a token. Mika approves the `op` prompt.

- [ ] **Step 2: Add a `gh` shim** at `~/.local/agent-bin/gh`, mode 755:
  ```sh
  #!/bin/sh
  GH_TOKEN="$(~/.local/bin/agent-token)" || exit 1
  export GH_TOKEN
  exec /opt/homebrew/bin/gh "$@"
  ```
  Use `command -v gh` outside the shim to find the real path, and put that path in the `exec` line.

- [ ] **Step 3: Add the `env` block** to Claude Code settings:

  | Key | Value | Why |
  |---|---|---|
  | `PATH` | `$HOME/.local/agent-bin:` + the current PATH, written out in full | The shim wins over the real `gh`. |
  | `GH_CONFIG_DIR` | `~/.config/gh-agents` (an empty folder) | `gh` can't find Mika's stored login. |
  | `LEZWATCH_AGENT_APP_ID` | the App ID | |
  | `LEZWATCH_AGENT_KEY_REF` | `op://<vault>/LezWatch agents App key/<file name>.pem` | |
  | `GIT_CONFIG_COUNT` | `5` | |
  | `GIT_CONFIG_KEY_0` / `VALUE_0` | `credential.helper` / `` (empty) | Resets the keychain helper. |
  | `GIT_CONFIG_KEY_1` / `VALUE_1` | `credential.https://github.com.helper` / `!~/.local/bin/agent-token git-credential` | |
  | `GIT_CONFIG_KEY_2` / `VALUE_2` | `url.https://github.com/.insteadOf` / `git@github.com:` | SSH pushes, which would go out as Mika, become HTTPS. |
  | `GIT_CONFIG_KEY_3` / `VALUE_3` | `user.name` / `lezwatch-agents[bot]` | |
  | `GIT_CONFIG_KEY_4` / `VALUE_4` | `user.email` / `<bot id>+lezwatch-agents[bot]@users.noreply.github.com` | |

- [ ] **Step 4: Add `permissions.deny` rules** as a best-effort fence:
  - `Bash(security:*)`, `Bash(gh auth:*)` and `Bash(op item:*)`
  - `Read(~/.config/gh/**)` and `Read(~/.ssh/**)`

- [ ] **Step 5: Verify in a new Claude Code session.** Run `gh api installation/repositories --jq '.repositories[].full_name'`.

  Expected: `LezWatch/pwa`. This endpoint only answers to an installation token, so a 4xx here means `gh` isn't using the App.

  Then push a throwaway branch and check who pushed it:
  ```bash
  gh api "repos/LezWatch/pwa/activity?ref=refs/heads/<branch>&per_page=1" --jq '.[0].actor.login'
  ```
  Expected: `lezwatch-agents[bot]`. Delete the branch afterwards, after confirming with Mika.

### Task 4: Prove it against the gate, and update the docs

Do this once PR #31's gate is live on `production`. Each PR here needs Mika's go-ahead.

**Files:**
- Modify: `docs/runbooks/risk-gate.md`. Rewrite the "Biggest gap" box in §2 so it says agents now run as `lezwatch-agents[bot]`, and what's still open (an agent on this Mac could still find Mika's credentials if it tried). Point to `tools/agent-token/README.md`.
- Modify: `README.md`, if it mentions agents and the gate.

- [ ] **Step 1: Open a proof PR as an agent** with a high-risk change, such as a comment in `config/app.php`. Add `human-reviewed` with the shim's `gh`.

  Expected: the issue events show actor `lezwatch-agents[bot]` with type `Bot`. `risk-tier` stays failing, and the gate takes the label off.

- [ ] **Step 2:** Mika adds the label herself. `risk-tier` goes green. Close the PR without merging and delete its branch.
- [ ] **Step 3:** Update the runbook and README as above. Then commit:
```bash
git add docs/runbooks/risk-gate.md README.md
git commit -m "docs: agents now work as lezwatch-agents[bot]"
```

## Follow-ups, not in this plan

- **A required approving review in the ruleset.** It's possible once agent PRs come from the bot, because GitHub won't let you approve your own PR. It's a settings change for Mika to decide.
- **Installing the App on `lwtv-underscores`.** That repo's gate needs checking first, to see whether it also rejects Bot actors.
