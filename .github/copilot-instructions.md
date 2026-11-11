# Copilot instructions: WebScheduler marketing repo

**Read `CLAUDE.md` first, every task.** It holds the project rules (two sites, layers,
CSP, copy rules, tests, the system handbook). They are not repeated here, so they cannot
drift. Before working in an area, read the skill `CLAUDE.md` names for it in
`.claude/skills/<name>/SKILL.md`.

This file only says what Copilot owns: **git, and basic tasks.**

## Who does what

| Who | Does |
|---|---|
| Owner | Decides what merges into `main` and what is released. |
| Claude | Features, reviews, migrations, releases (`deploy-production` skill). Leaves its work uncommitted for Copilot. |
| Copilot | Branches, commits, pushes, merges, cleanup (`/start`, `/commit`, `/finish`, `/check`), and basic tasks. |

## Basic tasks Copilot may take

- Copy fixes, small view and CSS changes, tests for behaviour that already exists, docs.
- **Hand it back to the owner (do not start)** if it touches: a migration or table,
  payments (Paystack, PayFast), sign in, `Config\Filters`, CSP, `Mailer`, what differs
  between co.za and .app, Bookings data, `.env`, `ssh lightsail`, or a release.
  Say which item it touches and stop.

## Git rules

- **The gate:** `npm run agent:check` finds gaps and orphans. The pre-commit hook runs it
  on what is staged (`npm run agent:hooks` installs the hook once per checkout). Never
  bypass it: no `--no-verify`. Fix what it reports, or stop and report it.
- **One checkout, sessions take turns.** Before anything, run `git status`. If it shows
  changes you were not told about, ask whose they are before you touch them.
- **Branches:** work never goes straight onto `main`. Names: `feat/…`, `fix/…`,
  `hotfix/…`, `chore/…`, short and lowercase.
- **Commits:** the style in `git log`: `type(scope): what changed, in plain words`,
  then a body that says why. Stage files by name, never `git add -A` or `git add .`.
  One logical change per commit. A change the handbook covers carries its dated
  changelog line in the same commit (the gate checks this).
- **Push** a feature branch after every commit, so nothing exists only on this machine.
- **`main`:** merge only when the owner says "merge <branch>" (`/finish`). Pushing `main`
  does not deploy anything; it puts the work in line for the next manual release. Say so.
- **Never:** force push, `git reset --hard`, `git clean`, rebase, amend a pushed commit,
  delete an unmerged branch, `git stash` (work hidden in a stash is an orphan; uncommitted
  changes travel with `git switch -c`), worktrees, a second server or database, `ssh lightsail`.
- **Conflicts:** resolve only the obvious ones (both sides add changelog lines: keep both,
  newest first). Anything else: `git merge --abort` and report the files.

## Reporting

End every task with what you ran and what it printed: commit hashes, the gate output,
the phpunit summary line. Never say "done" or "tests pass" without the output that
shows it. Notes for the next agent go to `docs/handover/<task>.md` (ignored by git,
delete when done); the commits and the handbook changelog are the lasting record.

Refer to people whose pronouns you do not know as "they".
