<?php
/**
 * Every plain notice the Jobs board sends: confirm, live, rejected, closing
 * soon, and the admin alert. One template because they differ only in words.
 * ReferralService sends through it too, with its own $eyebrow.
 *
 * @var string                       $site
 * @var string|null                  $eyebrow   the small line above the heading; defaults to "<site> Jobs"
 * @var string                       $heading
 * @var list<string>                 $paragraphs plain text; escaped here
 * @var array{0:string,1:string}|null $button    [label, url]
 * @var string|null                  $footnote
 * @var array{0:string,1:string}|null $footnoteLink [label, url] after the footnote
 */
?>
<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
        <p style="font-size:13px;color:#64748b;margin:0 0 6px"><?= esc($eyebrow ?? ($site . ' Jobs')) ?></p>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($heading) ?></h1>
        <?php foreach ($paragraphs as $p): ?>
            <p style="font-size:15px;line-height:1.6"><?= esc($p) ?></p>
        <?php endforeach; ?>
        <?php if (! empty($button)): ?>
            <p style="margin:22px 0">
                <a href="<?= esc($button[1], 'attr') ?>" style="background:#F77F00;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:11px;display:inline-block"><?= esc($button[0]) ?></a>
            </p>
            <p style="font-size:13px;color:#64748b">Or paste this link into your browser:<br><a href="<?= esc($button[1], 'attr') ?>" style="color:#003049"><?= esc($button[1]) ?></a></p>
        <?php endif; ?>
        <?php if (! empty($footnote) || ! empty($footnoteLink)): ?>
            <p style="font-size:13px;color:#64748b">
                <?= esc($footnote ?? '') ?>
                <?php if (! empty($footnoteLink)): ?>
                    <a href="<?= esc($footnoteLink[1], 'attr') ?>" style="color:#64748b"><?= esc($footnoteLink[0]) ?></a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
