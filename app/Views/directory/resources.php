<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('resources');

/**
 * Guides and reports: every PDF we publish for business owners. The list is
 * Contact::resources(); to add one, put the PDF under public/assets/ and add
 * an entry there.
 *
 * Built from the company page's pieces (.section bands, .company-steps rows)
 * so it reads as part of the same site. Each row is a native <details> that
 * rolls down to the summary, what the document covers, and two buttons:
 * Open reads it in a new tab (leaving the site to open a PDF loses whatever
 * the visitor was doing), Download saves it. The rows share a name, so
 * browsers that support it keep one open at a time; older ones let several
 * open, which is harmless.
 */
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Guides and reports | ' . $siteName,
    'description' => 'Free guides and reports from ' . $siteName . ' for South African businesses: '
        . 'a profile guide, the local visibility playbook, the Verified Business brochure, the 2027 local visibility outlook and a hiring guide.',
    'canonical'   => $canonical,
    'schema'      => schema_page([], $canonical, 'CollectionPage', 'Guides and reports'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="section-head-split">
            <div>
                <span class="eyebrow">Guides and reports</span>
                <h1 class="mt-3">Free reading for business owners</h1>
                <p>Practical guides on being found by local customers, and what <?= esc($siteName) ?> offers. Free to download, print and share.</p>
            </div>
        </div>

        <?php if ($documents === []): ?>
            <p class="empty">Nothing to download just yet.</p>
        <?php else: ?>
            <ol class="company-steps resource-list">
                <?php foreach ($documents as $i => $doc): ?>
                    <?php $url = base_url($doc['file']) . '?v=' . (int) $doc['version']; ?>
                    <li>
                        <span class="company-step-num"><?= sprintf('%02d', $i + 1) ?></span>
                        <details class="resource" name="resources">
                            <summary class="resource-summary">
                                <span>
                                    <span class="resource-tag"><?= esc($doc['tag']) ?></span>
                                    <h2><?= esc($doc['title']) ?></h2>
                                </span>
                                <span class="resource-pages"><span class="hidden sm:inline">PDF · <?= (int) $doc['pages'] ?> pages</span><?= lucide('chevron-down', 'resource-chevron') ?></span>
                            </summary>
                            <div class="resource-body">
                                <p><?= esc($doc['summary']) ?></p>
                                <?php if (! empty($doc['inside'])): ?>
                                    <p class="resource-inside"><strong>Inside:</strong> <?= esc(implode(' · ', $doc['inside'])) ?></p>
                                <?php endif; ?>
                                <p class="resource-meta">PDF · <?= (int) $doc['pages'] ?> pages · <?= esc($doc['size']) ?></p>
                                <div class="resource-actions">
                                    <a class="btn btn-ghost" href="<?= $url ?>" target="_blank" rel="noopener">
                                        <?= lucide('external-link', 'h-4 w-4 shrink-0') ?>Open<span class="sr-only"> <?= esc($doc['title']) ?> (PDF, new tab)</span>
                                    </a>
                                    <a class="btn btn-primary" href="<?= $url ?>" download="<?= esc($doc['download'], 'attr') ?>">
                                        <?= lucide('download', 'h-4 w-4 shrink-0') ?>Download<span class="sr-only"> <?= esc($doc['title']) ?> (PDF)</span>
                                    </a>
                                </div>
                            </div>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <h2>Ready to be found?</h2>
            <p>A free business profile takes a couple of minutes. No monthly fee. No subscription. No obligation.</p>
            <a class="btn btn-accent mt-6" href="<?= base_url('add-profile?plan=free') ?>">Create your FREE Business Profile</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
