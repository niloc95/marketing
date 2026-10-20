<?php
/**
 * The frame every customer email sits in: the grey canvas and the white card.
 *
 * Styles are inline on purpose. Most mail clients drop a <style> block, so
 * this is the one place they are written rather than once per email.
 */
?>
<!DOCTYPE html>
<html lang="en">
<body style="font-family:Inter,Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px">
    <div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">
<?= $this->renderSection('content') ?>
    </div>
</body>
</html>
