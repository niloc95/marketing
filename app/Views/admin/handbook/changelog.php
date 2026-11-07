<?php
/**
 * Newest first. Add one dated line for every change that touches what this
 * handbook describes (tables, services, dependencies, tokens, cron, ids,
 * external services, either site, Bookings or the brand site). Commit hash
 * once it is known.
 */
$entries = [
    ['2026-11-07', 'System handbook added at /admin/documents/system; document library (Config\Documents) puts every published PDF in public/assets/documents/.'],
    ['2026-11-07', 'Offsite backups to S3 af-south-1 live (8aaab6e).'],
    ['2026-11-05', 'Bookings phases 0 to 10 and .app merged to main, dark behind bookings.enabled (caf5511).'],
    ['2026-11-04', 'Mautic retired: own newsletter list (xs_newsletter_subscribers), brand site form posts to Local.'],
    ['2026-11-03', 'Paystack for every new payment, WSL- references (1428ee4).'],
    ['2026-11-02', 'Yearly billing; /pricing.json feeds the brand site (9942955).'],
    ['2026-11-01', 'Admin dashboard, profile statistics, form health; brand site moved to its own repo.'],
    ['2026-10-25', 'local.webscheduler.app live for every country but South Africa (b60ae84).'],
];
?>
<div class="prose-legal">
    <ul>
        <?php foreach ($entries as [$date, $text]): ?>
            <li><strong><?= esc(date('j M Y', strtotime($date))) ?>:</strong> <?= esc($text) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
