<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => ($role === null ? 'New role' : 'Edit role') . ' | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * Create or edit a role on /careers. A new role saves as a draft; opening it
 * is a separate button on the list, so a half written role never goes live
 * by accident. The slug is set once from the first title and never changes,
 * so links already shared keep working.
 *
 * Public copy rules apply to what is typed here: no dashes, no hyphenated words.
 *
 * @var array<string,mixed>|null     $role
 * @var array<string,mixed>          $values
 * @var array<string,string>         $errors
 * @var App\Services\CareersService  $svc
 * @var list<string>                 $provinces
 * @var Config\JobBoard              $jobs
 */
$v      = static fn (string $f) => (string) ($values[$f] ?? '');
$err    = static fn (string $f) => isset($errors[$f]) ? '<div class="err">' . esc($errors[$f]) . '</div>' : '';
$action = base_url($role === null ? 'admin/careers/new' : 'admin/careers/' . $role['id']);
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="<?= base_url('admin/careers') ?>">Careers</a>
            <span class="crumbs-sep">/</span>
            <span><?= $role === null ? 'New role' : esc($role['title']) ?></span>
        </nav>

        <div class="form-card">
            <h1 class="mb-1 text-xl font-bold text-slate-900 dark:text-white"><?= $role === null ? 'New role' : 'Edit role' ?></h1>
            <?php if ($role !== null): ?>
                <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
                    Status: <strong><?= esc($role['status']) ?></strong> · Link: /careers/<?= esc($role['slug']) ?>
                    <?php if ($svc->isOpen($role)): ?> · <a href="<?= esc($svc->url($role), 'attr') ?>" target="_blank" rel="noopener">View live</a><?php endif; ?>
                </p>
            <?php else: ?>
                <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">Saves as a draft. Open it from the list when it is ready.</p>
            <?php endif; ?>

            <form method="post" action="<?= $action ?>">
                <?= csrf_field() ?>

                <div class="form-row">
                    <div class="field">
                        <label for="r-title">Title</label>
                        <input type="text" id="r-title" name="title" required maxlength="120" value="<?= esc($v('title'), 'attr') ?>">
                        <?= $err('title') ?>
                    </div>
                    <div class="field">
                        <label for="r-team">Team</label>
                        <select id="r-team" name="team">
                            <?php foreach ($svc->config()->teams as $key => $label): ?>
                                <option value="<?= esc($key, 'attr') ?>" <?= $v('team') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('team') ?>
                    </div>
                </div>

                <div class="field">
                    <label for="r-summary">One line summary</label>
                    <input type="text" id="r-summary" name="summary" required maxlength="<?= App\Services\CareersService::MAX_SUMMARY ?>" value="<?= esc($v('summary'), 'attr') ?>">
                    <div class="hint">Shown on the /careers list and under the title.</div>
                    <?= $err('summary') ?>
                </div>

                <div class="field">
                    <label for="r-description">About the role</label>
                    <textarea id="r-description" name="description" rows="8" required maxlength="<?= App\Services\CareersService::MAX_DESCRIPTION ?>"><?= esc($v('description')) ?></textarea>
                    <div class="hint">A few short paragraphs: what the work is and why it matters. Leave a blank line between paragraphs.</div>
                    <?= $err('description') ?>
                </div>

                <?php
                // The listed sections, labelled with the heading the public sees.
                $listHints = [
                    'good_fit'          => 'One point per line, at least two. Start each with a verb: "Enjoy talking to business owners".',
                    'strong_candidates' => 'Optional. One point per line.',
                    'projects'          => 'Optional. One example of real work per line.',
                    'logistics'         => 'Optional. One per line. Write "Label: value" for a bold label, e.g. "Location: Remote within South Africa".',
                ];
                ?>
                <?php foreach ($listHints as $key => $hint): ?>
                    <div class="field">
                        <label for="r-<?= $key ?>"><?= esc(App\Services\CareersService::SECTIONS[$key][0]) ?></label>
                        <textarea id="r-<?= $key ?>" name="<?= $key ?>" rows="6" maxlength="<?= App\Services\CareersService::MAX_SECTION ?>"<?= $key === 'good_fit' ? ' required' : '' ?>><?= esc($v($key)) ?></textarea>
                        <div class="hint"><?= esc($hint) ?></div>
                        <?= $err($key) ?>
                    </div>
                <?php endforeach; ?>
                <p class="hint mb-4">Every role page ends with the same "About <?= esc(config('Directory')->siteName()) ?>" section, from Config\Careers.</p>

                <div class="form-row">
                    <div class="field">
                        <label for="r-type">Employment type</label>
                        <select id="r-type" name="employment_type">
                            <option value="">Not stated</option>
                            <?php foreach ($jobs->employmentTypes as $key => $t): ?>
                                <option value="<?= esc($key, 'attr') ?>" <?= $v('employment_type') === $key ? 'selected' : '' ?>><?= esc($t['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('employment_type') ?>
                    </div>
                    <div class="field">
                        <label for="r-valid">Closing date <span class="text-slate-400">(optional)</span></label>
                        <input type="date" id="r-valid" name="valid_through" value="<?= esc($v('valid_through'), 'attr') ?>">
                        <div class="hint">Leave empty to keep the role open until you close it.</div>
                        <?= $err('valid_through') ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="r-city">City or town <span class="text-slate-400">(optional)</span></label>
                        <input type="text" id="r-city" name="city" maxlength="100" value="<?= esc($v('city'), 'attr') ?>">
                    </div>
                    <div class="field">
                        <label for="r-province">Province <span class="text-slate-400">(optional)</span></label>
                        <select id="r-province" name="province">
                            <option value="">Not stated</option>
                            <?php foreach ($provinces as $p): ?>
                                <option value="<?= esc($p, 'attr') ?>" <?= $v('province') === $p ? 'selected' : '' ?>><?= esc($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('province') ?>
                    </div>
                </div>

                <div class="field">
                    <label class="font-medium"><input type="checkbox" name="is_remote" value="1" <?= $v('is_remote') ? 'checked' : '' ?>> Remote within South Africa</label>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="r-smin">Pay from (R) <span class="text-slate-400">(optional)</span></label>
                        <input type="text" inputmode="numeric" id="r-smin" name="salary_min" value="<?= esc($v('salary_min'), 'attr') ?>">
                        <?= $err('salary_min') ?>
                    </div>
                    <div class="field">
                        <label for="r-smax">Pay to (R) <span class="text-slate-400">(optional)</span></label>
                        <input type="text" inputmode="numeric" id="r-smax" name="salary_max" value="<?= esc($v('salary_max'), 'attr') ?>">
                    </div>
                    <div class="field">
                        <label for="r-speriod">Per</label>
                        <select id="r-speriod" name="salary_period">
                            <option value="">Choose</option>
                            <?php foreach ($jobs->salaryPeriods as $key => $p): ?>
                                <option value="<?= esc($key, 'attr') ?>" <?= $v('salary_period') === $key ? 'selected' : '' ?>><?= esc($p['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('salary_period') ?>
                    </div>
                </div>
                <div class="hint mb-4">Pay shown on the page goes into the Google for Jobs markup too. For commission roles, describe the commission in the description instead.</div>

                <?= view('partials/_screening_picker', [
                    'screening' => $svc->screening(),
                    'level'     => $v('screening_level'),
                    'error'     => (string) ($errors['screening_level'] ?? ''),
                ]) ?>

                <div class="field">
                    <label for="r-sort">Order on the page</label>
                    <input type="number" id="r-sort" name="sort_order" value="<?= esc($v('sort_order') ?: '0', 'attr') ?>">
                    <div class="hint">Lower numbers come first within a team.</div>
                </div>

                <button type="submit" class="btn btn-primary">Save role</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
