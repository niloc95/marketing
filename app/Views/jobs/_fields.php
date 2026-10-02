<?php
/**
 * The post form's fields, shared by the unlisted form, a listed owner's form
 * and the unlisted poster's edit page.
 *
 * The kind (job or service request) is chosen by URL (?kind=), not by a
 * script toggling fields: each kind renders only its own fields, which works
 * with JavaScript off and keeps inline script out of a CSP-enforced page.
 *
 * @var 'unlisted'|'listed'|'edit'|'listed-edit' $mode
 * @var 'job'|'service'             $kind
 * @var array<string,mixed>         $old
 * @var array<string,string>        $errors
 * @var list<array<string,mixed>>   $categories
 * @var list<string>                $provinces
 * @var array<string,array>         $types
 * @var array<string,array>         $periods
 * @var int                         $defaultDays
 * @var int                         $maxDays
 */
$isJob = $kind === 'job';
$v     = static fn (string $f) => form_old_value($old[$f] ?? '');
$err   = static fn (string $f) => $errors[$f] ?? '';
$error = static fn (string $f) => $err($f) !== '' ? '<div class="err">' . esc($err($f)) . '</div>' : '';
?>
<input type="hidden" name="kind" value="<?= esc($kind, 'attr') ?>">

<?php if ($mode === 'unlisted'): ?>
    <div class="form-section-head"><h3>About you</h3></div>
    <div class="form-row">
        <div class="field">
            <label for="f-poster-name">Your name</label>
            <input type="text" id="f-poster-name" name="poster_name" required value="<?= esc($v('poster_name'), 'attr') ?>" autocomplete="name">
            <?= $error('poster_name') ?>
        </div>
        <div class="field">
            <label for="f-poster-email">Email address</label>
            <input type="email" id="f-poster-email" name="poster_email" required value="<?= esc($v('poster_email'), 'attr') ?>" autocomplete="email">
            <div class="hint">We send a link to confirm the post. Never shown publicly.</div>
            <?= $error('poster_email') ?>
        </div>
    </div>
    <div class="form-row">
        <div class="field">
            <label for="f-poster-phone">Phone <span class="text-slate-400">(optional, only we see it)</span></label>
            <input type="tel" id="f-poster-phone" name="poster_phone" value="<?= esc($v('poster_phone'), 'attr') ?>" autocomplete="tel">
        </div>
        <div class="field">
            <label for="f-company">
                <?= $isJob ? 'Company or employer name' : 'Company name' ?>
                <span class="text-slate-400">(optional<?= $isJob ? '; blank shows "Private employer"' : '' ?>)</span>
            </label>
            <input type="text" id="f-company" name="company_name" value="<?= esc($v('company_name'), 'attr') ?>" autocomplete="organization">
        </div>
    </div>
<?php elseif ($mode === 'edit'): ?>
    <div class="field">
        <label for="f-company"><?= $isJob ? 'Company or employer name' : 'Company name' ?> <span class="text-slate-400">(optional)</span></label>
        <input type="text" id="f-company" name="company_name" value="<?= esc($v('company_name'), 'attr') ?>">
    </div>
<?php endif; ?>

<div class="form-section-head"><h3><?= $isJob ? 'The job' : 'What you need' ?></h3></div>
<div class="field">
    <label for="f-title"><?= $isJob ? 'Job title' : 'In one line, what do you need?' ?></label>
    <input type="text" id="f-title" name="title" required maxlength="150" value="<?= esc($v('title'), 'attr') ?>"
           placeholder="<?= $isJob ? 'e.g. Qualified electrician' : 'e.g. Plumber to replace a burst geyser' ?>">
    <?= $error('title') ?>
</div>
<div class="field">
    <label for="f-description"><?= $isJob ? 'Description' : 'Details' ?></label>
    <textarea id="f-description" name="description" rows="8" required maxlength="5000"
              placeholder="<?= $isJob ? 'What the work involves, the experience or qualifications needed, hours, and how to apply.' : 'What needs doing, the size of the job, and any access or timing details.' ?>"><?= esc($v('description')) ?></textarea>
    <?= $error('description') ?>
</div>
<div class="field">
    <label for="f-category">Category <span class="text-slate-400">(optional)</span></label>
    <select id="f-category" name="category_id" data-category-picker>
        <option value="">Choose a category</option>
        <?php $group = null; ?>
        <?php foreach ($categories as $cat): ?>
            <?php if ($cat['group_name'] !== $group): ?>
                <?= $group !== null ? '</optgroup>' : '' ?><optgroup label="<?= esc($cat['group_name'], 'attr') ?>" data-slug="<?= esc((string) ($cat['group_slug'] ?? ''), 'attr') ?>">
                <?php $group = $cat['group_name']; ?>
            <?php endif; ?>
            <option value="<?= (int) $cat['id'] ?>" <?= (string) $v('category_id') === (string) $cat['id'] ? 'selected' : '' ?>><?= esc($cat['name']) ?></option>
        <?php endforeach; ?>
        <?= $group !== null ? '</optgroup>' : '' ?>
    </select>
</div>

