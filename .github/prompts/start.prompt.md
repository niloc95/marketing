---
agent: agent
description: Start a feature branch from an up to date main
---

Start a new branch named `${input:branch:feat/short-name}`.

1. `git status`. If there are uncommitted changes, stop and ask whose they are and
   whether to `/commit` them first. Do not stash or discard them.
2. `git switch main` then `git pull --ff-only`. If the pull is not a fast forward, stop
   and report.
3. `git switch -c ${input:branch}` then `git push -u origin ${input:branch}`.
4. `npm run agent:check` and show its output. Report orphan branches it lists; delete
   them only through `/check`.

Report the branch name and the commit it starts from (`git log -1 --oneline`).
