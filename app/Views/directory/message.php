<?= $this->extend('layouts/public') ?>

<?php
/**
 * "Email this business" (Directory::messageForm): the visitor writes, we pass
 * it on (ListingMessageService). The business's address is never on this
 * page, and the reply comes from the business straight to the visitor.
 *
 * @var array<string,mixed>      $listing
 * @var array<string,mixed>|null $branch
 * @var string                   $branchLabel
 * @var string                   $stamp   Directory::messageStamp()
 * @var array<string,mixed>      $old
 * @var array<string,string>     $errors
 * @var string                   $sent    the confirmation, after a send
 */
$siteName = config('Directory')->siteName();
$name     = (string) $listing['display_name'];
$to       = $branch === null ? $name : $name . ', ' . $branchLabel;
$profile  = listing_url($listing);
$action   = base_url('directory/' . $listing['slug'] . '/message');
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Email ' . $to . ' | ' . $siteName,
    'description' => 'Send a message to ' . $to . ' through ' . $siteName . '.',
    'canonical'   => $action,
    'robots'      => 'noindex, follow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$v   = fn (string $f) => (string) ($old[$f] ?? '');
$err = fn (string $f) => $errors[$f] ?? '';
?>
<section class="section">
    <div class="container">
        <div class="form-card form-card-narrow">
            <span class="eyebrow">Email this business</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Write to <?= esc($to) ?></h1>

            <?php if ($sent !== ''): ?>
                <div class="alert alert-success mb-6" role="status"><?= esc($sent) ?></div>
                <a class="btn btn-accent btn-block" href="<?= esc($profile, 'attr') ?>">Back to <?= esc($name) ?></a>
            <?php else: ?>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    We pass your message, and any documents you attach, on to the business by email and
                    send you a confirmation. The business replies to you directly. We do not keep a copy.
                </p>

                <form method="post" action="<?= esc($action, 'attr') ?>" enctype="multipart/form-data" data-submit-once>
                    <?= csrf_field() ?>
                    <?= honeypot_field() ?>
                    <input type="hidden" name="message_form" value="<?= esc($stamp, 'attr') ?>">
                    <?php if ($branch !== null): ?>
                        <input type="hidden" name="branch" value="<?= (int) $branch['id'] ?>">
                    <?php endif; ?>

                    <div class="field">
                        <label for="msg-name">Your name</label>
                        <input type="text" id="msg-name" name="name" required maxlength="120" autocomplete="name" value="<?= esc($v('name'), 'attr') ?>">
                        <?php if ($err('name')): ?><div class="err"><?= esc($err('name')) ?></div><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="msg-email">Your email address</label>
                        <input type="email" id="msg-email" name="email" required maxlength="190" autocomplete="email" value="<?= esc($v('email'), 'attr') ?>">
                        <div class="hint">The business replies to this address.</div>
                        <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="msg-phone">Phone <span class="text-slate-400">(optional)</span></label>
                        <input type="tel" id="msg-phone" name="phone" maxlength="40" autocomplete="tel" value="<?= esc($v('phone'), 'attr') ?>">
                        <?php if ($err('phone')): ?><div class="err"><?= esc($err('phone')) ?></div><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="msg-message">Message</label>
                        <textarea id="msg-message" name="message" rows="6" required minlength="<?= \App\Services\ListingMessageService::MIN_MESSAGE ?>" maxlength="<?= \App\Services\ListingMessageService::MAX_MESSAGE ?>"><?= esc($v('message')) ?></textarea>
                        <?php if ($err('message')): ?><div class="err"><?= esc($err('message')) ?></div><?php endif; ?>
                    </div>

                    <?php // Checked again on the server (ListingMessageService::checkAttachments());
                          // data-max-* lets directory.js stop an oversized file before it uploads. ?>
                    <div class="field">
                        <label for="msg-files">Attach documents <span class="text-slate-400">(optional)</span></label>
                        <input type="file" id="msg-files" name="attachments[]" multiple
                               accept=".pdf,.jpg,.jpeg,.png,.docx,application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                               data-max-files="<?= \App\Services\ListingMessageService::MAX_FILES ?>"
                               data-max-file-bytes="<?= \App\Services\ListingMessageService::MAX_FILE_BYTES ?>"
                               data-max-total-bytes="<?= \App\Services\ListingMessageService::MAX_TOTAL_BYTES ?>">
                        <div class="hint">Up to <?= \App\Services\ListingMessageService::MAX_FILES ?> files: PDF, JPEG, PNG or Word (.docx), 5 MB each and 10 MB together.<?php if ($errors !== []): ?> Please attach your files again.<?php endif; ?></div>
                        <?php if ($err('attachments')): ?><div class="err"><?= esc($err('attachments')) ?></div><?php endif; ?>
                    </div>

                    <div class="field">
                        <label class="flex items-start gap-2 font-normal"><input type="checkbox" name="consent" value="1" required <?= $v('consent') !== '' ? 'checked' : '' ?>> <span>I agree to <?= esc($siteName) ?> passing my message, attached files, name, email and phone to <?= esc($name) ?>, as set out in the <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('privacy') ?>">Privacy policy</a>.</span></label>
                        <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-accent btn-block">Send message</button>
                </form>

                <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($profile, 'attr') ?>">Back to <?= esc($name) ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
