#!/usr/bin/env python3
"""Unit tests for human_review.py (stdlib unittest, no deps).

Run from the repo root: python3 -m unittest discover -s .github/scripts -p 'test_*.py'
"""
import contextlib
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path

_HERE = Path(__file__).resolve().parent
_spec = importlib.util.spec_from_file_location("human_review", _HERE / "human_review.py")
hr = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(hr)

HEAD = "b" * 40
OLD = "a" * 40
REVIEWERS = "ipstenu"


def _push(after, at, kind="push"):
    return {"after": after, "timestamp": at, "activity_type": kind}


def _label(event, at, login="Ipstenu", kind="User", name="human-reviewed", id_=None):
    return {
        "id": id_ or int(at[11:19].replace(":", "")),
        "event": event,
        "created_at": at,
        "actor": {"login": login, "type": kind},
        "label": {"name": name},
    }


def _decide(events, activity, head=HEAD, reviewers=REVIEWERS):
    return hr.decide(events, activity, head, reviewers)


class TestDecide(unittest.TestCase):
    def test_reviewer_label_after_the_push_approves(self):
        result = _decide([_label("labeled", "2026-11-23T10:05:00Z")],
                         [_push(HEAD, "2026-11-23T10:00:00Z")])
        self.assertTrue(result["approved"], result)
        self.assertIn("@Ipstenu", result["reason"])

    def test_login_match_ignores_case_and_spaces(self):
        result = _decide([_label("labeled", "2026-11-23T10:05:00Z", login="IPSTENU")],
                         [_push(HEAD, "2026-11-23T10:00:00Z")], reviewers=" someone , ipstenu ")
        self.assertTrue(result["approved"], result)

    def test_label_from_before_the_push_does_not_count(self):
        # Reviewed the old commit; a new push needs a new look.
        result = _decide([_label("labeled", "2026-11-23T09:59:00Z")],
                         [_push(OLD, "2026-11-23T09:00:00Z"), _push(HEAD, "2026-11-23T10:00:00Z")])
        self.assertFalse(result["approved"], result)

    def test_label_in_the_same_second_as_the_push_does_not_count(self):
        result = _decide([_label("labeled", "2026-11-23T10:00:00Z")],
                         [_push(HEAD, "2026-11-23T10:00:00Z")])
        self.assertFalse(result["approved"], result)

    def test_latest_push_of_the_head_sha_wins(self):
        # HEAD was pushed, replaced, then force-pushed back after the review.
        events = [_label("labeled", "2026-11-23T10:05:00Z")]
        activity = [
            _push(HEAD, "2026-11-23T10:00:00Z"),
            _push(OLD, "2026-11-23T10:03:00Z"),
            _push(HEAD, "2026-11-23T10:10:00Z", kind="force_push"),
        ]
        self.assertFalse(_decide(events, activity)["approved"])

    def test_branch_creation_counts_as_a_push(self):
        result = _decide([_label("labeled", "2026-11-23T10:05:00Z")],
                         [_push(HEAD, "2026-11-23T10:00:00Z", kind="branch_creation")])
        self.assertTrue(result["approved"], result)

    def test_unknown_push_fails_closed(self):
        # No record of when HEAD reached the branch (a fork, or the API returned nothing).
        result = _decide([_label("labeled", "2026-11-23T10:05:00Z")], [_push(OLD, "2026-11-23T10:00:00Z")])
        self.assertFalse(result["approved"])
        self.assertIn("when", result["reason"])

    def test_label_from_someone_not_listed_does_not_count(self):
        result = _decide([_label("labeled", "2026-11-23T10:05:00Z", login="agent-account")],
                         [_push(HEAD, "2026-11-23T10:00:00Z")])
        self.assertFalse(result["approved"])

    def test_label_from_a_bot_does_not_count(self):
        # A workflow in the PR could add the label with its own token.
        result = _decide([_label("labeled", "2026-11-23T10:05:00Z", login="github-actions[bot]", kind="Bot")],
                         [_push(HEAD, "2026-11-23T10:00:00Z")])
        self.assertFalse(result["approved"])

    def test_listed_login_that_is_not_a_user_does_not_count(self):
        result = _decide([_label("labeled", "2026-11-23T10:05:00Z", kind="Bot")],
                         [_push(HEAD, "2026-11-23T10:00:00Z")])
        self.assertFalse(result["approved"])

    def test_unset_reviewers_means_nobody(self):
        for reviewers in ("", "  ", ",", None):
            with self.subTest(reviewers=reviewers):
                result = _decide([_label("labeled", "2026-11-23T10:05:00Z")],
                                 [_push(HEAD, "2026-11-23T10:00:00Z")], reviewers=reviewers)
                self.assertFalse(result["approved"])
                self.assertIn("HUMAN_REVIEWERS", result["reason"])

    def test_removing_the_label_after_review_revokes_it(self):
        events = [_label("labeled", "2026-11-23T10:05:00Z"),
                  _label("unlabeled", "2026-11-23T10:06:00Z", login="someone-else")]
        self.assertFalse(_decide(events, [_push(HEAD, "2026-11-23T10:00:00Z")])["approved"])

    def test_relabel_after_removal_approves_again(self):
        events = [_label("labeled", "2026-11-23T10:05:00Z"),
                  _label("unlabeled", "2026-11-23T10:06:00Z"),
                  _label("labeled", "2026-11-23T10:07:00Z")]
        self.assertTrue(_decide(events, [_push(HEAD, "2026-11-23T10:00:00Z")])["approved"])

    def test_removal_in_the_same_second_is_ordered_by_event_id(self):
        events = [_label("unlabeled", "2026-11-23T10:05:00Z", id_=2),
                  _label("labeled", "2026-11-23T10:05:00Z", id_=1)]
        self.assertFalse(_decide(events, [_push(HEAD, "2026-11-23T10:00:00Z")])["approved"])

    def test_other_labels_are_ignored(self):
        events = [_label("labeled", "2026-11-23T10:05:00Z"),
                  _label("unlabeled", "2026-11-23T10:06:00Z", name="risk: high"),
                  {"id": 9, "event": "renamed", "created_at": "2026-11-23T10:07:00Z", "actor": {"login": "x", "type": "User"}}]
        self.assertTrue(_decide(events, [_push(HEAD, "2026-11-23T10:00:00Z")])["approved"])

    def test_fractional_second_timestamps_parse(self):
        result = _decide([_label("labeled", "2026-11-23T10:00:00.900Z")],
                         [_push(HEAD, "2026-11-23T10:00:00.100Z")])
        self.assertTrue(result["approved"], result)


