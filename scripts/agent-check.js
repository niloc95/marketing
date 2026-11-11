#!/usr/bin/env node
/**
 * agent-check.js — the gate for every commit, merge and push, whoever makes it.
 * -----------------------------------------------------------------------------
 * Copilot does the git work in this repo (.github/copilot-instructions.md), Claude
 * and the owner write code. This script is what keeps that hand off honest: it finds
 * the gaps (a change the handbook does not mention, customer copy with a dash), the
 * orphans (merged branches nobody deleted, stray files, one off prompts left behind)
 * and the unfinished (unpushed commits), so nobody has to remember them.
 *
 *   npm run agent:check             report; exit 1 if anything blocks
 *   npm run agent:check -- --staged pre-commit mode: judge what is staged (the hook)
 *   npm run agent:check -- --merge  before /finish: also needs a clean, pushed tree
 *                                   and a green phpunit run
 *
 * Errors block (exit 1). Warnings are listed for the agent to act on or report.
 */
import { execSync, spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';

const args = new Set(process.argv.slice(2));
const STAGED = args.has('--staged');
const MERGE = args.has('--merge');

const errors = [];
const warnings = [];
const git = (cmd) => execSync(`git ${cmd}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
const lines = (out) => out.split('\n').filter(Boolean);

const branch = git('rev-parse --abbrev-ref HEAD');
const base = git('merge-base main HEAD');
const diffRange = STAGED ? `--cached ${base}` : base;

// Prompt files that stay. Anything else in .github/prompts is a one off task prompt.
const STANDING_PROMPTS = ['start', 'commit', 'finish', 'check'];

// Paths whose change must come with a dated line in the handbook changelog
// (CLAUDE.md, "System handbook"). New files under these count, edits do not.
const HANDBOOK_NEW = ['app/Services/', 'app/Commands/', 'app/Filters/', 'app/Libraries/'];
const HANDBOOK_ANY = [
    'app/Database/Migrations/',
    'app/Config/Routes.php',
    'app/Config/Sites.php',
    'app/Config/Handbook.php',
    'tailwind.tokens.cjs',
    'deploy/',
];
// Manifests count only when a dependency changes, not a script.
const MANIFEST_DEPS = {
    'package.json': ['dependencies', 'devDependencies'],
    'composer.json': ['require', 'require-dev'],
};
const CHANGELOG = 'app/Views/admin/handbook/changelog.php';

const depsChanged = (path) => {
    const read = (ref) => {
        try {
            return JSON.parse(ref === null ? readFileSync(path, 'utf8') : git(`show ${ref}:${path}`));
        } catch {
            return {};
        }
    };
    const before = read(base);
    const after = read(STAGED ? '' : null);
    return MANIFEST_DEPS[path].some((k) => JSON.stringify(before[k] ?? {}) !== JSON.stringify(after[k] ?? {}));
};

// ---- branch ---------------------------------------------------------------
if (branch === 'main' && STAGED) {
    errors.push('Committing on main. Work goes on a feature branch (/start); main only takes merges (/finish).');
}

// ---- what changed against main ---------------------------------------------
const changed = lines(git(`diff --name-status ${diffRange}`)).map((l) => {
    const [status, ...paths] = l.split('\t');
    return { status: status[0], path: paths[paths.length - 1] };
});
const untracked = lines(git('ls-files --others --exclude-standard'));
if (!STAGED) untracked.forEach((path) => changed.push({ status: 'A', path }));

const touched = changed.map((c) => c.path);
const needsHandbook = changed.filter(
    (c) =>
        HANDBOOK_ANY.some((p) => c.path.startsWith(p)) ||
        (c.path in MANIFEST_DEPS && depsChanged(c.path)) ||
        (c.status === 'A' && HANDBOOK_NEW.some((p) => c.path.startsWith(p)) && c.path.endsWith('.php')),
);
if (needsHandbook.length && !touched.includes(CHANGELOG)) {
    errors.push(
        `Handbook gap: these changes need a dated line in ${CHANGELOG} (and Config\\Handbook if a table, service or cron changed), in the same commit:\n` +
            needsHandbook.map((c) => `      ${c.status} ${c.path}`).join('\n'),
    );
}

// ---- customer copy: no em dash in added lines -------------------------------
const copyDiff = git(`diff -U0 ${diffRange} -- app/Views app/Language`);
let file = '';
for (const l of copyDiff.split('\n')) {
    if (l.startsWith('+++ ')) file = l.slice(6);
    else if (l.startsWith('+') && l.includes('—') && !file.startsWith('app/Views/admin/')) {
        warnings.push(`Dash in customer copy (CLAUDE.md copy rules): ${file}: ${l.slice(1).trim().slice(0, 100)}`);
    }
}

// ---- orphans ----------------------------------------------------------------
const mergedLocal = lines(git("branch --merged main --format='%(refname:short)'")).filter(
    (b) => b !== 'main' && b !== branch,
);
if (mergedLocal.length) {
    warnings.push(`Merged branches never deleted (orphans): ${mergedLocal.join(', ')}\n      fix: git branch -d <name>`);
}
const mergedRemote = lines(git("branch -r --merged origin/main --format='%(refname:short)'")).filter(
    (b) => !['origin/main', 'origin/HEAD', 'origin'].includes(b) && b !== `origin/${branch}`,
);
if (mergedRemote.length) {
    warnings.push(`Merged branches still on GitHub: ${mergedRemote.join(', ')}\n      fix: git push origin --delete <name>`);
}

const strays = untracked.filter((p) => !p.startsWith('docs/handover/'));
if (strays.length && !STAGED) {
    warnings.push(`Untracked files: commit them with their change or add them to .gitignore:\n` + strays.map((p) => `      ${p}`).join('\n'));
}

const stashes = lines(git('stash list'));
if (stashes.length) {
    warnings.push(`Stashed work nobody will see (orphans): ${stashes.length}\n` + stashes.map((s) => `      ${s}`).join('\n') + '\n      fix: git stash show -p <ref>, then commit it on its branch or drop it with the owner\'s OK');
}
const worktrees = lines(git('worktree list')).slice(1);
if (worktrees.length) errors.push(`Extra worktrees (CLAUDE.md: one checkout):\n` + worktrees.map((w) => `      ${w}`).join('\n'));

if (existsSync('.github/prompts')) {
    const oneOff = readdirSync('.github/prompts').filter(
        (f) => f.endsWith('.prompt.md') && !STANDING_PROMPTS.includes(f.replace('.prompt.md', '')),
    );
    if (oneOff.length) warnings.push(`One off task prompts in .github/prompts (delete when the task is done): ${oneOff.join(', ')}`);
}
if (existsSync('docs/handover')) {
    const reports = readdirSync('docs/handover').filter((f) => f.endsWith('.md'));
    if (reports.length) warnings.push(`Open handover notes in docs/handover (delete when read and done): ${reports.join(', ')}`);
}

// ---- pushed? ----------------------------------------------------------------
let upstream = '';
try {
    upstream = git('rev-parse --abbrev-ref --symbolic-full-name @{u}');
} catch {
    /* no upstream */
}
if (!upstream && branch !== 'main') {
    (MERGE ? errors : warnings).push(`Branch ${branch} is not on GitHub yet: git push -u origin ${branch}`);
} else if (upstream) {
    const [ahead, behind] = git(`rev-list --left-right --count HEAD...${upstream}`).split(/\s+/).map(Number);
    if (ahead) (MERGE ? errors : warnings).push(`${ahead} commit(s) on ${branch} not pushed to ${upstream}.`);
    if (behind) warnings.push(`${upstream} has ${behind} commit(s) this checkout does not: git pull --ff-only`);
}

// ---- merge gate -------------------------------------------------------------
if (MERGE) {
    const dirty = lines(git('status --porcelain')).filter((l) => !l.slice(3).startsWith('docs/handover/'));
    if (dirty.length) errors.push(`Working tree not clean; commit or leave the change out first:\n` + dirty.map((l) => `      ${l}`).join('\n'));
    const behindMain = Number(git(`rev-list --count HEAD..main`));
    if (behindMain) warnings.push(`main has ${behindMain} commit(s) this branch does not; /finish merges them, expect conflicts to check.`);
    if (!errors.length) {
        console.log('Running php vendor/bin/phpunit …');
        const run = spawnSync('php', ['vendor/bin/phpunit'], { stdio: 'inherit' });
        if (run.status !== 0) errors.push('phpunit failed. Nothing is merged until every test passes.');
    }
}

// ---- report -----------------------------------------------------------------
console.log(`\nagent:check on ${branch}${STAGED ? ' (staged)' : ''}${MERGE ? ' (merge gate)' : ''}`);
errors.forEach((e) => console.log(`  ERROR  ${e}`));
warnings.forEach((w) => console.log(`  WARN   ${w}`));
if (!errors.length && !warnings.length) console.log('  OK     no gaps, no orphans');
console.log('');
process.exit(errors.length ? 1 : 0);
