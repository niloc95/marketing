---
agent: agent
description: Merge a finished branch into main, push main, and delete the branch (owner's go ahead only)
---

Merge `${input:branch}` into `main`. Only run this when the owner has said to merge this
branch. If you are not sure they did, ask.

1. `git switch ${input:branch}` then `npm run agent:check -- --merge`. It needs a clean,
   pushed branch and a green phpunit run. Any ERROR: stop and report it.
2. `git switch main`, `git pull --ff-only`.
3. `git merge --no-ff ${input:branch}` (default message `Merge branch '<branch>'`, as in
   `git log`). Conflicts: follow the conflict rule in `.github/copilot-instructions.md`;
   otherwise `git merge --abort` and report the files.
4. `php vendor/bin/phpunit` on `main`. If it fails: do not push. Report the failures and
   leave `main` as it is for the owner to decide.
5. `git push origin main`.
6. `git branch -d ${input:branch}` and `git push origin --delete ${input:branch}`.
7. If the branch added a migration, say so: the dev database keeps it, and the release
   must run it (the `deploy-production` skill says in which order).
8. `npm run agent:check`.

Report: the merge commit hash, the phpunit summary line on main, the push result, and
that the branch is deleted on both sides. Then say it plainly: **main is pushed, nothing
is released; releasing is a separate step the owner approves.**