class TestMain(unittest.TestCase):
    def _run(self, events, activity, extra=()):
        with tempfile.TemporaryDirectory() as tmp:
            ev, act = Path(tmp) / "events.json", Path(tmp) / "activity.json"
            ev.write_text(events if isinstance(events, str) else json.dumps(events))
            act.write_text(activity if isinstance(activity, str) else json.dumps(activity))
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                code = hr.main(["--events", str(ev), "--activity", str(act), "--head-sha", HEAD,
                                "--reviewers", REVIEWERS, *extra])
        return code, json.loads(out.getvalue())

    def test_approves_from_files(self):
        code, result = self._run([_label("labeled", "2026-11-23T10:05:00Z")],
                                 [_push(HEAD, "2026-11-23T10:00:00Z")])
        self.assertEqual(code, 0)
        self.assertTrue(result["approved"], result)

    def test_bad_input_fails_closed(self):
        for events, activity in (("not json", "[]"), ("[]", "{}"), ('[{"event": "labeled"}]', "[]"),
                                 ([_label("labeled", "garbage", id_=1)], [_push(HEAD, "2026-11-23T10:00:00Z")])):
            with self.subTest(events=events, activity=activity):
                code, result = self._run(events, activity)
                self.assertEqual(code, 0)
                self.assertFalse(result["approved"])
                self.assertIn("treating as not reviewed", result["reason"])


if __name__ == "__main__":
    unittest.main()
