<?= $this->extend('layouts/public') ?>

<?php

use Config\Comparison;

$siteName  = config('Directory')->siteName();
$canonical = base_url('compare');

/**
 * WebScheduler Local next to Google Business Profile, LinkedIn and the South
 * African directories.
 *
 * The table comes from ComparisonService (defaults in Config\Comparison, edits
 * from /admin/comparison) and every string in it is plain text, so everything
 * is escaped here. It is written to survive a skeptical reader: the rows where
 * we are behind (reviews, built-in booking) stay in on purpose, and the
 * checked-on date sits under the table.
 *
 * The Local column says Free or Verified on every row, so nobody has to guess
 * which features need the paid badge. Nothing here may suggest that paying
 * moves a business up the results (see _plan_cards.php).
 *
 * @var array $table   ComparisonService::forPage()
 * @var string $amount monthly badge price
 * @var bool  $offered VerificationService::isEnabled()
 */
$columns = $table['columns'];
$others  = array_values(array_diff(Comparison::COLUMNS, ['local']));

$checked = strtotime((string) $table['checkedOn']);

/** A Verified pill, or a plain chip while the badge is not on sale. */
$verifiedPill = static function () use ($offered): string {
    return $offered
        ? verified_badge_pill('Verified')
        : '<span class="compare-chip compare-chip-soon">Verified (coming soon)</span>';
};

/** The WebScheduler Local cell: which tier gives you this. */
$localCell = static function (string $status) use ($verifiedPill): string {
    return match ($status) {
        'free'          => '<span class="compare-chip compare-chip-free">' . lucide('check', 'h-3.5 w-3.5 shrink-0') . 'Free</span>',
        'verified'      => $verifiedPill(),
        'free_verified' => '<span class="compare-chip compare-chip-free">' . lucide('check', 'h-3.5 w-3.5 shrink-0') . 'Free</span> '
            . '<span class="compare-plus">+</span> ' . $verifiedPill(),
        default => '<span class="compare-chip compare-chip-no">' . lucide('x', 'h-3.5 w-3.5 shrink-0') . 'Not offered</span>',
    };
};

/**
 * Any other column: an icon where there is a clear yes or no, and always the
 * word. The class names are written out in full because Tailwind keeps only
 * the component classes it can find in a source file; a concatenated
 * "compare-chip-" . $status would be dropped from the build.
 */
$otherCell = static function (string $status): string {
    $label = Comparison::OTHER_STATUSES[$status] ?? '';
    [$class, $icon] = match ($status) {
        'yes'     => ['compare-chip-yes', lucide('check', 'h-3.5 w-3.5 shrink-0')],
        'no'      => ['compare-chip-no', lucide('x', 'h-3.5 w-3.5 shrink-0')],
        'partial' => ['compare-chip-partial', ''],
        'paid'    => ['compare-chip-paid', ''],
        default   => ['', ''],
    };

    return '<span class="compare-chip ' . $class . '">' . $icon . esc($label) . '</span>';
};
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'How ' . $siteName . ' compares — Google Business Profile, LinkedIn and SA directories',
    'description' => $siteName . ' next to Google Business Profile, LinkedIn and South African directories: '
        . 'searchable staff profiles, document-checked verification and two-way jobs, and which features are free.',
    'canonical'   => $canonical,
    'schema'      => schema_page([], $canonical, 'WebPage', 'How ' . $siteName . ' compares'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">How <?= esc($siteName) ?> compares</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            Next to Google Business Profile, LinkedIn and South African directories. Where we stand out:
            staff people can search for, verification checked against real documents, and jobs that work
            both ways. Where we are behind, the table says so too.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="compare-legend" aria-label="What the labels mean">
            <span><span class="compare-chip compare-chip-free"><?= lucide('check', 'h-3.5 w-3.5 shrink-0') ?>Free</span> on every free business profile</span>
            <span><?= $verifiedPill() ?> needs Verified Business<?= $offered ? ', R' . esc($amount) . ' a month' : '' ?></span>
            <span><span class="compare-chip compare-chip-partial">Partly</span> available with limits, explained in the cell</span>
        </div>

        <div class="compare-wrap">
            <table class="compare-table">
                <caption class="sr-only"><?= esc($siteName) ?> compared with <?= esc(implode(', ', array_map(static fn ($c) => $columns[$c]['label'], $others))) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Feature</th>
                        <?php foreach (Comparison::COLUMNS as $col): ?>
                            <th scope="col" class="<?= $col === 'local' ? 'compare-ours' : '' ?>">
                                <?= esc($columns[$col]['label']) ?>
                                <?php if ($columns[$col]['sub'] !== ''): ?>
                                    <span class="compare-sub"><?= esc($columns[$col]['sub']) ?></span>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table['rows'] as $row): ?>
                        <tr id="<?= esc('compare-' . $row['key'], 'attr') ?>">
                            <th scope="row"><?= esc($row['label']) ?></th>
                            <?php foreach (Comparison::COLUMNS as $col):
                                $cell = $row['cells'][$col]; ?>
                                <td class="<?= $col === 'local' ? 'compare-ours' : '' ?>" data-label="<?= esc($columns[$col]['label'], 'attr') ?>">
                                    <?= $col === 'local' ? $localCell($cell['status']) : $otherCell($cell['status']) ?>
                                    <?php if ($cell['note'] !== ''): ?>
                                        <span class="compare-note"><?= esc($cell['note']) ?></span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="hint mt-4">
            Checked on <?= esc($checked ? date('j F Y', $checked) : (string) $table['checkedOn']) ?>, from each
            company's own help pages. Other platforms change their features, and the South African directories
            differ from one another, so we name which one we mean. Spotted something out of date?
            <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('contact') ?>">Tell us</a> and we will correct it.
        </p>
        <p class="hint">
            Verified Business checks a company registration document and the owner's ID. It does not check
            qualifications or the quality of anyone's work, and it does not move a business up the results.
            <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('verified') ?>">What the badge means</a>.
        </p>

        <div class="panel mt-8 text-center">
            <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Put your business where people can find your people</h2>
            <p class="mb-5 text-sm text-slate-600 dark:text-slate-400">
                A South African business profile is free. No monthly fee. No subscription. No obligation.
            </p>
            <div class="flex flex-wrap justify-center gap-3">
                <a class="btn btn-accent" href="<?= esc(signup_cta()['url']) ?>"><?= signup_cta()['verified'] ? 'Get Verified' : 'Create your free profile' ?></a>
                <?php if ($offered): ?>
                    <a class="btn btn-ghost" href="<?= base_url('verified') ?>">About Verified Business</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
