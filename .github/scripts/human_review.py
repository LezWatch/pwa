#!/usr/bin/env python3
"""Decide whether a listed human has reviewed a PR's current head commit.

Prints one JSON object: {"approved": bool, "reason": str, "remove_label": bool}
Always exits 0; the risk-tier workflow decides pass/fail. Any error yields
"approved": false (fail closed).

remove_label is true when the label is on the PR but doesn't count (it predates the
latest push, or someone not listed added it), so the gate can take it off and Mika can
simply add it again. It is false whenever the answer is uncertain.

Approved means: the latest `labeled human-reviewed` event by a User whose login is in
HUMAN_REVIEWERS came after the last time the head SHA was pushed to the PR's branch,
and after the PR's base branch last changed (a retarget changes the diff, so the old
review no longer covers it), and nobody removed the label after that.

Both inputs come from GitHub's own records, which a PR can't write:
  --events    GET /repos/{repo}/issues/{pr}/events  (labels added or removed, base changes)
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


def last_base_change(events):
    """When the PR's base branch last changed, or None if it never did."""
    if not isinstance(events, list):
        raise TypeError("events is not a list")
    times = [_time(e["created_at"]) for e in events if e.get("event") == "base_ref_changed"]
    return max(times) if times else None


def decide(events, activity, head_sha, reviewers_text):
    # Parse both inputs first, so malformed data always raises instead of hiding
    # behind an earlier "not approved".
    pushed = last_push(activity, head_sha)
    ordered = label_events(events)
    based = last_base_change(events)
    reviewers = parse_reviewers(reviewers_text)
    if not reviewers:
        return {"approved": False, "remove_label": False,
                "reason": "HUMAN_REVIEWERS is unset, so nobody can approve."}
    if pushed is None:
        return {"approved": False, "remove_label": False,
                "reason": f"Couldn't tell when {head_sha[:7]} was pushed: it isn't the branch's newest recorded push."}
    # A review must be newer than both the push and the latest base change.
    since, since_text = pushed, f"{head_sha[:7]} was pushed"
    if based is not None and based >= pushed:
        since, since_text = based, "the base branch changed"
    label_on = bool(ordered) and ordered[-1]["event"] == "labeled"
    approval = None
    for index, event in enumerate(ordered):
        actor = event.get("actor") or {}
        if (event["event"] == "labeled" and actor.get("type") == "User"
                and str(actor.get("login", "")).lower() in reviewers
                and _time(event["created_at"]) > since):
            approval = index
    if approval is None:
        return {"approved": False, "remove_label": label_on,
                "reason": f"No {LABEL} label from a listed reviewer since {since_text}."}
    if any(e["event"] == "unlabeled" for e in ordered[approval + 1:]):
        return {"approved": False, "remove_label": label_on,
                "reason": f"The {LABEL} label was removed after the review."}
    event = ordered[approval]
    return {"approved": True, "remove_label": False,
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
        result = {"approved": False, "remove_label": False,
                  "reason": f"review check failed, treating as not reviewed: {exc!r}"}
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