<div class="form-row">
    <div class="field">
        <label for="f-province">Province</label>
        <select id="f-province" name="province">
            <option value="">Choose a province</option>
            <?php foreach ($provinces as $prov): ?>
                <option value="<?= esc($prov, 'attr') ?>" <?= $v('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $error('province') ?>
    </div>
    <div class="field">
        <label for="f-city">Town or suburb<?= $isJob ? ' <span class="text-slate-400">(optional)</span>' : '' ?></label>
        <input type="text" id="f-city" name="city" value="<?= esc($v('city'), 'attr') ?>" <?= $isJob ? '' : 'required' ?>>
        <?= $error('city') ?>
    </div>
</div>

<?php if ($isJob): ?>
    <div class="field">
        <label class="font-medium"><input type="checkbox" name="is_remote" value="1" <?= $v('is_remote') ? 'checked' : '' ?>> This job can be done remotely</label>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="f-type">Job type</label>
            <select id="f-type" name="employment_type" required>
                <option value="">Choose one</option>
                <?php foreach ($types as $key => $t): ?>
                    <option value="<?= esc($key, 'attr') ?>" <?= $v('employment_type') === $key ? 'selected' : '' ?>><?= esc($t['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $error('employment_type') ?>
        </div>
        <div class="field">
            <label for="f-period">Pay period <span class="text-slate-400">(if you give a salary)</span></label>
            <select id="f-period" name="salary_period">
                <option value="">—</option>
                <?php foreach ($periods as $key => $p): ?>
                    <option value="<?= esc($key, 'attr') ?>" <?= $v('salary_period') === $key ? 'selected' : '' ?>><?= esc(ucfirst($p['label'])) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $error('salary_period') ?>
        </div>
    </div>
    <div class="form-row">
        <div class="field">
            <label for="f-salary-min">Salary from (R) <span class="text-slate-400">(optional)</span></label>
            <input type="text" inputmode="numeric" id="f-salary-min" name="salary_min" value="<?= esc($v('salary_min'), 'attr') ?>">
        </div>
        <div class="field">
            <label for="f-salary-max">Salary to (R) <span class="text-slate-400">(optional)</span></label>
            <input type="text" inputmode="numeric" id="f-salary-max" name="salary_max" value="<?= esc($v('salary_max'), 'attr') ?>">
            <?= $error('salary_max') ?>
        </div>
    </div>
    <p class="hint mb-4">Posts that show pay get more applicants, and Google for Jobs ranks them higher.</p>

    <div class="form-section-head"><h3>How to apply</h3></div>
    <p class="hint mb-3">
        Fill in one. With neither, applications are emailed to <?= str_starts_with($mode, 'listed') ? 'your profile\'s address' : 'you' ?>.
        Applicants never see the email address: they apply through a form here and we forward it.
    </p>
    <div class="form-row">
        <div class="field">
            <label for="f-apply-email">Send applications to</label>
            <input type="email" id="f-apply-email" name="apply_email" value="<?= esc($v('apply_email'), 'attr') ?>" placeholder="jobs@yourcompany.co.za">
            <?= $error('apply_email') ?>
        </div>
        <div class="field">
            <label for="f-apply-url">Or apply on your website</label>
            <input type="url" id="f-apply-url" name="apply_url" value="<?= esc($v('apply_url'), 'attr') ?>" placeholder="https://">
            <?= $error('apply_url') ?>
        </div>
    </div>
<?php else: ?>
    <div class="form-row">
        <div class="field">
            <label for="f-budget">Budget <span class="text-slate-400">(optional)</span></label>
            <input type="text" id="f-budget" name="budget_text" maxlength="120" value="<?= esc($v('budget_text'), 'attr') ?>" placeholder="e.g. R2 000 to R3 000, or open to quotes">
        </div>
        <div class="field">
            <label for="f-needed-by">Needed by <span class="text-slate-400">(optional)</span></label>
            <input type="date" id="f-needed-by" name="needed_by" value="<?= esc($v('needed_by'), 'attr') ?>">
            <?= $error('needed_by') ?>
        </div>
    </div>
    <p class="hint mb-4">
        Businesses on <?= esc(config('Directory')->siteName()) ?> can reply, up to <?= (int) config('JobBoard')->maxResponses ?> of them.
        Replies come to your email; your address is never shown.
    </p>
<?php endif; ?>

<div class="field">
    <label for="f-closes">Close the post on <span class="text-slate-400">(optional)</span></label>
    <input type="date" id="f-closes" name="closes_on" value="<?= esc($v('closes_on'), 'attr') ?>"
           min="<?= date('Y-m-d', strtotime('+1 day')) ?>" max="<?= date('Y-m-d', strtotime('+' . $maxDays . ' days')) ?>">
    <div class="hint">Left empty, it closes after <?= (int) $defaultDays ?> days. We email you before it closes so you can renew it.</div>
    <?= $error('closes_on') ?>
</div>

<?php if (! in_array($mode, ['edit', 'listed-edit'], true)): ?>
    <div class="field">
        <label class="font-medium">
            <input type="checkbox" name="genuine" value="1" <?= $v('genuine') ? 'checked' : '' ?>>
            <?php if ($isJob): ?>
                This is a real vacancy. Applicants will never be asked to pay a fee of any kind. I accept the
            <?php else: ?>
                This is a real request for work I need done. I accept the
            <?php endif; ?>
            <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('terms') ?>" target="_blank" rel="noopener">Terms of use</a>
            and <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('privacy') ?>" target="_blank" rel="noopener">Privacy policy</a>.
        </label>
        <?= $error('genuine') ?>
    </div>
<?php endif; ?>
