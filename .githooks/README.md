# Git hooks

Repo-managed hooks that enforce the commit-identity rule: commits and pushes carry
only the owner's git identity, with no contributor trailers and no tool/AI traces in
commit messages, code, comments, docs or branch names.

## Setup (once per clone)

    git config core.hooksPath .githooks

The setting lives in the shared repo config, so it applies to every worktree.

## What each hook checks

- `commit-msg`: rejects contributor trailers (`Co-authored-by:`, `Co-developed-by:`,
  `Assisted-by:`, `Generated-by:`, `Helped-by:`, any case) and any term from
  `trace-terms` (which includes "Generated with").
- `pre-commit`: scans only the lines *added* by the staged diff for terms from
  `trace-terms` and prints `file:line [term]`. Skipped: lockfiles
  (`package-lock.json`, `npm-shrinkwrap.json`, `yarn.lock`, `pnpm-lock.yaml`,
  `composer.lock`, `Cargo.lock`), `vendor/`, `node_modules/`, `target/`, binary
  files and `.githooks/` itself. Removed lines are never flagged.
- `pre-push`: rejects a branch or tag whose name contains a trace term, and for every
  outgoing commit rejects an author or committer email that differs from
  `git config user.email` (case-insensitive), a contributor trailer, or a trace term
  in the message.

## trace-terms

One case-insensitive POSIX extended regex per line; `#` starts a comment. A `w:`
prefix makes the term word-bounded (no letter or digit directly before or after it),
so `astra` does not match `fastrand` and `opus` does not match `opuscule`.

## The `trace-ok` marker

A line that legitimately discusses a listed term (for example product documentation)
passes `pre-commit` if the same line contains the text `trace-ok`, e.g. in a comment
or an HTML comment `<!-- trace-ok -->`. The marker is not honoured in commit messages
or branch names.

## Self-test

    sh .githooks/test.sh

## Do not bypass

`git commit --no-verify` and `git push --no-verify` skip these hooks entirely and
defeat the rule. Do not use them.
