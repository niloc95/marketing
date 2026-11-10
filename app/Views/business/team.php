<?php
/**
 * @var list<array<string,mixed>>           $providers
 * @var array<int,array<int,array<string,mixed>>> $hours
 * @var list<array<string,mixed>>           $members
 * @var bool                                $canAdd
 * @var list<array<string,mixed>>           $profileTeam
 * @var array<string,string>                $roleLabels
 * @var int                                 $myMemberId
 */
use App\Services\Bookings\TeamService;

$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="text-xl font-bold text-slate-900 dark:text-white">Team</h1>
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The people customers can book, and when they work.</p>

<div class="mt-5 space-y-2">
    <?php foreach ($providers as $p): ?>
        <?php $archived = $p['archived_at'] !== null; $week = $hours[(int) $p['id']]; ?>
        <a href="<?= base_url('bookings/team/' . $p['id']) ?>" class="panel flex flex-wrap items-center justify-between gap-3 hover:border-brand-ocean<?= $archived ? ' opacity-60' : '' ?>">
            <span>
                <span class="block font-semibold"><?= esc((string) $p['name']) ?></span>
                <?php if ($p['title']): ?><span class="text-sm text-slate-500 dark:text-slate-400"><?= esc((string) $p['title']) ?></span><?php endif ?>
                <?php if ($archived): ?><span class="pill pill-unpublished">Archived</span><?php elseif (! $p['bookable']): ?><span class="pill pill-pending">Not bookable</span><?php endif ?>
            </span>
            <span class="flex gap-1 text-xs" aria-label="Working days">
                <?php foreach ($days as $i => $d): ?>
                    <span class="<?= $week[$i + 1]['active'] ? 'bg-brand-ocean text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-800' ?> rounded px-1.5 py-0.5"><?= $d ?></span>
                <?php endforeach ?>
            </span>
        </a>
    <?php endforeach ?>
</div>

<?php if ($canAdd): ?>
    <form method="post" action="<?= base_url('bookings/team') ?>" class="panel mt-4 flex flex-wrap items-end gap-2">
        <?= csrf_field() ?>
        <div class="min-w-0 flex-1">
            <label for="prov-name" class="mb-1 block text-sm font-semibold">Add someone customers can book</label>
            <input id="prov-name" type="text" name="name" placeholder="Name" maxlength="120" class="w-full">
        </div>
        <?php if ($profileTeam !== []): ?>
            <div>
                <label for="prov-card" class="mb-1 block text-sm font-semibold">Or from your profile's team</label>
                <select id="prov-card" name="team_member_id">
                    <option value="0">Choose</option>
                    <?php foreach ($profileTeam as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"><?= esc((string) $t['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endif ?>
        <button type="submit" class="btn btn-ghost">Add</button>
    </form>

    <section class="mt-8" id="access">
        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Who can sign in</h2>
        <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">Admins manage everything. Team members see their own bookings. Reception books for the people they are assigned to.</p>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Person</th><th>Role</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td>
                                <span class="block font-semibold"><?= esc((string) ($m['account_name'] ?: $m['account_email'])) ?></span>
                                <span class="text-xs text-slate-500 dark:text-slate-400"><?= esc((string) $m['account_email']) ?></span>
                                <?php if ($m['status'] === 'invited'): ?><span class="pill pill-pending">Invited</span><?php endif ?>
                            </td>
                            <td>
                                <form method="post" action="<?= base_url('bookings/team/members/' . $m['id'] . '/role') ?>" class="flex gap-2">
                                    <?= csrf_field() ?>
                                    <select name="role" aria-label="Role">
                                        <?php foreach ($roleLabels as $role => $label): ?>
                                            <option value="<?= $role ?>"<?= $m['role'] === $role ? ' selected' : '' ?>><?= esc($label) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                    <button type="submit" class="btn btn-ghost btn-xs">Change</button>
                                </form>
                            </td>
                            <td>
                                <?php if ((int) $m['id'] !== $myMemberId): ?>
                                    <form method="post" action="<?= base_url('bookings/team/members/' . $m['id'] . '/remove') ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-ghost btn-xs">Remove</button>
                                    </form>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <form method="post" action="<?= base_url('bookings/team/invite') ?>" class="form-card mt-4">
            <?= csrf_field() ?>
            <h3 class="mb-3 font-bold">Invite someone</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="field"><label for="inv-name">Name</label><input id="inv-name" type="text" name="name" maxlength="120" required></div>
                <div class="field"><label for="inv-email">Email</label><input id="inv-email" type="email" name="email" required></div>
                <div class="field">
                    <label for="inv-role">Role</label>
                    <select id="inv-role" name="role">
                        <?php foreach ($roleLabels as $role => $label): ?>
                            <option value="<?= $role ?>"<?= $role === 'provider' ? ' selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="field">
                    <label for="inv-provider">Customers book them as</label>
                    <select id="inv-provider" name="provider_id">
                        <option value="0">Nobody (they do not take bookings)</option>
                        <?php foreach ($providers as $p): ?>
                            <?php if ($p['archived_at'] === null): ?><option value="<?= (int) $p['id'] ?>"><?= esc((string) $p['name']) ?></option><?php endif ?>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">We email them a link to join, valid for <?= TeamService::INVITE_DAYS ?> days.</p>
            <button type="submit" class="btn btn-primary">Send invitation</button>
        </form>
    </section>
<?php endif ?>
<?= $this->endSection() ?>
