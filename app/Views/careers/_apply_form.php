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
// Set by Careers::apply on success. Shown here, in place of the form, because
// the redirect lands on #apply and a top of page alert would be off screen.
$applied  = session()->getFlashdata('applied');
// Careers::apply, for a second application to the same role from the same
// email or phone (ApplicationOnceService). Also in place of the form.
$already  = session()->getFlashdata('already_applied');
?>
<?php if ($already): ?>
<div class="alert alert-info" role="status" tabindex="-1" id="apply-done">
    <p class="font-semibold">Already applied</p>
    <p class="mt-1"><?= esc($already) ?></p>
</div>
<?php return; endif; ?>
<?php if ($applied): ?>
<div class="alert alert-success" role="status" tabindex="-1" id="apply-done">
    <p class="font-semibold">Application submitted</p>
    <p class="mt-1"><?= esc($applied) ?></p>
</div>
<?php return; endif; ?>
<form method="post" action="<?= base_url('careers/apply') ?>" enctype="multipart/form-data" data-submit-once>
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

    <?php // data-screening-needs: the fields the screening step checks before Start, each with the
          // message shown under it when it is missing (directory.js). ?>
    <p class="hint mb-3">Fields marked <span class="req" aria-hidden="true">*</span> are required.</p>

    <div class="form-row">
        <div class="field">
            <label for="c-name">Your name <span class="req" aria-hidden="true">*</span></label>
            <input type="text" id="c-name" name="name" data-screening-needs="Please enter your name." required maxlength="120" autocomplete="name" value="<?= esc($v('name'), 'attr') ?>">
            <?php if ($err('name')): ?><div class="err"><?= esc($err('name')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label for="c-email">Email <span class="req" aria-hidden="true">*</span></label>
            <input type="email" id="c-email" name="email" data-screening-needs="Please enter your email address." required maxlength="190" autocomplete="email" value="<?= esc($v('email'), 'attr') ?>">
            <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="c-phone">Phone <span class="req" aria-hidden="true">*</span></label>
            <input type="tel" id="c-phone" name="phone" data-screening-needs="Please enter a phone number of at least 9 digits." required minlength="9" maxlength="40" autocomplete="tel" value="<?= esc($v('phone'), 'attr') ?>">
            <?php if ($err('phone')): ?><div class="err"><?= esc($err('phone')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label for="c-linkedin">LinkedIn or portfolio <span class="text-slate-400">(optional)</span></label>
            <input type="text" id="c-linkedin" name="linkedin_url" maxlength="255" value="<?= esc($v('linkedin_url'), 'attr') ?>" placeholder="https://…">
            <?php if ($err('linkedin_url')): ?><div class="err"><?= esc($err('linkedin_url')) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="field">
        <label for="c-cv">Your CV <span class="req" aria-hidden="true">*</span></label>
        <input type="file" id="c-cv" name="cv" data-screening-needs="Please attach your CV as a PDF or Word document." required accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
        <div class="hint">PDF or Word format only, up to 10 MB.</div>
        <?php if ($err('cv')): ?><div class="err"><?= esc($err('cv')) ?></div><?php endif; ?>
    </div>

    <div class="field">
        <label for="c-message">Tell us about yourself <span class="req" aria-hidden="true">*</span></label>
        <textarea id="c-message" name="message" rows="6" data-screening-needs="Please tell us about yourself in at least <?= App\Services\CareersService::MIN_MESSAGE ?> characters." required minlength="<?= App\Services\CareersService::MIN_MESSAGE ?>" maxlength="<?= App\Services\CareersService::MAX_MESSAGE ?>" placeholder="What you have done before, and why this role suits you."><?= esc($v('message')) ?></textarea>
        <?php if ($err('message')): ?><div class="err"><?= esc($err('message')) ?></div><?php endif; ?>
    </div>

    <div class="field">
        <label class="font-medium">
            <input type="checkbox" name="consent" value="1" required <?= $v('consent') ? 'checked' : '' ?>>
            I agree to <?= esc($siteName) ?> keeping my application and CV for <?= $months ?> months to consider me for this and future roles. See our <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('privacy') ?>#careers" target="_blank" rel="noopener">Privacy policy</a>.
        </label>
        <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
    </div>

    <?php
    // Screening questions (App\Libraries\Screening), last so the clock is
    // not running while the details are typed. On a role page, that role's
    // step. On /careers, a step for every open role that asks questions, each
    // hidden and disabled until the picker chooses its role (directory.js),
    // so only that role's token posts.
    $screened = array_values(array_filter($role !== null ? [$role] : $roles, [$svc, 'screens']));
    ?>
    <?php foreach ($screened as $r): ?>
        <?= view('partials/_screening_questions', [
            'roleId'    => (int) $r['id'],
            'level'     => (string) $r['screening_level'],
            'attempt'   => $svc->screeningView($r),
            'error'     => $err('screening'),
            'off'       => $role === null && $picked !== (string) $r['id'],
            'who'       => 'us',
            'startUrl'  => base_url('careers/screening/start'),
            'answerUrl' => base_url('careers/screening/answer'),
            'screening' => $svc->screening(),
        ]) ?>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-accent btn-block">Send my application</button>
</form>
