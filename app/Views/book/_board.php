<?php
/**
 * Free times, one column per place, a week at a time (FreeTimesBoard::week(),
 * decision T33). The same board on the public booking page and in every
 * /business Book form:
 *
 *   link mode (public, no script needed): each time and Earlier/Later is a link;
 *   button mode (/business, free-times.js): buttons carrying data-board-*.
 *
 * The column says where; a time shows only the time. Each day is a details
 * row (the first open), so a short step does not make a wall of times.
 *
 * @var array<string,mixed>                          $board    FreeTimesBoard::week()
 * @var string                                       $mode     'link' or 'button'
 * @var (callable(string,string,int):string)|null     $timeUrl  link mode: (date, start UTC, place id) => href
 * @var (callable(string):string)|null                $weekUrl  link mode: from date => href
 * @var (callable(string):string)|null                $clock    HH:MM => as the business shows times
 * @var string|null                                   $selected the chosen time's start (UTC), marked
 */
$clock    ??= static fn (string $t): string => $t;
$selected ??= null;
$timeUrl  ??= null;
$weekUrl  ??= null;
$isLink   = ($mode ?? 'link') === 'link';
$fmt      = static fn (string $d, string $f): string => (new DateTimeImmutable($d))->format($f);
// With the year: the year of the end only, unless the week crosses into a new one.
$range    = $board['from'] === $board['to']
    ? $fmt($board['from'], 'D j M Y')
    : $fmt($board['from'], substr($board['from'], 0, 4) === substr($board['to'], 0, 4) ? 'D j M' : 'D j M Y') . ' to ' . $fmt($board['to'], 'D j M Y');
$columns  = $board['places'];
$cols     = count($columns);
$step     = static function (?string $to, string $label, string $icon) use ($isLink, $weekUrl): string {
    if ($to === null) {
        return '<span class="btn btn-ghost btn-xs board-step is-off" aria-disabled="true">' . ($icon === 'chevron-left' ? lucide($icon, 'h-4 w-4') . $label : $label . lucide($icon, 'h-4 w-4')) . '</span>';
    }
    $inner = $icon === 'chevron-left' ? lucide($icon, 'h-4 w-4') . $label : $label . lucide($icon, 'h-4 w-4');

    return $isLink
        ? '<a class="btn btn-ghost btn-xs board-step" href="' . esc($weekUrl($to), 'attr') . '">' . $inner . '</a>'
        : '<button type="button" class="btn btn-ghost btn-xs board-step" data-board-from="' . esc($to, 'attr') . '">' . $inner . '</button>';
};
?>
<div class="board" data-board data-from="<?= esc($board['from'], 'attr') ?>" data-to="<?= esc($board['to'], 'attr') ?>">
    <div class="board-head">
        <?= $step($board['prev'], 'Earlier', 'chevron-left') ?>
        <p class="board-range"><?= esc($range) ?></p>
        <?= $step($board['next'], 'Later', 'chevron-right') ?>
    </div>
    <?php if ($columns === []): ?>
        <p class="board-empty">Nobody offers this here yet.</p>
    <?php else: ?>
        <div class="board-cols<?= $cols >= 3 ? ' board-cols-3' : ($cols === 2 ? ' board-cols-2' : '') ?>">
            <?php foreach ($columns as $c): ?>
                <section class="board-col" aria-label="<?= esc($c['name'], 'attr') ?>">
                    <header class="board-place">
                        <?= lucide('map-pin', 'h-4 w-4 shrink-0') ?>
                        <span class="min-w-0">
                            <strong class="board-place-name"><?= esc($c['name']) ?></strong>
                            <?php if ($c['address'] !== ''): ?><span class="board-place-address"><?= esc($c['address']) ?></span><?php endif ?>
                        </span>
                    </header>
                    <?php if ($c['days'] === []): ?>
                        <p class="board-empty">Nothing free here these days.</p>
                    <?php endif ?>
                    <?php foreach ($c['days'] as $i => $d): ?>
                        <?php $n = count($d['times']); $open = $i === 0 || in_array($selected, array_column($d['times'], 'start'), true); ?>
                        <details class="board-day"<?= $open ? ' open' : '' ?>>
                            <summary>
                                <span class="board-day-name"><?= esc($d['label']) ?></span>
                                <span class="board-day-info"><?= $n ?> <?= $n === 1 ? 'time' : 'times' ?>, from <?= esc($clock($d['times'][0]['time'])) ?></span>
                            </summary>
                            <div class="board-times">
                                <?php foreach ($d['times'] as $t): ?>
                                    <?php $on = $selected !== null && $t['start'] === $selected; ?>
                                    <?php if ($isLink): ?>
                                        <a class="board-time<?= $on ? ' is-on' : '' ?>" href="<?= esc($timeUrl($d['date'], $t['start'], (int) $c['id']), 'attr') ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= esc($clock($t['time'])) ?></a>
                                    <?php else: ?>
                                        <button type="button" class="board-time" data-board-time data-date="<?= esc($d['date'], 'attr') ?>" data-time="<?= esc($t['time'], 'attr') ?>" data-place="<?= (int) $c['id'] ?>" data-place-name="<?= esc($c['name'], 'attr') ?>" data-label="<?= esc($d['label'] . ', ' . $clock($t['time']), 'attr') ?>"><?= esc($clock($t['time'])) ?></button>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </div>
                        </details>
                    <?php endforeach ?>
                </section>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>
