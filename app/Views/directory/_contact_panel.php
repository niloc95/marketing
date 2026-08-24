<?php
/**
 * The "Contact" panel, for the listing's own address or for one branch.
 *
 * Extracted from show.php so a branch is rendered by the same code as the
 * primary rather than a second, drifting copy of it. Everything here reads $row
 * by key, and a practice-location row carries the same key names by design — see
 * the ExpandPracticeLocations migration for why that naming is load-bearing.
 *
 * @var array  $row      a listing row, or a practice-location row
 * @var string $heading  panel heading
 * @var bool   $showWeb  render website + social links. False for a branch: those
 *                       are business-wide, and a per-branch copy would only go
 *                       stale against the listing's own.
 */
$heading = $heading ?? 'Contact';
$showWeb = $showWeb ?? true;

// title ("Dr, Mrs, Prof…") is the contact person's honorific, not the business
// name's — pairing it here (not the H1) keeps them together.
$contactLine = trim(($row['title'] ?? '') . ' ' . ($row['contact_person'] ?? ''));

// Two different intents, so two different Google Maps schemes. The address text
// means "show me where this is" — /maps/search/ drops a pin. The Get directions
// button in _map_panel.php means "take me there" — that one uses /maps/dir/,
// which opens routing directly rather than making the visitor tap Directions
// once they arrive. Neither needs an API key or billing account.
//
// Both take their destination from map_destination(), which only trusts our
// coordinates when the pin was actually pinpointed — otherwise Google gets the
// address text and does far better with it than our street-centroid guess would.
//
// map_address_text() rather than the inline implode this replaced: that one
// omitted address_line_2, so a listing with a unit number displayed a shorter
// address than the one its own directions link navigated to. One definition
// now, and the two can no longer disagree.
$addr      = map_address_text($row);
$mapsQuery = map_destination($row);
$mapsUrl   = $mapsQuery !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapsQuery) : '';

$socials = $showWeb ? array_filter([
    'Facebook'  => safe_external_url($row['social_facebook'] ?? ''),
    'Instagram' => safe_external_url($row['social_instagram'] ?? ''),
    'LinkedIn'  => safe_external_url($row['social_linkedin'] ?? ''),
]) : [];

$websiteUrl = $showWeb ? safe_external_url($row['website'] ?? '') : '';
?>
<div class="panel">
    <h3><?= esc($heading) ?></h3>
    <?php if ($contactLine !== ''): ?><div class="kv"><span class="k">Contact</span><span><?= esc($contactLine) ?></span></div><?php endif; ?>
    <?php // Phone/email are reversed in data-reveal-value (not plaintext tel:/mailto:)
          // so naive HTML scrapers can't lift them; the LocalBusiness JSON-LD on the
          // page still carries the real values, so crawlers are unaffected. ?>
    <?php if (! empty($row['phone'])): ?>
        <div class="kv"><span class="k">Phone</span><span><button type="button" class="reveal-btn" data-reveal data-reveal-type="tel" data-reveal-value="<?= esc(strrev($row['phone']), 'attr') ?>">Show phone</button></span></div>
    <?php endif; ?>
    <?php // Same reversal and same reveal button as the number above. A second row
          // rather than "021 555 0100 / 082 555 0100" in one, so each gets its own
          // tel: link on a phone. ?>
    <?php if (! empty($row['phone_alt'])): ?>
        <div class="kv"><span class="k">Alt phone</span><span><button type="button" class="reveal-btn" data-reveal data-reveal-type="tel" data-reveal-value="<?= esc(strrev($row['phone_alt']), 'attr') ?>">Show number</button></span></div>
    <?php endif; ?>
    <?php if (! empty($row['email'])): ?>
        <div class="kv"><span class="k">Email</span><span><button type="button" class="reveal-btn" data-reveal data-reveal-type="mailto" data-reveal-value="<?= esc(strrev($row['email']), 'attr') ?>">Show email</button></span></div>
    <?php endif; ?>
    <?php if ($websiteUrl !== ''): ?><div class="kv"><span class="k">Website</span><span><a href="<?= esc($websiteUrl, 'attr') ?>" target="_blank" rel="noopener nofollow">Visit</a></span></div><?php endif; ?>
    <?php if ($addr !== ''): ?>
        <div class="kv"><span class="k">Address</span><span>
            <?php if ($mapsUrl !== ''): ?><a href="<?= esc($mapsUrl, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= esc($addr) ?></a><?php else: ?><?= esc($addr) ?><?php endif; ?>
        </span></div>
    <?php endif; ?>
    <?php if ($socials): ?>
        <div class="kv"><span class="k">Social</span><span>
            <?php foreach ($socials as $label => $url): ?><a href="<?= esc($url, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= esc($label) ?></a>&nbsp; <?php endforeach; ?>
        </span></div>
    <?php endif; ?>
</div>
