<?= $this->extend('layouts/public') ?>

<?php
/**
 * One open role at WebScheduler Local, laid out after openai.com/careers: a
 * large title, a sticky facts column with Apply now, and the posting in the
 * sections every role shares (CareersService::SECTIONS), closing with About
 * WebScheduler Local from Config\Careers. Drafts and closed roles never reach
 * here (Careers::show 404s them).
 *
 * The JobPosting markup is built from the same CareersService::sections(), so
 * it always matches what this page shows.
 *
 * @var array<string,mixed>          $role
 * @var App\Services\CareersService  $svc
 * @var array<string,mixed>          $old
 * @var array<string,string>         $errors
 */
$siteName  = config('Directory')->siteName();
$careers   = $svc->config();
$canonical = $svc->url($role);

$facts = array_filter([
    'Team'     => $svc->teamLabel($role),
    'Location' => implode(' or ', $svc->locationsOf($role)),
    'Type'     => $svc->employmentLabel($role),
    'Pay'      => $svc->salaryText($role),
    'Closes'   => ! empty($role['valid_through']) ? date('j F Y', strtotime((string) $role['valid_through'])) : '',
], static fn ($v) => $v !== '');
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => $role['title'] . ', Careers | ' . $siteName,
    'description' => seo_excerpt((string) $role['summary'] . ' ' . (string) $role['description'], 155),
    'canonical'   => $canonical,
    'schema'      => $svc->jobPostingSchema($role),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <a class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-primary-500 dark:text-slate-400 dark:hover:text-primary-300" href="<?= base_url('careers') ?>#roles">
            <?= lucide('arrow-left', 'h-4 w-4 shrink-0') ?>All roles
        </a>

        <h1 class="mt-6 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-5xl"><?= esc($role['title']) ?></h1>
        <p class="mt-3 text-lg text-slate-600 dark:text-slate-300"><?= esc($role['summary']) ?></p>

        <div class="posting-layout mt-10">
            <aside class="posting-aside">
                <div class="posting-aside-inner">
                    <dl>
                        <?php foreach ($facts as $label => $value): ?>
                            <div class="kv"><dt class="k"><?= esc($label) ?></dt><dd><?= esc($value) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <a class="btn btn-accent btn-block mt-6" href="#apply">Apply now</a>
                    <p class="posting-share-label">Share this role</p>
                    <?= view('partials/_share', [
                        'title'    => $role['title'] . ', ' . $siteName,
                        'url'      => $canonical,
                        'networks' => ['whatsapp', 'linkedin', 'facebook', 'x'],
                    ], ['saveData' => false]) ?>
                </div>
            </aside>

            <div class="posting-body">
                <?= view('partials/_posting_sections', ['sections' => $svc->sections($role)], ['saveData' => false]) ?>

                <section class="posting-section">
                    <h2>About <?= esc($siteName) ?></h2>
                    <?php foreach ($careers->about as $p): ?>
                        <p><?= esc($p) ?></p>
                    <?php endforeach; ?>
                    <p class="posting-note"><?= esc($careers->applyAnyway) ?></p>
                </section>
            </div>
        </div>

        <div class="form-card mt-10" id="apply">
            <span class="eyebrow">Apply</span>
            <h2 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($role['title']) ?></h2>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Your application comes straight to the people hiring. Not quite right for you?
                <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('careers') ?>#roles">See all open roles</a>.
            </p>
            <?= view('careers/_apply_form', ['role' => $role, 'roles' => [], 'old' => $old, 'errors' => $errors, 'svc' => $svc], ['saveData' => false]) ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
