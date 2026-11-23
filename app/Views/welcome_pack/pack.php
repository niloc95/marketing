<?php
/**
 * The welcome pack, as HTML for dompdf (WelcomePackService::html()).
 *
 * dompdf draws CSS 2.1 with a few extras: tables and floats for layout (no
 * flexbox or grid), DejaVu Sans for every script, images only as data: URIs
 * (remote fetching is off). The site's CSP does not apply: this markup never
 * reaches a browser.
 *
 * Pages: 1 cover and welcome letter, 2+ the profile, then the A4 QR poster,
 * the counter cards and the share kit. Everything shown here is public on the
 * profile already: $l went through listing_public_view(), email only when
 * email_public. Copy follows the site rules (Discover, no dashes or hyphenated
 * words) and, on .app, says nothing South African: $isApp is the PROFILE's
 * site, because admin on co.za can download an .app pack.
 *
 * @var array<string,mixed> $l
 * @var array<string,mixed> $v vertical_profile()
 */
helper('directory_hours');

$name     = (string) ($l['display_name'] ?? '');
$category = (string) ($l['category']['name'] ?? '');
$place    = trim(implode(', ', array_filter([$l['suburb'] ?? '', $l['city'] ?? ''])));
$planName = $verified ? 'Verified' : 'Plus';

$addressPublic = listing_address_public($l);
$address = array_values(array_filter(array_map('trim', [
    (string) ($l['location_name'] ?? ''),
    (string) ($l['address_line'] ?? ''),
    (string) ($l['address_line_2'] ?? ''),
    (string) ($l['suburb'] ?? ''),
    trim(($l['city'] ?? '') . ' ' . ($l['postal_code'] ?? '')),
    (string) (($l['province'] ?? '') ?: ($l['region'] ?? '')),
    $isApp ? (string) ($l['country'] ?? '') : '',
]), static fn (string $s): bool => $s !== ''));

$phones   = array_values(array_filter([trim((string) ($l['phone'] ?? '')), trim((string) ($l['phone_alt'] ?? ''))]));
$whatsapp = preg_replace('/\D+/', '', (string) ($l['whatsapp'] ?? ''));
$email    = ! empty($l['email_public']) ? trim((string) ($l['email'] ?? '')) : '';
$website  = listing_website_url($l['website'] ?? '');
$websiteLabel = (string) preg_replace(['#^https?://#i', '#^www\.#i', '#/$#'], '', $website);

$socials = [];
foreach (listing_social_networks() as $field => [$network]) {
    $url = social_profile_url($field, $l[$field] ?? '');
    if ($url !== null && $url !== '') {
        $socials[$network] = (string) preg_replace(['#^https?://#i', '#^www\.#i', '#/$#'], '', $url);
    }
}

$hours     = is_array($l['trading_hours'] ?? null) ? $l['trading_hours'] : [];
$anyHours  = array_filter($hours, static fn ($d): bool => is_array($d) && (($d['open'] ?? '') !== '' || ! empty($d['closed']))) !== [];
$services  = ! empty($l['_booking_services']) ? $l['_booking_services'] : ($l['services'] ?? []);
$features  = array_values(array_filter(array_map('strval', is_array($l['attributes'] ?? null) ? $l['attributes'] : [])));
$tags      = array_values(array_filter(array_map('strval', is_array($l['tags'] ?? null) ? $l['tags'] : [])));
$team      = is_array($l['team'] ?? null) ? $l['team'] : [];
$branches  = is_array($l['locations'] ?? null) ? array_values($l['locations']) : [];
$sections  = is_array($l['sections'] ?? null) ? $l['sections'] : [];
$areas     = listing_service_areas($l);
$headings  = $v['headings'] ?? [];

$hoursRows = static function (array $hours): string {
    $out = '';
    foreach (hours_days() as $key => $label) {
        $row  = $hours[$key] ?? null;
        $text = (empty($row) || ! empty($row['closed']) || trim((string) ($row['open'] ?? '')) === '') ? 'Closed' : esc((string) $row['open']) . ' to ' . esc((string) $row['close']);
        if (! empty($row['note'])) {
            $text .= '<br><span class="muted">' . esc((string) $row['note']) . '</span>';
        }
        $out .= '<tr><td class="day">' . esc($label) . '</td><td>' . $text . '</td></tr>';
    }

    return $out;
};

