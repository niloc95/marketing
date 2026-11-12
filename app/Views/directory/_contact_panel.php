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
 * a customer reaches for them: book, chat, visit the site, call, email.
 *
 * No address and no directions: those live in the location panel
 * (_map_panel.php) that every caller renders for the same row, so a profile
 * shows Get directions and Waze once (owner, 4 Nov 2026). The mobile service
 * row stays, since that panel renders nothing for a hidden address.
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
 * @var string $emailUrl where "Show email" fetches the address (Directory::email);
 *                       '' hides the row. Callers pass '' unless the owner
 *                       switched email_public on (owner, 12 Nov 2026).
 * @var string $messageUrl the "Email this business" page (Directory::messageForm);
 *                       '' hides the row. Shown whenever the row has an email,
 *                       whatever email_public says: the message is relayed and
 *                       the address stays private.
 * Every caller passes both: CI4 keeps view data between renders, so a default
 * here could inherit a branch's URL.
 */
$heading  = $heading ?? 'Contact';
$showWeb  = $showWeb ?? true;
$emailUrl = $emailUrl ?? '';
$messageUrl = $messageUrl ?? '';

// A mobile business that hides its address gets the "we travel to you" row
// instead; the location panel renders nothing for it.
$addressPublic = listing_address_public($row);

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
$websiteUrl = $showWeb ? listing_website_url($row['website'] ?? '') : '';
$bookingUrl = $showWeb ? safe_external_url($row['booking_url'] ?? '') : '';
// Online bookings on the platform (Directory::show sets `_book_url` when the
// profile takes them) win over an outside booking link.
$ownBooking = $showWeb && ($row['_book_url'] ?? '') !== '';
if ($ownBooking) {
    $bookingUrl = (string) $row['_book_url'];
}

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

// A branch with no phone or email of its own has nothing left to show here now
// the address lives in the location panel, so it gets no empty card.
$hasEmail   = ! empty($row['email']) && $emailUrl !== '';
$hasMessage = ! empty($row['email']) && $messageUrl !== '';
if ($bookingUrl === '' && $whatsappUrl === '' && $websiteUrl === '' && $phones === []
    && ! $hasEmail && ! $hasMessage && $addressPublic && $socials === [] && $suggestUrl === '') {
    return;
}
?>
<div class="panel">
    <h2 class="panel-title"><?= esc($heading) ?></h2>

    <?php if ($bookingUrl !== ''): ?>
        <?php // id="book" keeps any old "#book" link landing on the right spot. ?>
        <a id="book" class="btn btn-accent btn-block contact-book" href="<?= esc($bookingUrl) ?>" data-stat="booking"<?= $ownBooking ? '' : ' target="_blank" rel="noopener nofollow"' ?>><?= lucide('calendar-days', 'h-5 w-5 shrink-0') ?>Book online</a>
    <?php endif; ?>

    <?php if ($whatsappUrl !== ''): ?>
        <a class="btn btn-whatsapp btn-block contact-book" href="<?= esc($whatsappUrl) ?>" data-stat="whatsapp" target="_blank" rel="noopener nofollow"><?= brand_icon('whatsapp', 'h-5 w-5 shrink-0') ?>Chat on WhatsApp</a>
    <?php endif; ?>

    <?php // Email goes through us: the visitor writes on our page and we relay it,
          // so the address is never in the HTML (spam, scraping, abuse). A button
          // beside Book online and WhatsApp, in brand blue so the three differ. ?>
    <?php if ($hasMessage): ?>
        <a class="btn btn-primary btn-block contact-book" href="<?= esc($messageUrl, 'attr') ?>" rel="nofollow"><?= lucide('mail', 'h-5 w-5 shrink-0') ?><?= $showWeb ? 'Email this business' : 'Email this branch' ?></a>
    <?php endif; ?>

    <?php if ($websiteUrl !== ''): ?>
        <div class="contact-row">
            <a href="<?= esc($websiteUrl) ?>" data-stat="website" target="_blank" rel="noopener nofollow"><?= esc($websiteLabel) ?></a>
            <?= lucide('external-link') ?>
        </div>
    <?php endif; ?>

    <?php foreach ($phones as $phone): ?>
        <div class="contact-row">
            <a href="<?= esc($telHref($phone)) ?>" data-stat="call"><?= esc($phone) ?></a>
            <?= lucide('phone') ?>
        </div>
    <?php endforeach; ?>

    <?php // Only when the owner chose to show the address: still behind the reveal,
          // since it is what an address harvesting scraper is after. The click
          // handler in directory.js fetches it from $emailUrl (throttled per IP)
          // and turns it into a mailto: link. ?>
    <?php if ($hasEmail): ?>
        <div class="contact-row">
            <span><button type="button" class="reveal-btn" data-reveal data-reveal-url="<?= esc($emailUrl, 'attr') ?>">Show email</button></span>
            <?= lucide('mail') ?>
        </div>
    <?php endif; ?>

    <?php if (! $addressPublic): ?>
        <div class="contact-row">
            <a href="#service-areas">Mobile service: we travel to you</a>
            <?= lucide('car') ?>
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
