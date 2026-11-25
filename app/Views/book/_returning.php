<?php
/**
 * "Booked here before?" above the details form (Book::page, T37). Three
 * states: ask for the email, ask for the code, or say whose details are
 * filled in. Its own small forms, never inside the details form. Each
 * carries the chosen time, so every answer lands back on this step.
 *
 * The wording never says whether an email belongs to a patient here.
 *
 * @var string                                     $base      directory/{slug}/book
 * @var array<string,string>                       $hidden    service_id, provider_id, place, start
 * @var array{patient:?string,email:string,pending:?string,error:mixed,sent:bool} $returning
 */
$fields = static function () use ($hidden): string {
    $out = csrf_field();
    foreach ($hidden as $name => $value) {
        if ($value !== '') {
            $out .= '<input type="hidden" name="' . esc($name, 'attr') . '" value="' . esc($value, 'attr') . '">';
        }
    }

    return $out;
};
$error = is_string($returning['error']) ? $returning['error'] : '';
?>
<div class="book-returning" id="returning">
    <?php if ($returning['patient'] !== null): ?>
        <div class="book-returning-done">
            <?= lucide('check', 'h-5 w-5 shrink-0 text-emerald-600') ?>
            <p class="flex-1 text-sm">
                <strong>Welcome back<?= $returning['patient'] !== '' ? ', ' . esc($returning['patient']) : '' ?>.</strong>
                Your details are filled in. Check them and book.
            </p>
            <form method="post" action="<?= base_url($base . '/returning/forget') ?>">
                <?= $fields() ?>
                <button type="submit" class="text-sm underline">Not you?</button>
            </form>
        </div>
    <?php elseif ($returning['pending'] !== null): ?>
        <h3 class="book-returning-title">Enter the 6 digit code we emailed you</h3>
        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">If <?= esc($returning['pending']) ?> has booked here before, an email is on its way with a code that works for <?= (int) \App\Services\Bookings\ReturningPatientService::CODE_MINUTES ?> minutes, and a link that brings you back to this time.</p>
        <form method="post" action="<?= base_url($base . '/returning/code') ?>" class="book-returning-form">
            <?= $fields() ?>
            <label for="returning-code" class="sr-only">Code</label>
            <input id="returning-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required class="book-returning-code">
            <button type="submit" class="btn btn-primary">Fill in my details</button>
        </form>
        <?php if ($error !== ''): ?><p class="mt-2 text-sm text-rose-600"><?= esc($error) ?></p><?php endif ?>
        <form method="post" action="<?= base_url($base . '/returning/forget') ?>" class="mt-2">
            <?= $fields() ?>
            <p class="text-sm text-slate-500 dark:text-slate-400">Nothing arrived? <button type="submit" class="underline">Use another email</button>, or fill in your details below.</p>
        </form>
    <?php else: ?>
        <h3 class="book-returning-title">Booked here before?</h3>
        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">We can email you a code and fill in your details. New here? Fill in the form below.</p>
        <form method="post" action="<?= base_url($base . '/returning') ?>" class="book-returning-form">
            <?= $fields() ?>
            <?= honeypot_field() ?>
            <label for="returning-email" class="sr-only">Your email</label>
            <input id="returning-email" name="returning_email" type="email" maxlength="190" autocomplete="email" required placeholder="The email you booked with">
            <button type="submit" class="btn btn-ghost">Send me a code</button>
        </form>
        <?php if ($error !== ''): ?><p class="mt-2 text-sm text-rose-600"><?= esc($error) ?></p><?php endif ?>
        <?php if ($returning['sent']): ?><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">If we know this email, we have sent a code.</p><?php endif ?>
    <?php endif ?>
</div>
