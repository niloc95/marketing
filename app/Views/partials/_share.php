<?php
/**
 * The share row: business profiles, Jobs board posts and /careers roles.
 *
 * On a phone (navigator.share) directory.js swaps the network links for one
 * Share button that opens the system sheet; elsewhere the links stay. Copy
 * link works everywhere the Clipboard API does. One partial so the three
 * pages cannot drift; directory.js binds every [data-share] on the page.
 *
 * @var string        $title    what the link is called when shared
 * @var string        $url      the canonical URL to share
 * @var list<string>  $networks any of whatsapp, facebook, linkedin, x, in this order
 */
$links = [
    'whatsapp' => ['https://wa.me/?text=' . rawurlencode($title . ' ' . $url), 'Share on WhatsApp'],
    'facebook' => ['https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url), 'Share on Facebook'],
    'linkedin' => ['https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($url), 'Share on LinkedIn'],
    'x'        => ['https://twitter.com/intent/tweet?text=' . rawurlencode($title) . '&url=' . rawurlencode($url), 'Share on X'],
];
?>
<div class="share-row" data-share data-share-title="<?= esc($title, 'attr') ?>" data-share-url="<?= esc($url, 'attr') ?>">
    <button type="button" class="share-icon" data-share-native hidden aria-label="Share" title="Share">
        <?= lucide('share') ?>
    </button>
    <?php foreach ($networks as $network): ?>
        <?php [$href, $label] = $links[$network]; ?>
        <a class="share-icon" data-share-fallback href="<?= esc($href, 'attr') ?>" target="_blank" rel="noopener" aria-label="<?= esc($label, 'attr') ?>" title="<?= esc($label, 'attr') ?>">
            <?php if ($network === 'x'): ?>
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-7.2 8.2L23 22h-6.6l-5.2-6.8L5.2 22H2l7.7-8.8L1.5 2h6.8l4.7 6.2L18.9 2Zm-1.2 18h1.8L7.4 4H5.5l12.2 16Z"/></svg>
            <?php else: ?>
                <?= brand_icon($network) ?>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    <button type="button" class="share-icon" data-share-copy aria-label="Copy link" title="Copy link">
        <?= lucide('link', 'icon-link') ?>
        <?= lucide('check', 'icon-check') ?>
    </button>
</div>
