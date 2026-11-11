---
agent: agent
description: Commit the work in the checkout as clean, logical commits and push the branch
---

Commit the uncommitted work and push it.

1. Read `.github/copilot-instructions.md` and `CLAUDE.md`.
2. `git status` and `git branch --show-current`. On `main`, stop: ask for a branch name
   and run `/start` with it first (`git switch -c` keeps the uncommitted changes).
3. Read the full diff (`git diff`, and every untracked file). Group the changes into
   logical commits. If a file mixes two changes, keep them in one commit and say so;
   do not split hunks you are unsure of. Untracked files that belong to no change: list
   them and ask; do not commit them.
4. If any PHP, view or test file changed, run `php vendor/bin/phpunit` first. If it
   fails, stop and report the failures; commit nothing.
5. For each commit: `git add <files by name>`, then `git commit` with a message in the
   `git log` style: `type(scope): plain summary`, then a body that says why. The newest
   handbook changelog line (`app/Views/admin/handbook/changelog.php`) usually says it
   best; reuse its wording. End the message with
   `Co-authored-by: GitHub Copilot <copilot@github.com>`.
   If the pre-commit gate fails, fix what it names (usually a missing changelog line:
   ask for its wording if the change is not yours) and commit again. Never `--no-verify`.
6. `git push` (with `-u origin <branch>` the first time).
7. `npm run agent:check`.

Report: each commit hash and subject, the phpunit summary line, the push result, and
the gate output.
