<?php
/**
 * A message passed from one person to another: a job application, or a
 * business replying to a service request. Reply-To is set to the sender, so
 * the recipient answers them directly and we drop out of the conversation.
 *
 * @var string               $site
 * @var string               $heading
 * @var string               $intro
 * @var array<string,string> $fields  label => value; values that look like
 *                                    links are linked, everything is escaped
 * @var string               $message
 * @var array{heading:string,level:string,lines:list<string>}|null $screeningResult
 *                                    a job application's marked screening
 *                                    answers (JobBoardService::relayApplication());
 *                                    every caller passes it, null for none,
 *                                    because CI4 keeps view data between renders
 * @var string               $footnote
 */
$screeningResult = $screeningResult ?? null;
?>
<?= $this->extend('emails/_layout') ?>
<?= $this->section('content') ?>
        <p style="font-size:13px;color:#64748b;margin:0 0 6px"><?= esc($site) ?> Jobs</p>
        <h1 style="color:#003049;font-size:20px;margin:0 0 12px"><?= esc($heading) ?></h1>
        <p style="font-size:15px;line-height:1.6"><?= esc($intro) ?></p>

        <table style="font-size:15px;line-height:1.6;border-collapse:collapse">
            <?php foreach ($fields as $label => $value): ?>
                <tr>
                    <td style="color:#64748b;padding-right:14px;vertical-align:top"><?= esc($label) ?></td>
                    <td>
                        <?php if (preg_match('#^https?://#i', $value) === 1): ?>
                            <a href="<?= esc($value) ?>" style="color:#003049"><?= esc($value) ?></a>
                        <?php else: ?>
                            <?= esc($value) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php // esc() first, then nl2br, so the sender's text cannot inject markup. ?>
        <div style="margin:22px 0;padding:16px;background:#f8fafc;border-radius:10px;font-size:15px;line-height:1.6">
            <?= nl2br(esc($message)) ?>
        </div>

        <?php if ($screeningResult !== null): ?>
            <h2 style="color:#003049;font-size:16px;margin:0 0 4px"><?= esc($screeningResult['heading']) ?></h2>
            <p style="font-size:13px;color:#64748b;margin:0 0 10px"><?= esc($screeningResult['level']) ?>. Each applicant gets their own numbers and names, so answers cannot be shared. Nobody was turned away on their score; it is a guide for you.</p>
            <ul style="font-size:14px;line-height:1.6;margin:0 0 22px;padding-left:18px">
                <?php foreach ($screeningResult['lines'] as $line): ?>
                    <li style="margin-bottom:6px"><?= esc($line) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p style="font-size:13px;color:#64748b"><?= esc($footnote) ?></p>
<?= $this->endSection() ?>
