#!/usr/bin/env python3
"""Decide whether a listed human has reviewed a PR's current head commit.

Prints one JSON object: {"approved": bool, "reason": str}
Always exits 0; the risk-tier workflow decides pass/fail. Any error yields
"approved": false (fail closed).

Approved means: the latest `labeled human-reviewed` event by a User whose login is in
HUMAN_REVIEWERS came after the last time the head SHA was pushed to the PR's branch,
and nobody removed the label after that.

Both inputs come from GitHub's own records, which a PR can't write:
  --events    GET /repos/{repo}/issues/{pr}/events  (who added or removed a label, when)
  --activity  GET /repos/{repo}/activity?ref=...    (when each push landed on the branch)
A workflow in the PR can add the label with its own token, but the event then names
github-actions[bot], not a listed human. A commit status or comment would not work: a
workflow in the PR can post either one under the same bot name as this gate.
"""
import argparse
import json
from datetime import datetime

LABEL = "human-reviewed"


def parse_reviewers(text):
    """Lowercased logins from a comma-separated HUMAN_REVIEWERS value."""
    return {part.strip().lower() for part in (text or "").split(",") if part.strip()}


def _time(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def last_push(activity, head_sha):
    """When head_sha landed on the branch, or None if we can't tell.

    Only the newest record counts, and it must be head_sha arriving. If the newest
    record is anything else (another SHA, a deletion), the branch moved by a route the
    records don't date, so an older head_sha entry can't be trusted.
    """
    if not isinstance(activity, list):
        raise TypeError("activity is not a list")
    if not activity:
        return None
    newest = max(activity, key=lambda a: (_time(a["timestamp"]), a.get("id", 0)))
    if newest.get("after") != head_sha or newest.get("activity_type") == "branch_deletion":
        return None
    return _time(newest["timestamp"])


def label_events(events):
    """labeled/unlabeled events for the review label, oldest first."""
    if not isinstance(events, list):
        raise TypeError("events is not a list")
    found = [e for e in events
             if e.get("event") in ("labeled", "unlabeled") and e["label"]["name"] == LABEL]
    return sorted(found, key=lambda e: (_time(e["created_at"]), e["id"]))


def decide(events, activity, head_sha, reviewers_text):
    # Parse both inputs first, so malformed data always raises instead of hiding
    # behind an earlier "not approved".
    pushed = last_push(activity, head_sha)
    ordered = label_events(events)
    reviewers = parse_reviewers(reviewers_text)
    if not reviewers:
        return {"approved": False, "reason": "HUMAN_REVIEWERS is unset, so nobody can approve."}
    if pushed is None:
        return {"approved": False,
                "reason": f"Couldn't tell when {head_sha[:7]} was pushed: it isn't the branch's newest recorded push."}
    approval = None
    for index, event in enumerate(ordered):
        actor = event.get("actor") or {}
        if (event["event"] == "labeled" and actor.get("type") == "User"
                and str(actor.get("login", "")).lower() in reviewers
                and _time(event["created_at"]) > pushed):
            approval = index
    if approval is None:
        return {"approved": False,
                "reason": f"No {LABEL} label from a listed reviewer since {head_sha[:7]} was pushed."}
    if any(e["event"] == "unlabeled" for e in ordered[approval + 1:]):
        return {"approved": False, "reason": f"The {LABEL} label was removed after the review."}
    event = ordered[approval]
    return {"approved": True,
            "reason": f"Reviewed by @{event['actor']['login']} at {event['created_at']}."}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--events", required=True)
    parser.add_argument("--activity", required=True)
    parser.add_argument("--head-sha", required=True)
    parser.add_argument("--reviewers", default="")
    args = parser.parse_args(argv)
    try:
        with open(args.events) as fh:
            events = json.load(fh)
        with open(args.activity) as fh:
            activity = json.load(fh)
        result = decide(events, activity, args.head_sha, args.reviewers)
    except Exception as exc:  # fail closed on anything unexpected
        result = {"approved": False,
                  "reason": f"review check failed, treating as not reviewed: {exc!r}"}
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
