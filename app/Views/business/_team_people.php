<?php
/**
 * Everyone in one list (T36): practitioners, with or without a sign in, and
 * people who sign in without taking bookings. Each row opens the one page
 * where everything about that person is changed.
 *
 * @var list<array<string,mixed>>          $people     Team::people()
 * @var array<int,array<int,array<string,mixed>>> $hours
 * @var array<int,bool>                    $hoursChanged
 * @var array<string,string>               $roleLabels
 * @var int                                $myMemberId
 */
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
?>
<section class="mt-5" id="people">
    <h2 class="section-title">Your team</h2>
    <div class="table-scroll mt-2">
        <table class="table">
            <thead>
                <tr><th>Person</th><th>Signs in as</th><th>Customers book them</th><th>Looks after</th><th>Gets notices for</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($people as $row): ?>
                    <?php
                    $p = $row['provider'];
                    $m = $row['member'];
                    $name = $p !== null ? (string) $p['name'] : (string) ($m['account_name'] ?: $m['account_email']);
                    $archived = $p !== null && $p['archived_at'] !== null;
                    ?>
                    <tr<?= $archived ? ' class="opacity-60"' : '' ?>>
                        <td>
                            <a class="block font-semibold underline-offset-2 hover:underline" href="<?= $row['url'] ?>"><?= esc($name) ?></a>
                            <?php if ($p !== null && $p['title']): ?><span class="text-xs text-slate-500 dark:text-slate-400"><?= esc((string) $p['title']) ?></span><?php endif ?>
                            <?php if ($m !== null): ?><span class="block text-xs text-slate-500 dark:text-slate-400"><?= esc((string) $m['account_email']) ?><?= (int) $m['id'] === $myMemberId ? ' (you)' : '' ?></span><?php endif ?>
                        </td>
                        <td>
                            <?php if ($m === null): ?>
                                <span class="text-sm text-slate-500 dark:text-slate-400">Not signing in</span>
                            <?php else: ?>
                                <?= esc($roleLabels[$m['role']] ?? '') ?>
                                <?php if ($m['status'] === 'invited'): ?><span class="pill pill-pending">Invited</span><?php endif ?>
                                <?php if ($row['pending'] !== null): ?><span class="pill pill-pending">New email waiting</span><?php endif ?>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if ($p === null): ?>
                                <span class="text-sm text-slate-500 dark:text-slate-400">No</span>
                            <?php elseif ($archived): ?>
                                <span class="pill pill-unpublished">Archived</span>
                            <?php elseif (! $p['bookable']): ?>
                                <span class="pill pill-pending">Not bookable</span>
                            <?php else: ?>
                                <?php $week = $hours[(int) $p['id']] ?? []; ?>
                                <span class="flex gap-0.5 text-[11px]" aria-label="Working days">
                                    <?php foreach ($days as $i => $d): ?>
                                        <span class="<?= ! empty($week[$i + 1]['active']) ? 'bg-brand-ocean text-white' : 'bg-slate-100 text-slate-400 dark:bg-slate-800' ?> rounded px-1 py-0.5"><?= $d ?></span>
                                    <?php endforeach ?>
                                </span>
                                <?php if ($hoursChanged[(int) $p['id']] ?? false): ?><span class="pill pill-pending">Opening hours changed</span><?php endif ?>
                            <?php endif ?>
                        </td>
                        <td class="text-sm">
                            <?php if ($m !== null && $m['role'] === 'staff'): ?>
                                <?= $row['looks'] === [] ? '<span class="pill pill-pending">Nobody yet</span>' : esc(implode(', ', $row['looks'])) ?>
                            <?php endif ?>
                        </td>
                        <td class="text-sm">
                            <?php if ($m !== null): ?>
                                <?= $row['all'] ? 'Everyone' : ($row['notices'] === [] ? '<span class="text-slate-500 dark:text-slate-400">None</span>' : esc(implode(', ', $row['notices']))) ?>
                                <?php if ((int) $m['notify_on_appointments'] !== 1 && ($row['all'] || $row['notices'] !== [])): ?><span class="block text-xs text-slate-500 dark:text-slate-400">In Notices only, no email</span><?php endif ?>
                            <?php endif ?>
                        </td>
                        <td><a class="btn btn-ghost btn-xs" href="<?= $row['url'] ?>">Open</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
