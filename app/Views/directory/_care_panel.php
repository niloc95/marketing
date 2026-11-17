<?php
/**
 * "For patients" ("For clients" for vets and alternative medicine): the
 * answers a patient looks for first, beside the contact card (medical first,
 * owner 17 Nov 2026). listing_care_facts() decides what goes in; nothing to
 * say, no box. The same features leave "Features & amenities".
 *
 * @var array $l
 * @var array $v
 */
$facts = listing_care_facts($l, $v);
if ($facts === []) {
    return;
}
?>
<div class="panel mt-5" id="for-patients">
    <h2 class="panel-title">For <?= esc((string) ($v['audience'] ?? 'customers')) ?></h2>
    <ul class="feature-list">
        <?php foreach ($facts as $label): ?>
            <li><?= lucide('check', 'feature-check') ?><span><?= esc($label) ?></span></li>
        <?php endforeach; ?>
    </ul>
</div>
