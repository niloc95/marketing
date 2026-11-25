<?php
/**
 * One person's sign in, who they look after and whose notices they get
 * (T36): the same cards on the practitioner page and on the page of someone
 * who signs in without taking bookings. Admins only.
 *
 * @var array<string,mixed>  $access     Team::access()
 * @var array<string,string> $roleLabels
 */
use App\Services\Bookings\TeamService;
use Config\BookingPermissions;

$m          = $access['member'];
$id         = (int) $m['id'];
$label      = (string) ($m['account_name'] ?: $m['account_email']);
$invited    = $m['status'] === 'invited';
$isStaff    = $m['role'] === BookingPermissions::STAFF;
$isAdminRow = $m['role'] === BookingPermissions::ADMIN;
$providers  = $access['providers'];
$base       = base_url('business/team/people/' . $id);
?>
<section class="form-card" id="access">
    <h2 class="mb-1 section-title">Sign in</h2>
    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
        <?= esc((string) $m['account_email']) ?>
        <?php if ($invited): ?><span class="pill pill-pending">Invited, not joined yet</span><?php endif ?>
        <?php if ($access['pending'] !== null): ?><span class="pill pill-pending">Waiting for <?= esc((string) $access['pending']['account_email']) ?> to confirm</span><?php endif ?>
    </p>

    <div class="grid gap-4 sm:grid-cols-2">
        <form method="post" action="<?= base_url('business/team/members/' . $id . '/role') ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="acc-role-<?= $id ?>">Role</label>
                <div class="flex gap-2">
                    <select id="acc-role-<?= $id ?>" name="role">
                        <?php foreach ($roleLabels as $role => $roleLabel): ?>
                            <option value="<?= $role ?>"<?= $m['role'] === $role ? ' selected' : '' ?>><?= esc($roleLabel) ?></option>
                        <?php endforeach ?>
                    </select>
                    <button type="submit" class="btn btn-ghost btn-xs whitespace-nowrap">Change</button>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Admins manage everything. Team members see their own bookings. Reception books for the people they look after.</p>
            </div>
        </form>

        <?php if ($access['isMe']): ?>
            <p class="text-sm text-slate-500 dark:text-slate-400">Change your own email on <a class="underline" href="<?= base_url('business/account#email') ?>">your account page</a>.</p>
        <?php else: ?>
            <form method="post" action="<?= $base . '/email' ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="acc-email-<?= $id ?>">Change their email</label>
                    <div class="flex gap-2">
                        <input id="acc-email-<?= $id ?>" type="email" name="email" required autocomplete="off" placeholder="new@example.com">
                        <button type="submit" class="btn btn-ghost btn-xs whitespace-nowrap">Send link</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">We email a link to the new address. Their current email keeps working until they open it; then everything below moves to the new one.</p>
                </div>
            </form>
        <?php endif ?>
    </div>

    <?php if ($invited): ?>
        <div class="mt-2 flex flex-wrap gap-2">
            <form method="post" action="<?= $base . '/resend' ?>"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-xs"><?= lucide('send', 'h-4 w-4') ?> Send the invitation again</button></form>
            <form method="post" action="<?= $base . '/cancel' ?>"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-xs">Cancel the invitation</button></form>
        </div>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">A new link works for <?= TeamService::INVITE_DAYS ?> days, and the old one stops.</p>
    <?php endif ?>

    <?php if ($access['linkedTo'] !== []): ?>
        <p class="mt-4 text-sm">Signs in as <?= esc(implode(', ', array_column($access['linkedTo'], 'name'))) ?>, and sees their calendar as their own.</p>
    <?php endif ?>

    <?php if (! $access['isMe']): ?>
        <?php $choices = $access['handOverTo']; ?>
        <details class="mt-5 rounded-inner border border-slate-200 p-3 dark:border-slate-700" id="remove">
            <summary class="cursor-pointer text-sm font-semibold">Remove or hand over</summary>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                <?php
                $looksNames   = array_values(array_intersect_key(array_column($providers, 'name', 'id'), array_flip($access['looks'])));
                $noticesNames = array_values(array_intersect_key(array_column($providers, 'name', 'id'), array_flip($access['notices'])));
                ?>
                <?php if ($isStaff): ?>Looks after: <?= $looksNames === [] ? 'nobody' : esc(implode(', ', $looksNames)) ?>. <?php endif ?>
                Gets notices for: <?= $isAdminRow && (int) $m['notices_all'] === 1 ? 'everyone' : ($noticesNames === [] ? 'nobody' : esc(implode(', ', $noticesNames))) ?>.
            </p>
            <?php if ($choices === []): ?>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Add another admin or someone at reception to hand over to.</p>
            <?php endif ?>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <?php if ($choices !== []): ?>
                    <form method="post" action="<?= $base . '/hand-over' ?>">
                        <?= csrf_field() ?>
                        <label class="block text-sm font-semibold" for="ho-<?= $id ?>">Hand over to</label>
                        <select id="ho-<?= $id ?>" name="hand_over_to" class="mt-1">
                            <?php foreach ($choices as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= esc((string) ($c['account_name'] ?: $c['account_email'])) ?> (<?= esc($roleLabels[$c['role']] ?? '') ?>)</option>
                            <?php endforeach ?>
                        </select>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">They take over who <?= esc($label) ?> looks after and the notices. <?= esc($label) ?> keeps their sign in, e.g. while on leave.</p>
                        <button type="submit" class="btn btn-ghost btn-xs mt-2">Hand over</button>
                    </form>
                <?php endif ?>
                <form method="post" action="<?= base_url('business/team/members/' . $id . '/remove') ?>">
                    <?= csrf_field() ?>
                    <label class="block text-sm font-semibold" for="rm-<?= $id ?>">Remove <?= esc($label) ?></label>
                    <select id="rm-<?= $id ?>" name="hand_over_to" class="mt-1">
                        <?php foreach ($choices as $c): ?>
                            <option value="<?= (int) $c['id'] ?>">Hand over to <?= esc((string) ($c['account_name'] ?: $c['account_email'])) ?></option>
                        <?php endforeach ?>
                        <option value="0">Nobody takes over</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">They can no longer sign in. Their bookings and their card on your profile stay.</p>
                    <button type="submit" class="btn btn-ghost btn-xs mt-2">Remove</button>
                </form>
            </div>
        </details>
    <?php endif ?>
</section>

<?php if ($isStaff): ?>
    <form method="post" action="<?= $base . '/looks-after' ?>" class="form-card" id="looks-after">
        <?= csrf_field() ?>
        <h2 class="mb-1 section-title">Looks after</h2>
        <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">The people whose calendar <?= esc($label) ?> sees and books for. Someone newly ticked also sends them notices.</p>
        <?php if ($providers === []): ?><p class="text-sm text-slate-500">Nobody takes bookings yet.</p><?php endif ?>
        <?php foreach ($providers as $p): ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="provider_ids[]" value="<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $access['looks'], true) ? ' checked' : '' ?>> <?= esc((string) $p['name']) ?></label>
        <?php endforeach ?>
        <button type="submit" class="btn btn-primary btn-xs mt-3">Save</button>
    </form>
<?php endif ?>

<form method="post" action="<?= $base . '/notices' ?>" class="form-card" id="notices">
    <?= csrf_field() ?>
    <h2 class="mb-1 section-title">Gets notices for</h2>
    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">A notice when a customer books, moves or cancels online with these people<?= (int) $m['notify_on_appointments'] === 1 ? ', also by email' : ' (in Notices only: they switched off Email me too)' ?>.</p>
    <?php if ($isAdminRow): ?>
        <label class="mb-2 flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="all" value="1"<?= (int) $m['notices_all'] === 1 ? ' checked' : '' ?>> Everyone, including people added later</label>
    <?php endif ?>
    <?php foreach ($providers as $p): ?>
        <?php $allowed = in_array((int) $p['id'], $access['canCover'], true); ?>
        <label class="flex items-center gap-2 text-sm<?= $allowed ? '' : ' text-slate-400' ?>">
            <input type="checkbox" name="provider_ids[]" value="<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $access['notices'], true) && $allowed ? ' checked' : '' ?><?= $allowed ? '' : ' disabled' ?>>
            <?= esc((string) $p['name']) ?>
            <?php if (! $allowed): ?><span class="text-xs"><?= $isStaff ? '(tick under Looks after first)' : '(not their bookings)' ?></span><?php endif ?>
        </label>
    <?php endforeach ?>
    <button type="submit" class="btn btn-primary btn-xs mt-3">Save</button>
</form>
