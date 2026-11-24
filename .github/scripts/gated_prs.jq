# Input: array of PR objects (fresh from the API). Keep the open, same-repo PRs into a
# gated branch whose head is exactly the commit the trigger ran on, one per number.
[ .[]
  | select(.state == "open"
      and .head.repo.full_name == $repo
      and .head.sha == $sha
      and (.base.ref == "production" or (.base.ref | startswith("release/")))) ]
| unique_by(.number)
