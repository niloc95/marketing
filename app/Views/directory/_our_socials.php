<?php
/**
 * WebScheduler Local's own social profiles, as a row of icons.
 *
 * Used in the footer and on /company. The links come from
 * Config\Directory::$socialLinks (brand_icon() name => URL), which also feeds
 * the Organization's sameAs, so the two cannot disagree. Renders nothing
 * when the list is empty.
 *
 * @var string|null $class extra classes on the wrapper
 */
$links = config('Directory')->socialLinks;
if ($links === []) {
    return;
}
$siteName = config('Directory')->siteName();
?>
<div class="our-socials<?= ! empty($class) ? ' ' . esc($class, 'attr') : '' ?>">
    <?php foreach ($links as $icon => $url): ?>
        <?php $network = $icon === 'linkedin' ? 'LinkedIn' : ucfirst($icon); ?>
        <a href="<?= esc($url) ?>" target="_blank" rel="noopener" aria-label="<?= esc($siteName . ' on ' . $network, 'attr') ?>" title="<?= esc($network, 'attr') ?>"><?= brand_icon($icon, 'h-5 w-5') ?></a>
    <?php endforeach; ?>
</div>
