<?php
/**
 * "Brands we carry": the logos of brands the business sells, stocks or
 * services, right under its description. Free for every profile.
 *
 * One of the panels show.php renders by walking Config\Verticals' 'order', so it
 * takes the same two variables and returns early with nothing to draw.
 *
 * With more than MARQUEE_MIN logos the strip scrolls in a loop, the way
 * openai.com/business/partners does: the list is drawn twice, the track slides
 * by half its width, and the second copy is aria-hidden with empty alts so a
 * screen reader hears each brand once. Fewer logos sit still in a wrapped row.
 * Reduced motion gets the still row too (directory.css), and directory.js adds
 * a pause button and sets the speed from the count, since CSP forbids a
 * style="" attribute here.
 *
 * @var array $l the listing, from DirectoryService::getProfile()
 * @var array $v vertical_profile() — headings, nouns, CTA and order
 */
$brands = $l['brands'] ?? [];
if ($brands === []) {
    return;
}

$marquee = count($brands) > 4;

$list = static function (array $brands, bool $copy): string {
    ob_start(); ?>
    <ul class="logo-list"<?= $copy ? ' aria-hidden="true"' : '' ?>>
        <?php foreach ($brands as $b): ?>
            <li class="logo-item">
                <img src="<?= esc(base_url($b['logo_path']), 'attr') ?>"
                     alt="<?= $copy ? '' : esc($b['name'], 'attr') ?>"
                     title="<?= esc($b['name'], 'attr') ?>"
                     <?php if (! empty($b['width']) && ! empty($b['height'])): ?>width="<?= (int) $b['width'] ?>" height="<?= (int) $b['height'] ?>"<?php endif; ?>
                     loading="lazy" decoding="async">
            </li>
        <?php endforeach; ?>
    </ul>
    <?php return (string) ob_get_clean();
};
?>
<div class="panel mb-5" id="brands">
    <div class="logo-strip-head">
        <h3><?= esc($v['headings']['brands'] ?? 'Brands we carry') ?></h3>
        <?php if ($marquee): ?>
            <button type="button" class="logo-strip-toggle" data-logo-toggle hidden aria-label="Pause the brand logos">
                <span data-icon-pause><?= lucide('pause', 'h-3.5 w-3.5') ?></span>
                <span data-icon-play hidden><?= lucide('play', 'h-3.5 w-3.5') ?></span>
            </button>
        <?php endif; ?>
    </div>
    <div class="logo-strip<?= $marquee ? ' is-marquee' : '' ?>" data-logo-strip data-count="<?= count($brands) ?>">
        <div class="logo-track">
            <?= $list($brands, false) ?>
            <?php if ($marquee): ?><?= $list($brands, true) ?><?php endif; ?>
        </div>
    </div>
</div>
