<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;padding:24px">
    <div style="max-width:520px;margin:0 auto">
        <h2 style="color:#003049;font-size:18px;margin:0 0 12px">New published listing — <?= esc($site) ?></h2>
        <p style="font-size:15px"><strong><?= esc($listing['display_name'] ?? '') ?></strong> just verified and published a listing.</p>
        <ul style="font-size:14px;color:#334155;line-height:1.7">
            <li>Email: <?= esc($listing['email'] ?? '') ?></li>
            <li>Phone: <?= esc($listing['phone'] ?? '') ?></li>
            <li>City: <?= esc(trim(($listing['city'] ?? '') . ' ' . ($listing['province'] ?? ''))) ?></li>
            <li>Source: <?= esc($listing['source'] ?? '') ?><?= ! empty($listing['source_url']) ? ' (' . esc($listing['source_url']) . ')' : '' ?></li>
        </ul>
        <p style="margin:18px 0"><a href="<?= esc($url, 'attr') ?>" style="color:#003049;font-weight:700">View listing &rarr;</a></p>
    </div>
</body>
</html>
