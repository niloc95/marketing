---
agent: agent
description: Find gaps and orphans in the repo and clean up the safe ones
---

1. `git fetch --prune`, then `npm run agent:check`. Show the output.
2. For each finding:
   - **Merged branch** (local or on GitHub): confirm with
     `git branch --merged main` / `git branch -r --merged origin/main`, then delete it
     (`git branch -d`, `git push origin --delete`). Never `-D`; never an unmerged branch.
   - **Unmerged branch with no commits for 14 days**: list it with its last commit
     (`git log -1 --format='%h %cr %s' <branch>`) and ask. Do not delete it.
   - **Untracked file**: say which change it seems to belong to, and ask.
   - **One off prompt or handover note**: ask whether its task is done; delete it only on yes.
   - **Handbook gap or dash in copy**: report it; it is fixed in the change, not here.
   - **Unpushed commits**: push the feature branch. Never push `main` here.
3. Run `npm run agent:check` again and show the output.

Report what you deleted, what you pushed, and every question still open.
