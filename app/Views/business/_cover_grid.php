<?php
/**
 * Who covers whom (T36): practitioners down the side, people who sign in
 * across the top. Reception has two ticks per practitioner (looks after,
 * notices); an admin a notices tick plus "Everyone"; a team member only
 * their own. One save for the whole grid (TeamCover::saveGrid()).
 *
 * @var array<string,mixed>  $cover      providers, members, looks, notices, canCover
 * @var array<string,string> $roleLabels
 */
use Config\BookingPermissions;

$providers = $cover['providers'];
$members   = $cover['members'];
?>
<?php if ($providers !== [] && $members !== []): ?>
<form method="post" action="<?= base_url('business/team/cover') ?>" class="form-card mt-6" id="cover">
    <?= csrf_field() ?>
    <h2 class="mb-1 section-title">Who covers whom</h2>
    <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
        <strong>Looks after</strong>: reception sees and books for that person.
        <strong>Notices</strong>: gets a notice (and an email, with Email me too) when a customer books, moves or cancels online with that person.
        A doctor who wants no notices unticks their own.
    </p>
    <div class="table-scroll">
        <table class="table cover-grid">
            <thead>
                <tr>
                    <th>Customers book</th>
                    <?php foreach ($members as $m): ?>
                        <th class="text-left align-bottom">
                            <input type="hidden" name="members[]" value="<?= (int) $m['id'] ?>">
                            <span class="block"><?= esc((string) ($m['account_name'] ?: $m['account_email'])) ?></span>
                            <span class="block text-xs font-normal text-slate-500 dark:text-slate-400"><?= esc($roleLabels[$m['role']] ?? '') ?></span>
                            <?php if ($m['role'] === BookingPermissions::ADMIN): ?>
                                <label class="mt-1 inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-normal"><input type="checkbox" name="all[]" value="<?= (int) $m['id'] ?>"<?= (int) $m['notices_all'] === 1 ? ' checked' : '' ?>> Everyone</label>
                            <?php endif ?>
                        </th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $p): ?>
                    <?php $pid = (int) $p['id']; ?>
                    <tr>
                        <th scope="row" class="font-semibold"><?= esc((string) $p['name']) ?></th>
                        <?php foreach ($members as $m): ?>
                            <?php
                            $mid   = (int) $m['id'];
                            $staff = $m['role'] === BookingPermissions::STAFF;
                            $can   = $staff || in_array($pid, $cover['canCover'][$mid] ?? [], true);
                            $ref   = esc((string) ($m['account_name'] ?: $m['account_email']), 'attr') . ', ' . esc((string) $p['name'], 'attr');
                            ?>
                            <td class="text-xs">
                                <div class="flex flex-col items-start gap-1.5">
                                <?php if ($staff): ?>
                                    <label class="inline-flex items-center gap-1.5 whitespace-nowrap"><input type="checkbox" name="looks[<?= $mid ?>][]" value="<?= $pid ?>" aria-label="<?= $ref ?>: looks after"<?= in_array($pid, $cover['looks'][$mid] ?? [], true) ? ' checked' : '' ?>> Looks after</label>
                                <?php endif ?>
                                <?php if ($can): ?>
                                    <label class="inline-flex items-center gap-1.5 whitespace-nowrap"><input type="checkbox" name="notices[<?= $mid ?>][]" value="<?= $pid ?>" aria-label="<?= $ref ?>: notices"<?= in_array($pid, $cover['notices'][$mid] ?? [], true) ? ' checked' : '' ?>> Notices</label>
                                <?php else: ?>
                                    <span class="text-slate-400" title="Team members only get notices for their own bookings">&middot;</span>
                                <?php endif ?>
                                </div>
                            </td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">For reception, ticking Notices also ticks Looks after: they only get notices for people whose bookings they can open.</p>
    <button type="submit" class="btn btn-primary mt-3">Save</button>
</form>
<?php endif ?>
