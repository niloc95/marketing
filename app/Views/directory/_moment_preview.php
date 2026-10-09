<?php
/**
 * The illustration beside each of the five homepage moments: a small, quiet
 * rendering of the part of a profile that moment is about.
 *
 * Placeholder content on purpose ("Your business name", "Practice manager"):
 * it shows what YOUR profile would look like, and it must never read as a real
 * business. The whole thing is aria-hidden — the moment's own heading and text
 * already say what it shows.
 *
 * @var string $kind business|services|locations|people|opportunities
 */
?>
<div class="preview" aria-hidden="true">
<?php if ($kind === 'business'): ?>
    <div class="preview-head">
        <span class="preview-avatar preview-avatar-lg">YB</span>
        <div>
            <p class="preview-name">Your business name</p>
            <p class="preview-meta">Your category · Your town</p>
        </div>
    </div>
    <ul class="preview-rows">
        <li><?= lucide('calendar-days', 'h-4 w-4 shrink-0') ?>Open today · 08:00 – 17:00</li>
        <li><?= lucide('phone', 'h-4 w-4 shrink-0') ?>Call or WhatsApp</li>
        <li><?= lucide('map-pin', 'h-4 w-4 shrink-0') ?>On the map</li>
    </ul>
    <div class="preview-photos"><span></span><span></span><span></span></div>

<?php elseif ($kind === 'services'): ?>
    <p class="preview-label">Services</p>
    <ul class="preview-rows preview-rows-split">
        <li><span>Consultation</span><span class="preview-price">R 450</span></li>
        <li><span>Follow up visit</span><span class="preview-price">R 300</span></li>
        <li><span>Callout</span><span class="preview-price">On request</span></li>
        <li><span>After hours</span><span class="preview-price">On request</span></li>
    </ul>

<?php elseif ($kind === 'locations'): ?>
    <p class="preview-label">Locations</p>
    <ul class="preview-rows preview-rows-stacked">
        <li><?= lucide('map-pin', 'h-4 w-4 shrink-0') ?><span><strong>Head office</strong>Your city</span></li>
        <li><?= lucide('map-pin', 'h-4 w-4 shrink-0') ?><span><strong>Branch</strong>Another town</span></li>
        <li><?= lucide('map-pin', 'h-4 w-4 shrink-0') ?><span><strong>Practice</strong>A third town</span></li>
    </ul>

<?php elseif ($kind === 'people'): ?>
    <p class="preview-label">Our team</p>
    <ul class="preview-rows preview-rows-stacked">
        <li><span class="preview-avatar">AN</span><span><strong>Practice manager</strong>Qualifications · Expertise</span></li>
        <li><span class="preview-avatar">TM</span><span><strong>Lead technician</strong>Qualifications · Expertise</span></li>
        <li><span class="preview-avatar">SK</span><span><strong>Consultant</strong>Qualifications · Expertise</span></li>
    </ul>

<?php elseif ($kind === 'opportunities'): ?>
    <p class="preview-label">Vacancies</p>
    <ul class="preview-rows preview-rows-stacked">
        <li><?= lucide('briefcase', 'h-4 w-4 shrink-0') ?><span><strong>Receptionist</strong>Full time · Your town</span></li>
        <li><?= lucide('briefcase', 'h-4 w-4 shrink-0') ?><span><strong>Apprentice</strong>Part time · Your town</span></li>
    </ul>
<?php endif; ?>
</div>
