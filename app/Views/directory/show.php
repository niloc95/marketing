<?= $this->extend('layouts/public') ?>

<?php
$name = $l['display_name'] ?? '';
$prof = $l['profession']['name'] ?? ($l['profession_name'] ?? '');
$place = trim(implode(', ', array_filter([$l['suburb'] ?? '', $l['city'] ?? '', $l['province'] ?? ''])));
$logo = $l['logo_path'] ?? '';
$logoUrl = $logo === '' ? '' : (preg_match('#^https?://#i', $logo) ? $logo : base_url($logo));
$canonical = base_url('directory/' . ($l['slug'] ?? ''));
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));

$address = array_filter([
    '@type'           => 'PostalAddress',
    'streetAddress'   => $l['address_line'] ?? '',
    'addressLocality' => $l['city'] ?? '',
    'addressRegion'   => $l['province'] ?? '',
    'postalCode'      => $l['postal_code'] ?? '',
    'addressCountry'  => $l['country'] ?? '',
]);
$schema = array_filter([
    '@context'        => 'https://schema.org',
    '@type'           => ($l['type'] ?? 'person') === 'person' ? 'Physician' : 'MedicalBusiness',
    'name'            => $name,
    'url'             => $canonical,
    'telephone'       => $l['phone'] ?? '',
    'email'           => $l['email'] ?? '',
    'image'           => $logoUrl,
    'medicalSpecialty' => $prof,
    'address'         => count($address) > 1 ? $address : null,
]);
$metaDesc = $prof ? ($name . ' — ' . $prof . ($place ? ' in ' . $place : '') . '.') : $name;
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => trim($name . ($prof ? ' · ' . $prof : '')) . ' — WebScheduler Directory',
    'description' => $metaDesc,
    'canonical'   => $canonical,
    'image'       => $logoUrl,
    'type'        => 'profile',
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <p style="margin:0 0 16px"><a href="<?= base_url('directory') ?>">&larr; Back to directory</a></p>

        <div class="profile-head">
            <div class="avatar">
                <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl, 'attr') ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
            </div>
            <div>
                <?php if ($prof): ?><span class="cat" style="color:var(--orange);font-weight:700"><?= esc($prof) ?></span><?php endif; ?>
                <h1><?= esc(trim(($l['title'] ?? '') . ' ' . $name)) ?></h1>
                <?php if ($place): ?><div class="meta" style="color:var(--muted)">📍 <?= esc($place) ?></div><?php endif; ?>
                <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured" style="margin-top:8px">★ Featured</span><?php endif; ?>
            </div>
        </div>

        <div class="profile-grid">
            <div>
                <?php if (! empty($l['description'])): ?>
                    <div class="panel" style="margin-bottom:20px">
                        <h3>About</h3>
                        <p style="margin:0;white-space:pre-line"><?= esc($l['description']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['qualifications'])): ?>
                    <div class="panel" style="margin-bottom:20px">
                        <h3>Qualifications</h3>
                        <p style="margin:0;white-space:pre-line"><?= esc($l['qualifications']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['tags'])): ?>
                    <div class="panel" style="margin-bottom:20px">
                        <h3>Areas of focus</h3>
                        <div class="taglist">
                            <?php foreach ($l['tags'] as $t): ?><span class="tag"><?= esc($t) ?></span><?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (! empty($l['locations'])): ?>
                    <div class="panel">
                        <h3>Practice locations</h3>
                        <?php foreach ($l['locations'] as $loc): ?>
                            <div class="kv">
                                <span class="k"><?= esc($loc['name'] ?: 'Location') ?></span>
                                <span><?= esc(trim(implode(', ', array_filter([$loc['address_line'] ?? '', $loc['suburb'] ?? '', $loc['city'] ?? '', $loc['province'] ?? ''])))) ?><?php if (! empty($loc['phone'])): ?> · <?= esc($loc['phone']) ?><?php endif; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <div class="panel">
                    <h3>Contact</h3>
                    <?php if (! empty($l['phone'])): ?><div class="kv"><span class="k">Phone</span><span><a href="tel:<?= esc(preg_replace('/\s+/', '', $l['phone']), 'attr') ?>"><?= esc($l['phone']) ?></a></span></div><?php endif; ?>
                    <?php if (! empty($l['email'])): ?><div class="kv"><span class="k">Email</span><span><a href="mailto:<?= esc($l['email'], 'attr') ?>"><?= esc($l['email']) ?></a></span></div><?php endif; ?>
                    <?php if (! empty($l['website'])): ?><div class="kv"><span class="k">Website</span><span><a href="<?= esc($l['website'], 'attr') ?>" target="_blank" rel="noopener nofollow">Visit</a></span></div><?php endif; ?>
                    <?php $addr = trim(implode(', ', array_filter([$l['address_line'] ?? '', $l['suburb'] ?? '', $l['city'] ?? '', $l['province'] ?? '', $l['postal_code'] ?? '']))); ?>
                    <?php if ($addr !== ''): ?><div class="kv"><span class="k">Address</span><span><?= esc($addr) ?></span></div><?php endif; ?>
                    <?php
                    $socials = array_filter([
                        'Facebook'  => $l['social_facebook'] ?? '',
                        'Instagram' => $l['social_instagram'] ?? '',
                        'LinkedIn'  => $l['social_linkedin'] ?? '',
                    ]);
                    ?>
                    <?php if ($socials): ?>
                        <div class="kv"><span class="k">Social</span><span>
                            <?php foreach ($socials as $label => $url): ?><a href="<?= esc($url, 'attr') ?>" target="_blank" rel="noopener nofollow"><?= esc($label) ?></a>&nbsp; <?php endforeach; ?>
                        </span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
