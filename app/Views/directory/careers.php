<?= $this->extend('layouts/public') ?>

<?php
/**
 * Careers at WebScheduler Local: the open roles (searchable, in plain rows
 * like openai.com/careers/search), why join, how we hire, and a general
 * application form. Roles come from /admin/careers; each has its own page
 * with JobPosting markup (careers/role).
 *
 * Copy rules: no dashes and no hyphenated words, and we are a discovery and
 * visibility platform, never "a directory".
 *
 * @var list<array<string,mixed>>   $all      every open role
 * @var list<array<string,mixed>>   $roles    the open roles the filters leave
 * @var array{q:string,team:string,location:string} $filters
 * @var array{teams:array<string,string>,locations:list<string>} $options
 * @var App\Services\CareersService $svc
 * @var array<string,mixed>         $old
 * @var array<string,string>        $errors
 */
$siteName  = config('Directory')->siteName();
$canonical = base_url('careers');
$open      = $all;
$filtered  = array_filter($filters, static fn ($v) => $v !== '') !== [];

$faqs = [
    [
        'q' => 'Do I need experience?',
        'a' => 'For some roles, yes, and each role says what it needs. For sales and support, how you treat people matters more to us than where you have worked before.',
    ],
    [
        'q' => 'Can I work remotely?',
        'a' => 'Several roles are remote within South Africa, and each role page says where the work is. Field sales means visiting businesses in your area.',
    ],
    [
        'q' => 'What happens after I apply?',
        'a' => 'You get an email straight away to confirm we have your application and CV. We read every application and get back to you within two weeks. If there is a fit, we contact you to set up a short call, then a conversation with the team. If not, we keep your application on file for future roles for the period you agreed to.',
    ],
    [
        'q' => 'There is no role for me. Can I still apply?',
        'a' => 'Yes. Send a general application below and tell us what you would like to do. We look at those first whenever a new role opens.',
    ],
    [
        'q' => 'What do you do with my CV?',
        'a' => 'Only the people hiring can open it. We keep it for ' . (int) $svc->config()->retentionMonths . ' months and then delete it, as our <a href="' . base_url('privacy') . '#careers">Privacy policy</a> explains.',
    ],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Careers | ' . $siteName,
    'description' => 'Work at ' . $siteName . ' and help South African businesses get discovered. See our open roles in sales, customer support and engineering.',
    'canonical'   => $canonical,
    // A filtered list is the same page narrowed; only the plain one is indexed.
    'robots'      => ! $filtered,
    'schema'      => schema_page([schema_faq_page($faqs, $canonical)], $canonical, 'WebPage', 'Careers at ' . $siteName),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <span class="eyebrow text-white/80">Careers</span>
        <h1 class="mt-2 text-2xl sm:text-3xl">Help South African businesses get discovered</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            <?= esc($siteName) ?> is a local business discovery and visibility platform. We are a small team building
            something useful for the businesses in our own towns, and we are looking for people who care about them too.
        </p>
        <p class="mt-5">
            <a class="btn btn-accent" href="<?= $open === [] ? '#apply' : '#roles' ?>"><?= $open === [] ? 'Send a general application' : 'See open roles' ?></a>
        </p>
    </div>
</section>

<section class="section" id="roles">
    <div class="container">
        <h2 class="mb-4 text-2xl font-extrabold text-slate-900 dark:text-white">Open roles</h2>

        <?php if ($all === []): ?>
            <div class="panel">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    We have no open roles right now. <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="#apply">Send a general application</a>
                    and we will look at it first when one opens.
                </p>
            </div>
        <?php else: ?>
            <?php // A plain GET form, so search works with no script at all. ?>
            <form method="get" action="<?= base_url('careers') ?>#roles" class="posting-filter" role="search">
                <div class="posting-filter-search">
                    <?= lucide('search', 'posting-filter-icon h-4 w-4 shrink-0') ?>
                    <label class="sr-only" for="cf-q">Discover roles</label>
                    <input type="search" id="cf-q" name="q" maxlength="100" placeholder="Discover roles" value="<?= esc($filters['q'], 'attr') ?>">
                </div>
                <label class="sr-only" for="cf-team">Team</label>
                <select id="cf-team" name="team">
                    <option value="">All teams</option>
                    <?php foreach ($options['teams'] as $key => $label): ?>
                        <option value="<?= esc($key, 'attr') ?>" <?= $filters['team'] === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="sr-only" for="cf-location">Location</label>
                <select id="cf-location" name="location">
                    <option value="">All locations</option>
                    <?php foreach ($options['locations'] as $place): ?>
                        <option value="<?= esc($place, 'attr') ?>" <?= $filters['location'] === $place ? 'selected' : '' ?>><?= esc($place) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Discover</button>
            </form>

            <p class="mb-2 mt-5 text-sm text-slate-500 dark:text-slate-400">
                <?= count($roles) ?> <?= count($roles) === 1 ? 'role' : 'roles' ?><?= $filtered ? ' match' : ' open' ?>
                <?php if ($filtered): ?>
                    · <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('careers') ?>#roles">Clear filters</a>
                <?php endif; ?>
            </p>

            <?php if ($roles === []): ?>
                <p class="posting-empty">
                    No roles match. <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('careers') ?>#roles">Clear filters</a>
                    or <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="#apply">send a general application</a>.
                </p>
            <?php else: ?>
                <ul class="posting-list">
                    <?php foreach ($roles as $r): ?>
                        <li>
                            <a class="posting-row" href="<?= esc($svc->url($r), 'attr') ?>">
                                <span class="posting-row-main">
                                    <span class="posting-row-title"><?= esc($r['title']) ?></span>
                                    <span class="posting-row-summary"><?= esc($r['summary']) ?></span>
                                    <span class="posting-row-sub"><?= esc($svc->teamLabel($r)) ?> · <?= esc(implode(' or ', $svc->locationsOf($r)) ?: 'South Africa') ?></span>
                                </span>
                                <span class="posting-row-meta">
                                    <span><?= esc($svc->teamLabel($r)) ?></span>
                                    <span><?= esc(implode(' or ', $svc->locationsOf($r)) ?: 'South Africa') ?></span>
                                </span>
                                <?= lucide('chevron-right', 'posting-row-arrow h-5 w-5 shrink-0') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Work that you can see</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Every business you sign up, help or build for is a real shop, practice or trade down the road, now easier for customers to find.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">A small team</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Your ideas reach the people who decide, and you will help shape how the platform grows.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Built for South Africa</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">We build for local businesses in every province, in the way they actually work.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Honest by design</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">We never sell a higher place in the results, and we hold ourselves to the same standard in how we sell and support.</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="mb-4 text-2xl font-extrabold text-slate-900 dark:text-white">How we hire</h2>
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">1. Apply</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Send your CV and a few lines about yourself. It takes five minutes.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">2. A short call</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">If there is a fit, we call you to talk about the role and answer your questions.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">3. Meet the team</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">A conversation with the people you would work with, and for some roles a small practical task.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">4. An answer</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Once you have met the team, we tell you either way.</p>
            </div>
        </div>

        <h2 class="mb-4 text-2xl font-extrabold text-slate-900 dark:text-white">Questions</h2>
        <div class="panel">
            <?php foreach ($faqs as $faq): ?>
                <details class="faq-item">
                    <summary><?= esc($faq['q']) ?></summary>
                    <div class="faq-answer"><?= $faq['a'] ?></div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="apply">
    <div class="container">
        <div class="form-card">
            <span class="eyebrow">Apply</span>
            <h2 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Send us your application</h2>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Choose a role, or send a general application and tell us what you would like to do.
            </p>
            <?= view('careers/_apply_form', ['role' => null, 'roles' => $open, 'old' => $old, 'errors' => $errors, 'svc' => $svc], ['saveData' => false]) ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
