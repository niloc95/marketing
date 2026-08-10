<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;padding:24px">
    <div style="max-width:520px;margin:0 auto">
        <h2 style="color:#003049;font-size:18px;margin:0 0 12px">
            Verification documents submitted — <?= esc($site) ?>
        </h2>
        <p style="font-size:15px">
            <strong><?= esc($listing['display_name'] ?? '') ?></strong>
            has uploaded a company registration document and an owner ID for review.
        </p>
        <ul style="font-size:14px;color:#334155;line-height:1.7">
            <li>Email: <?= esc($listing['email'] ?? '') ?></li>
            <li>City: <?= esc(trim(($listing['city'] ?? '') . ' ' . ($listing['province'] ?? ''))) ?></li>
            <li>Listing status: <?= esc($listing['status'] ?? '') ?></li>
            <?php if (empty($listing['is_verified'])): ?>
                <li><strong>Email address not confirmed yet</strong> — worth waiting before you review.</li>
            <?php endif; ?>
        </ul>
        <?php
            // The documents themselves are never attached or linked directly.
            // They are ID copies: they belong behind the admin session, reachable
            // only from the review queue, not sitting in a mailbox.
        ?>
        <p style="margin:18px 0"><a href="<?= esc($url, 'attr') ?>" style="color:#003049;font-weight:700">Open the review queue &rarr;</a></p>
    </div>
</body>
</html>
