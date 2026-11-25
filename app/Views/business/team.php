<?php
/**
 * @var list<array<string,mixed>>           $providers
 * @var array<int,array<int,array<string,mixed>>> $hours
 * @var array<int,bool>                          $hoursChanged opening hours changed since their own hours were set
 * @var list<array<string,mixed>>           $members
 * @var bool                                $canAdd
 * @var list<array<string,mixed>>           $profileTeam  profile cards nobody in bookings is yet
 * @var array<string,string>                $roleLabels
 * @var int                                 $myMemberId
 * @var list<array<string,mixed>>           $people  everyone in one list (admins, T36): provider, member, looks, notices, all, pending, url
 * @var array<string,mixed>                 $cover   the Who covers whom grid (admins)
 */
use App\Services\Bookings\TeamService;

$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<?php // This page is one of the profile's sections: the same tabs as /business/profile, docked. ?>
<?php if (! empty($profileTabs)): ?>
    <?= view('layouts/_profile_tabs', ['tabs' => $profileTabs, 'label' => 'Profile sections'], ['saveData' => false]) ?>
<?php endif ?>
<?php if (! $canAdd): ?>
<p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The people customers can book, and when they work.</p>

<div class="mt-5 space-y-2">
    <?php foreach ($providers as $p): ?>
        <?php $archived = $p['archived_at'] !== null; $week = $hours[(int) $p['id']]; ?>
        <a href="<?= base_url('business/team/' . $p['id']) ?>" class="panel flex flex-wrap items-center justify-between gap-3 hover:border-brand-ocean<?= $archived ? ' opacity-60' : '' ?>">
            <span>
                <span class="block font-semibold"><?= esc((string) $p['name']) ?></span>
                <?php if ($p['title']): ?><span class="text-sm text-slate-500 dark:text-slate-400"><?= esc((string) $p['title']) ?></span><?php endif ?>
                <?php if ($archived): ?><span class="pill pill-unpublished">Archived</span><?php elseif (! $p['bookable']): ?><span class="pill pill-pending">Not bookable</span><?php endif ?>
                <?php if (! $archived && ($hoursChanged[(int) $p['id']] ?? false)): ?><span class="pill pill-pending">Opening hours changed</span><?php endif ?>
            </span>
            <span class="flex gap-1 text-xs" aria-label="Working days">
                <?php foreach ($days as $i => $d): ?>
                    <span class="<?= $week[$i + 1]['active'] ? 'bg-brand-ocean text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-800' ?> rounded px-1.5 py-0.5"><?= $d ?></span>
                <?php endforeach ?>
            </span>
        </a>
    <?php endforeach ?>
</div>

<?php else: ?>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Everyone in your practice or business: who customers book, who signs in, who reception looks after and who gets notices.</p>

    <?= view('business/_team_people', ['people' => $people, 'hours' => $hours, 'hoursChanged' => $hoursChanged, 'roleLabels' => $roleLabels, 'myMemberId' => $myMemberId], ['saveData' => false]) ?>

    <?php // One person, one record (T31): adding someone who takes bookings adds their profile team card too. ?>
    <form method="post" action="<?= base_url('business/team') ?>" class="form-card mt-6" id="add">
        <?= csrf_field() ?>
        <h2 class="mb-3 section-title">Add a person</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="field"><label for="prov-name">Name</label><input id="prov-name" type="text" name="name" maxlength="150" required></div>
            <div class="field"><label for="prov-role">Position <span class="font-normal text-slate-500">(optional)</span></label><input id="prov-role" type="text" name="role" maxlength="120" placeholder="e.g. Receptionist, Dentist"></div>
            <div class="field"><label for="prov-email">Email <span class="font-normal text-slate-500">(to sign in)</span></label><input id="prov-email" type="email" name="email" autocomplete="off"></div>
            <div class="field">
                <label for="prov-member-role">They sign in as</label>
                <select id="prov-member-role" name="member_role">
                    <?php foreach ($roleLabels as $role => $label): ?>
                        <option value="<?= $role ?>"<?= $role === 'provider' ? ' selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>
        <label class="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" name="bookable" value="1" checked> Customers book them (they get hours, services and a calendar)</label>
        <label class="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" name="show_on_profile" value="1"> Show on my profile</label>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Reception or a practice manager: untick &ldquo;Customers book them&rdquo; and give their email. With an email, we send them a link to set their own password, valid for <?= TeamService::INVITE_DAYS ?> days.</p>
        <button type="submit" class="btn btn-primary">Add</button>
    </form>

    <?php if ($profileTeam !== []): ?>
        <section class="panel mt-4">
            <h2 class="mb-1 section-title">On your profile, not in bookings</h2>
            <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">People from your profile&rsquo;s team. Add them here to give them hours, services and a calendar.</p>
            <ul class="space-y-2">
                <?php foreach ($profileTeam as $t): ?>
                    <li class="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            <span class="font-semibold"><?= esc((string) $t['name']) ?></span>
                            <?php if (! empty($t['role'])): ?><span class="text-sm text-slate-500 dark:text-slate-400"> <?= esc((string) $t['role']) ?></span><?php endif ?>
                        </span>
                        <form method="post" action="<?= base_url('business/team') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="team_member_id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-xs">Add to bookings</button>
                        </form>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>


    <?= view('business/_cover_grid', ['cover' => $cover, 'roleLabels' => $roleLabels], ['saveData' => false]) ?>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Team<?= $this->endSection() ?>
