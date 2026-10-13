<?php
/**
 * The application form, shared by /careers (general, with a role picker) and
 * each role page (role fixed). Posts to careers/apply, which sends the
 * applicant back to whichever page they came from.
 *
 * @var array<string,mixed>|null       $role   the role page's role; null on /careers
 * @var list<array<string,mixed>>      $roles  open roles for the picker; ignored when $role is set
 * @var array<string,mixed>            $old
 * @var array<string,string>           $errors
 * @var App\Services\CareersService    $svc
 */
$siteName = config('Directory')->siteName();
$v        = static fn (string $f) => (string) ($old[$f] ?? '');
$err      = static fn (string $f) => $errors[$f] ?? '';
$months   = (int) $svc->config()->retentionMonths;
$picked   = $v('role_id');
?>
<form method="post" action="<?= base_url('careers/apply') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <!-- honeypot -->
    <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

    <?php if ($role !== null): ?>
        <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
    <?php else: ?>
        <div class="field">
            <label for="c-role">Which role?</label>
            <select id="c-role" name="role_id">
                <option value="0">General application</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $picked === (string) $r['id'] ? 'selected' : '' ?>><?= esc($r['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($err('role_id')): ?><div class="err mb-3"><?= esc($err('role_id')) ?></div><?php endif; ?>

    <div class="form-row">
        <div class="field">
            <label for="c-name">Your name</label>
            <input type="text" id="c-name" name="name" required maxlength="120" autocomplete="name" value="<?= esc($v('name'), 'attr') ?>">
            <?php if ($err('name')): ?><div class="err"><?= esc($err('name')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label for="c-email">Email</label>
            <input type="email" id="c-email" name="email" required maxlength="190" autocomplete="email" value="<?= esc($v('email'), 'attr') ?>">
            <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="c-phone">Phone <span class="text-slate-400">(optional)</span></label>
            <input type="tel" id="c-phone" name="phone" maxlength="40" autocomplete="tel" value="<?= esc($v('phone'), 'attr') ?>">
        </div>
        <div class="field">
            <label for="c-linkedin">LinkedIn or portfolio <span class="text-slate-400">(optional)</span></label>
            <input type="text" id="c-linkedin" name="linkedin_url" maxlength="255" value="<?= esc($v('linkedin_url'), 'attr') ?>" placeholder="https://…">
            <?php if ($err('linkedin_url')): ?><div class="err"><?= esc($err('linkedin_url')) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="field">
        <label for="c-cv">Your CV</label>
        <input type="file" id="c-cv" name="cv" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        <div class="hint">PDF works best, up to 10 MB. Save a Word CV as a PDF first.</div>
        <?php if ($err('cv')): ?><div class="err"><?= esc($err('cv')) ?></div><?php endif; ?>
    </div>

    <div class="field">
        <label for="c-message">Tell us about yourself</label>
        <textarea id="c-message" name="message" rows="6" required minlength="<?= App\Services\CareersService::MIN_MESSAGE ?>" maxlength="<?= App\Services\CareersService::MAX_MESSAGE ?>" placeholder="What you have done before, and why this role suits you."><?= esc($v('message')) ?></textarea>
        <?php if ($err('message')): ?><div class="err"><?= esc($err('message')) ?></div><?php endif; ?>
    </div>

    <div class="field">
        <label class="font-medium">
            <input type="checkbox" name="consent" value="1" required <?= $v('consent') ? 'checked' : '' ?>>
            I agree to <?= esc($siteName) ?> keeping my application and CV for <?= $months ?> months to consider me for this and future roles. See our <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('privacy') ?>#careers" target="_blank" rel="noopener">Privacy policy</a>.
        </label>
        <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
    </div>

    <button type="submit" class="btn btn-accent btn-block">Send my application</button>
</form>