$brand = static function (bool $onDark = false) use ($logo): string {
    // Two sibling spans: dompdf drops a nested inline span to the next line.
    $word = '<span class="word' . ($onDark ? ' word-dark' : '') . '">WebScheduler</span> <span class="word orange">Local</span>';

    return $logo !== '' && ! $onDark
        ? '<img class="brand-logo" src="' . $logo . '" alt="">' . $word
        : $word;
};
?>
<!DOCTYPE html>
<html lang="<?= $isApp ? 'en' : 'en-ZA' ?>">
<head>
<meta charset="utf-8">
<title><?= esc($name) ?> welcome pack</title>
<style>
    @page { margin: 16mm 16mm 18mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.45; color: #0f172a; }
    h1, h2, h3 { color: #003049; margin: 0; }
    h1 { font-size: 24pt; line-height: 1.15; }
    h2 { font-size: 13pt; margin: 0 0 6px; padding-bottom: 4px; border-bottom: 2px solid #F77F00; }
    h3 { font-size: 10.5pt; margin: 0 0 3px; }
    p { margin: 0 0 7px; }
    ul, ol { margin: 0 0 7px; padding-left: 16px; }
    li { margin: 0 0 3px; }
    .muted { color: #64748b; }
    .small { font-size: 8pt; }
    .orange, .word.orange { color: #F77F00; }
    .eyebrow { font-size: 8.5pt; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; color: #F77F00; }
    .page { page-break-after: always; }
    .page-last { page-break-after: auto; }
    .footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7.5pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 4px; }
    .footer .right { float: right; }
    .brand-logo { width: 18mm; height: 18mm; vertical-align: middle; margin-right: 8px; }
    .word { font-size: 15pt; font-weight: bold; color: #003049; vertical-align: middle; white-space: nowrap; }
    .word-dark { color: #ffffff; }
    .word-small { font-size: 9pt; }
    table { border-collapse: collapse; width: 100%; }
    td { vertical-align: top; }

    /* Cover */
    .cover-band { background: #003049; color: #ffffff; padding: 7mm 8mm 8mm; border-radius: 4mm; margin-bottom: 6mm; }
    .cover-band h1 { color: #ffffff; font-size: 24pt; margin: 2mm 0 1.5mm; }
    .cover-band .sub { color: #cbd5e1; font-size: 11pt; }
    .plan-plus { display: inline-block; border: 1.5px solid #F77F00; color: #F77F00; font-weight: bold; font-size: 9pt; padding: 1.5mm 4mm; border-radius: 10mm; }
    .seal { width: 26mm; height: 26mm; }
    .own-logo { max-width: 34mm; max-height: 24mm; }
    .steps td { padding: 0 0 3mm; font-size: 9.5pt; }
    .num { width: 10mm; }
    /* Padding, not height + line-height: dompdf sets the digit on the box's bottom edge. */
    .num div { width: 7mm; padding: 1.4mm 0 1.6mm; line-height: 1; text-align: center; border-radius: 3.5mm; background: #F77F00; color: #ffffff; font-weight: bold; font-size: 10pt; }

    /* Profile */
    .head td { vertical-align: middle; }
    .qr-box { text-align: center; width: 36mm; }
    .qr-box img { width: 32mm; height: 32mm; }
    .card { border: 1px solid #e2e8f0; border-radius: 2.5mm; padding: 4mm; margin: 0 0 4mm; }
    .kv td { padding: 1.2mm 0; border-bottom: 1px solid #f1f5f9; }
    .kv td.k { width: 30mm; color: #64748b; }
    .hours td { padding: 0.8mm 0; }
    .hours td.day { width: 26mm; color: #64748b; }
    .svc td { padding: 1.4mm 0; border-bottom: 1px solid #f1f5f9; }
    .svc td.price { text-align: right; white-space: nowrap; width: 38mm; font-weight: bold; color: #003049; }
    .chip { display: inline-block; background: #f1f5f9; border-radius: 2mm; padding: 0.8mm 2.5mm; margin: 0 1.5mm 1.5mm 0; font-size: 8.5pt; }
    .prose p, .prose li { font-size: 9.5pt; }
    .block { margin: 0 0 5mm; }
    .block h2 { page-break-after: avoid; }
    .person { padding: 0 0 3mm; margin: 0 0 3mm; border-bottom: 1px solid #f1f5f9; }

    /* Poster */
    .poster { text-align: center; padding-top: 4mm; }
    .poster .head-line { font-size: 30pt; font-weight: bold; color: #003049; line-height: 1.15; margin: 8mm 0 3mm; }
    .poster .sub { font-size: 14pt; color: #334155; margin: 0 0 8mm; }
    .poster .qr { width: 120mm; height: 120mm; border: 2mm solid #F77F00; border-radius: 5mm; padding: 3mm; }
    .poster .name { font-size: 20pt; font-weight: bold; color: #003049; margin: 7mm 0 1mm; }
    .poster .url { font-size: 11pt; color: #475569; }

    /* Counter cards: four A6 cards on one A4, dashed cut lines */
    .cards td { width: 50%; height: 110mm; border: 1px dashed #94a3b8; text-align: center; vertical-align: middle; padding: 5mm; }
    .cards .c-head { font-size: 13pt; font-weight: bold; color: #003049; margin: 0 0 3mm; }
    .cards .c-qr { width: 58mm; height: 58mm; }
    .cards .c-name { font-size: 11pt; font-weight: bold; margin: 3mm 0 1mm; }
    .cards .c-url { font-size: 7.5pt; color: #475569; }

    /* Share kit */
    .copy { border-left: 3px solid #F77F00; background: #f8fafc; padding: 3mm 4mm; margin: 0 0 4mm; font-size: 9.5pt; }
    .link { color: #003049; font-weight: bold; }
</style>
</head>
<body>

<div class="footer">
    <span class="right"><?= esc($siteLabel) ?></span>
    WebScheduler Local welcome pack for <?= esc($name) ?>, made <?= esc($builtOn) ?>
</div>

<!-- 1. Cover and welcome letter -->
<div class="page">
    <table>
        <tr>
            <td><?= $brand() ?></td>
            <td style="text-align:right;vertical-align:middle">
                <?php if ($seal !== ''): ?>
                    <img class="seal" src="<?= $seal ?>" alt="">
                <?php elseif ($plus): ?>
                    <span class="plan-plus">Plus</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="cover-band" style="margin-top:5mm">
        <div class="eyebrow">Welcome pack</div>
        <h1><?= esc($name) ?></h1>
        <div class="sub"><?= esc(implode(' · ', array_filter([$category, $place]))) ?></div>
    </div>

    <p style="font-size:10.5pt">Hi <?= esc($contact) ?>,</p>
    <p style="font-size:10.5pt">Welcome to <?= esc($planName) ?>. Your <?= esc($noun) ?> profile is live on WebScheduler Local, a local business discovery and visibility platform, where <?= esc($audience) ?> discover you at:</p>
    <p style="font-size:12pt" class="link"><?= esc($shortUrl) ?></p>
    <p style="font-size:10.5pt;margin-bottom:5mm">This pack helps people who already walk past your door, or sit in your waiting room, find you again online.</p>

    <table class="steps">
        <tr>
            <td class="num"><div>1</div></td>
            <td><h3>Put up your QR poster</h3>Print the A4 poster in this pack and place it at your reception, front door or till. <?= esc(ucfirst($audience)) ?> scan it with their phone camera to open your profile.</td>
        </tr>
        <tr>
            <td class="num"><div>2</div></td>
            <td><h3>Hand out the counter cards</h3>Cut out the four counter cards and keep them at the desk, in a waiting area or with your receipts.</td>
        </tr>
        <tr>
            <td class="num"><div>3</div></td>
            <td><h3>Share your link</h3>The share kit at the back has your link and ready to send messages for WhatsApp, your email signature and social media.</td>
        </tr>
        <tr>
            <td class="num"><div>4</div></td>
            <td><h3>Keep your profile complete</h3>Sign in at <strong><?= esc($shortOwner) ?></strong> to update your hours, services and photos. Complete profiles give <?= esc($audience) ?> what they need to choose you.</td>
        </tr>
        <tr>
            <td class="num"><div>5</div></td>
            <td><h3>Use everything <?= esc($planName) ?> includes</h3>Add your team, your other branches and job vacancies from your owner area<?= $bookUrl !== '' ? '. Online bookings are on: the share kit has a QR code for your booking page' : '' ?>.</td>
        </tr>
    </table>

    <p class="muted small" style="margin-top:4mm">This pack is built from your live profile each time you download it, so it always shows your latest details. Download a fresh copy any time from your owner area.</p>
</div>

<!-- 2. The profile, ready to print -->
<div class="page">
    <table class="head" style="margin-bottom:5mm">
        <tr>
            <?php if ($ownLogo !== ''): ?>
                <td style="width:38mm"><img class="own-logo" src="<?= $ownLogo ?>" alt=""></td>
            <?php endif; ?>
            <td>
                <div class="eyebrow"><?= $verified ? 'Verified profile' : 'Plus profile' ?></div>
                <h1 style="font-size:20pt"><?= esc($name) ?></h1>
                <div class="muted" style="font-size:10.5pt"><?= esc(implode(' · ', array_filter([$category, $place]))) ?></div>
            </td>
            <td class="qr-box">
                <img src="<?= $qrProfile ?>" alt="">
                <div class="small muted">Scan to open this profile</div>
            </td>
        </tr>
    </table>

    <?php // One short row (contact beside hours), then full width blocks:
          // dompdf cannot split a table row over two pages, so nothing long
          // may sit inside a table cell. ?>
    <table style="margin-bottom:4mm">
        <tr>
            <td style="width:55%;padding-right:4mm">
                <div class="card">
                    <h2>Contact</h2>
                    <table class="kv">
                        <?php foreach ($phones as $p): ?><tr><td class="k">Phone</td><td><?= esc($p) ?></td></tr><?php endforeach; ?>
                        <?php if ($whatsapp !== ''): ?><tr><td class="k">WhatsApp</td><td>+<?= esc($whatsapp) ?></td></tr><?php endif; ?>
                        <?php if ($email !== ''): ?><tr><td class="k">Email</td><td><?= esc($email) ?></td></tr><?php endif; ?>
                        <?php if ($websiteLabel !== ''): ?><tr><td class="k">Website</td><td><?= esc($websiteLabel) ?></td></tr><?php endif; ?>
                        <?php if ($bookUrl !== ''): ?><tr><td class="k">Book online</td><td><?= esc($shortBook) ?></td></tr><?php endif; ?>
                        <?php foreach ($socials as $network => $label): ?><tr><td class="k"><?= esc($network) ?></td><td><?= esc($label) ?></td></tr><?php endforeach; ?>
                        <?php if ($addressPublic && $address !== []): ?>
                            <tr><td class="k">Address</td><td><?= implode('<br>', array_map('esc', $address)) ?></td></tr>
                        <?php elseif (! $addressPublic): ?>
                            <tr><td class="k">Where</td><td>Mobile service: we travel to you</td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </td>
            <td style="width:45%">
                <?php if ($anyHours || ! empty($l['by_appointment'])): ?>
                    <div class="card">
                        <h2><?= esc((string) ($headings['hours'] ?? 'Trading hours')) ?></h2>
                        <?php if (! empty($l['by_appointment'])): ?><p><strong>By appointment only</strong></p><?php endif; ?>
                        <?php if ($anyHours): ?><table class="hours"><?= $hoursRows($hours) ?></table><?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if ($areas !== []): ?>
        <div class="card">
            <h2>Areas we serve</h2>
            <?php foreach ($areas as $a): ?><span class="chip"><?= esc($a) ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (! empty($l['description'])): ?>
        <div class="block">
            <h2><?= esc((string) ($headings['description'] ?? 'About')) ?></h2>
            <div class="prose"><?= listing_rich_text((string) $l['description']) ?></div>
        </div>
    <?php endif; ?>
    <?php foreach ($sections as $card): ?>
        <div class="block">
            <h2><?= esc((string) $card['heading']) ?></h2>
            <div class="prose"><?= listing_rich_text((string) $card['body']) ?></div>
        </div>
    <?php endforeach; ?>

    <?php if ($services !== []): ?>
        <div class="block">
            <h2><?= esc((string) ($headings['services'] ?? 'Services')) ?></h2>
            <table class="svc">
                <?php foreach ($services as $svc): ?>
                    <?php $price = (string) ($svc['price'] ?? ($svc['price_label'] ?? '')); ?>
                    <tr>
                        <td>
                            <?= esc((string) $svc['name']) ?>
                            <?php if (! empty($svc['duration_min'])): ?><span class="muted small"> · <?= (int) $svc['duration_min'] ?> min</span><?php endif; ?>
                        </td>
                        <td class="price"><?= esc($price) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($features !== []): ?>
        <div class="block">
            <h2><?= esc((string) ($headings['features'] ?? 'Features')) ?></h2>
            <?php foreach ($features as $f): ?><span class="chip"><?= esc($f) ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tags !== []): ?>
        <div class="block">
            <h2><?= esc((string) ($headings['tags'] ?? 'Areas of focus')) ?></h2>
            <?php foreach ($tags as $t): ?><span class="chip"><?= esc($t) ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($team !== []): ?>
        <div class="block">
            <h2><?= esc((string) ($headings['team'] ?? 'Our team')) ?></h2>
            <?php foreach ($team as $m): ?>
                <div class="person">
                    <h3><?= esc((string) $m['name']) ?><?php if (! empty($m['role'])): ?> <span class="muted" style="font-weight:normal">· <?= esc((string) $m['role']) ?></span><?php endif; ?></h3>
                    <?php $creds = listing_rich_text((string) ($m['credentials'] ?? '')); ?>
                    <?php if ($creds !== ''): ?><div class="prose"><?= $creds ?></div><?php endif; ?>
                    <?php $reg = \App\Libraries\ProfessionalRegistration::forPerson($m, $regulator); ?>
                    <?php if ($reg !== null && $reg['number'] !== ''): ?>
                        <p class="small"><?= esc($reg['short']) ?> registration <strong><?= esc($reg['number']) ?></strong> <span class="muted">(<?= $reg['checked'] ? 'checked by WebScheduler Local' : 'self declared' ?>)</span></p>
                    <?php endif; ?>
                    <?php $spec = \App\Services\TeamMemberService::splitSpecializations($m['specializations'] ?? null); ?>
                    <?php if ($spec !== []): ?><div><?php foreach ($spec as $s): ?><span class="chip"><?= esc($s) ?></span><?php endforeach; ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($branches !== []): ?>
        <div class="block">
            <h2><?= esc((string) ($headings['locations'] ?? 'Other branches')) ?></h2>
            <?php foreach ($branches as $i => $b): ?>
                <?php
                $bAddress = array_values(array_filter(array_map('trim', [
                    (string) ($b['address_line'] ?? ''), (string) ($b['address_line_2'] ?? ''),
                    (string) ($b['suburb'] ?? ''), trim(($b['city'] ?? '') . ' ' . ($b['postal_code'] ?? '')),
                    (string) (($b['province'] ?? '') ?: ($b['region'] ?? '')),
                ]), static fn (string $s): bool => $s !== ''));
                $bHours = is_array($b['trading_hours'] ?? null) ? $b['trading_hours'] : [];
                // A branch on the profile's own hours stores empty days: show none.
                $bHours = array_filter($bHours, static fn ($d): bool => is_array($d) && trim((string) ($d['open'] ?? '')) !== '') !== [] ? $bHours : [];
                ?>
                <div class="person">
                    <h3><?= esc(profile_location_label($b, false, $i + 2)) ?></h3>
                    <?php if ($bAddress !== []): ?><p><?= esc(implode(', ', $bAddress)) ?></p><?php endif; ?>
                    <?php if (! empty($b['phone'])): ?><p>Phone <?= esc((string) $b['phone']) ?></p><?php endif; ?>
                    <?php if ($bHours !== []): ?><table class="hours" style="width:70mm"><?= $hoursRows($bHours) ?></table><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 3. A4 QR poster -->
<div class="page poster">
    <?= $brand() ?>
    <div class="head-line">Discover us on<br>WebScheduler Local</div>
    <div class="sub"><?= $bookUrl !== '' ? 'Scan for our hours, services and online booking' : 'Scan for our hours, services and contact details' ?></div>
    <img class="qr" src="<?= $qrProfile ?>" alt="">
    <div class="name"><?= esc($name) ?></div>
    <div class="url"><?= esc($shortUrl) ?></div>
</div>

<!-- 4. Counter cards -->
<div class="page">
    <p class="muted small" style="text-align:center;margin-bottom:2mm">Cut along the dashed lines</p>
    <table class="cards">
        <?php for ($r = 0; $r < 2; $r++): ?>
            <tr>
                <?php for ($c = 0; $c < 2; $c++): ?>
                    <td>
                        <div class="c-head">Scan to discover us</div>
                        <img class="c-qr" src="<?= $qrProfile ?>" alt="">
                        <div class="c-name"><?= esc($name) ?></div>
                        <div class="c-url"><?= esc($shortUrl) ?></div>
                        <div style="margin-top:3mm"><span class="word word-small">WebScheduler</span> <span class="word word-small orange">Local</span></div>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endfor; ?>
    </table>
</div>

<!-- 5. Share kit -->
<div class="page-last">
    <div class="eyebrow">Share kit</div>
    <h1 style="font-size:20pt;margin-bottom:5mm">Tell people where to discover you</h1>

    <table>
        <tr>
            <td style="padding-right:6mm">
                <h3>Your profile link</h3>
                <p class="link" style="font-size:11pt"><?= esc($profileUrl) ?></p>
                <p class="muted">Add it to your website, your Google Business Profile, your invoices and your WhatsApp Business profile.</p>
            </td>
            <td class="qr-box"><img src="<?= $qrProfile ?>" alt=""><div class="small muted">Your profile</div></td>
        </tr>
    </table>

    <h3 style="margin-top:4mm">For WhatsApp</h3>
    <div class="copy">You can now discover <?= esc($name) ?> on WebScheduler Local. Our hours, services and contact details are all in one place: <?= esc($profileUrl) ?></div>

    <h3>For your email signature</h3>
    <div class="copy">Discover us on WebScheduler Local: <?= esc($shortUrl) ?></div>

    <h3>For Facebook, Instagram or LinkedIn</h3>
    <div class="copy">We are on WebScheduler Local! Discover <?= esc($name) ?>, see our hours and services, and get in touch in one tap: <?= esc($profileUrl) ?></div>

    <?php if ($bookUrl !== ''): ?>
        <table style="margin-top:2mm">
            <tr>
                <td style="padding-right:6mm">
                    <h3>Your booking page</h3>
                    <p class="link"><?= esc($bookUrl) ?></p>
                    <p class="muted"><?= esc(ucfirst($audience)) ?> book online straight from this link or QR code. Print it for appointment cards.</p>
                </td>
                <td class="qr-box"><img src="<?= $qrBook ?>" alt=""><div class="small muted">Book online</div></td>
            </tr>
        </table>
    <?php endif; ?>

    <h3 style="margin-top:4mm">See how <?= esc($audience) ?> discover you</h3>
    <p>Your owner area shows views, appearances in discovery results and taps on call, WhatsApp, website and directions: <strong><?= esc($shortOwner) ?>/stats</strong></p>

    <?php if ($verified): ?>
        <h3 style="margin-top:4mm">Your Verified mark</h3>
        <p>The Verified mark shows next to your name on your profile and in discovery results. It tells <?= esc($audience) ?> that WebScheduler Local checked your business documents.</p>
    <?php endif; ?>
</div>

</body>
</html>
