<?php
/**
 * The "Contact" panel, for the listing's own address or for one branch.
 *
 * Extracted from show.php so a branch is rendered by the same code as the
 * primary rather than a second, drifting copy of it. Everything here reads $row
 * by key, and a practice-location row carries the same key names by design — see
 * the ExpandPracticeLocations migration for why that naming is load-bearing.
 *
 * Laid out as action rows — value on the left, icon on the right — in the order
 * a customer reaches for them: book, chat, visit the site, call, email, get
 * there.
 *
 * title and contact_person are deliberately NOT rendered. They name a private
 * individual and are kept for administration only; the signup form tells the
 * owner so. Do not add them back here — or anywhere public.
 *
 * @var array  $row      a listing row, or a practice-location row
 * @var string $heading  panel heading
 * @var bool   $showWeb  render the business-wide rows: Book online, Chat on
 *                       WhatsApp, website, socials and Suggest an edit. False for a branch: a
 *                       per-branch copy would only go stale against the
 *                       listing's own, and one edit link per profile is enough.
 */
$heading = $heading ?? 'Contact';
$showWeb = $showWeb ?? true;

// Two different intents, so two different Google Maps schemes. The address text
// means "show me where this is" — /maps/search/ drops a pin. Get directions
// means "take me there" — /maps/dir/ opens routing directly rather than making
// the visitor tap Directions once they arrive. Neither needs an API key.
//
// Both take their destination from map_destination(), which only trusts our
// coordinates when the pin was actually pinpointed — otherwise Google gets the
// address text and does far better with it than our street-centroid guess would.
//
// map_address_text() is the one definition of the address, so the text shown
// and the place the link navigates to can never disagree (an earlier inline
// implode dropped address_line_2 and did exactly that).
$addr    = map_address_text($row);
$dirUrl  = map_directions_url($row);
$wazeUrl = map_waze_url($row);

// social_profile_url() at render as well as on save, for the same reason as
// safe_external_url() below — and it also refuses a link that is not to the
// network its icon claims. Keyed by the brand_icon() name.
$socials = [];
if ($showWeb) {
    foreach (listing_social_networks() as $field => [$network]) {
        $url = social_profile_url($field, $row[$field] ?? '');
        if ($url !== null && $url !== '') {
            $socials[strtolower($network)] = [$network, $url];
        }
    }
}

// wa.me with the digits whatsapp_digits() stores; '' hides the button.
$whatsappUrl = $showWeb ? whatsapp_chat_url($row['whatsapp'] ?? '', (string) ($row['display_name'] ?? '')) : '';

// safe_external_url() at render time as well as normaliseUrl() on save: a row
// written before either check existed must still never reach an href unvetted.
$websiteUrl = $showWeb ? safe_external_url($row['website'] ?? '') : '';
$bookingUrl = $showWeb ? safe_external_url($row['booking_url'] ?? '') : '';

// "https://www.example.co.za/" reads as "example.co.za" — what a person would
// say out loud, and what fits beside the icon on a phone.
$websiteLabel = preg_replace(['#^https?://#i', '#^www\.#i', '#/$#'], '', $websiteUrl);

// Phones are shown openly — a customer should not have to tap twice to call.
// The tel: href keeps only digits and a leading +, so "(021) 555-0100" dials.
$phones = array_values(array_filter([
    trim((string) ($row['phone'] ?? '')),
    trim((string) ($row['phone_alt'] ?? '')),
], static fn (string $p): bool => $p !== ''));
$telHref = static fn (string $p): string => 'tel:' . (str_starts_with($p, '+') ? '+' : '') . preg_replace('/\D+/', '', $p);

$suggestUrl = $showWeb && ! empty($row['slug'])
    ? base_url('contact') . '?listing=' . rawurlencode((string) $row['slug'])
    : '';
?>
<div class="panel">
    <h3><?= esc($heading) ?></h3>

    <?php if ($bookingUrl !== ''): ?>
        <?php // id="book" keeps any old "#book" link landing on the right spot. ?>
        <a id="book" class="btn btn-accent btn-block contact-book" href="<?= esc($bookingUrl) ?>" target="_blank" rel="noopener nofollow"><?= lucide('calendar-days', 'h-5 w-5 shrink-0') ?>Book online</a>
    <?php endif; ?>

    <?php if ($whatsappUrl !== ''): ?>
        <a class="btn btn-whatsapp btn-block contact-book" href="<?= esc($whatsappUrl) ?>" target="_blank" rel="noopener nofollow"><?= brand_icon('whatsapp', 'h-5 w-5 shrink-0') ?>Chat on WhatsApp</a>
    <?php endif; ?>

    <?php if ($websiteUrl !== ''): ?>
        <div class="contact-row">
            <a href="<?= esc($websiteUrl) ?>" target="_blank" rel="noopener nofollow"><?= esc($websiteLabel) ?></a>
            <?= lucide('external-link') ?>
        </div>
    <?php endif; ?>

    <?php foreach ($phones as $phone): ?>
        <div class="contact-row">
            <a href="<?= esc($telHref($phone)) ?>"><?= esc($phone) ?></a>
            <?= lucide('phone') ?>
        </div>
    <?php endforeach; ?>

    <?php // Email alone stays behind the reveal: it is the one an address-harvesting
          // scraper is actually after, and nobody needs it in one tap. Reversed in
          // data-reveal-value so it never appears as plain text in the HTML; the
          // click handler in directory.js reverses it back into a mailto: link. ?>
    <?php if (! empty($row['email'])): ?>
        <div class="contact-row">
            <span><button type="button" class="reveal-btn" data-reveal data-reveal-type="mailto" data-reveal-value="<?= esc(strrev($row['email']), 'attr') ?>">Show email</button></span>
            <?= lucide('mail') ?>
        </div>
    <?php endif; ?>

    <?php if ($addr !== ''): ?>
        <div class="contact-row">
            <span class="min-w-0">
                <?php if ($dirUrl !== ''): ?><a href="<?= esc($dirUrl) ?>" target="_blank" rel="noopener nofollow">Get directions</a><?php endif; ?>
                <?php if ($wazeUrl !== ''): ?><span aria-hidden="true">&middot;</span> <a href="<?= esc($wazeUrl) ?>" target="_blank" rel="noopener nofollow">Waze</a><?php endif; ?>
                <span class="contact-sub"><?= esc($addr) ?></span>
            </span>
            <?= lucide('map-pin') ?>
        </div>
    <?php endif; ?>

    <?php if ($socials): ?>
        <div class="contact-row">
            <span class="contact-socials">
                <?php foreach ($socials as $icon => [$network, $url]): ?>
                    <a href="<?= esc($url) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= esc(($row['display_name'] ?? '') !== '' ? $row['display_name'] . ' on ' . $network : $network, 'attr') ?>" title="<?= esc($network, 'attr') ?>"><?= brand_icon($icon, 'h-5 w-5') ?></a>
                <?php endforeach; ?>
            </span>
            <?= lucide('link') ?>
        </div>
    <?php endif; ?>

    <?php if ($suggestUrl !== ''): ?>
        <a class="contact-suggest" href="<?= esc($suggestUrl) ?>" rel="nofollow"><?= lucide('square-pen') ?>Suggest an edit</a>
    <?php endif; ?>
</div>
