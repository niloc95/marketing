<?php
/**
 * The profile setup service, offered in one bold line where an owner fills
 * in their profile: the signup form (form.php) and the owner's edit page
 * (manage_edit.php). One copy so the price and wording cannot drift; the
 * price comes from the same config the /profile-setup page reads.
 */
$setupPrice = money(rtrim(rtrim(config('Directory')->profileSetupAmount(), '0'), '.'));
?>
<p class="mb-6 rounded-surface bg-slate-50 p-4 text-sm text-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
    <strong>Short on time? For a single payment of <?= esc($setupPrice) ?>, we complete your profile for you.</strong>
    <a class="font-semibold text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('profile-setup') ?>">See how it works</a>
</p>
