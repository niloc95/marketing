<?php
/**
 * What a bookable person adds to their team card (decision T28): their usual
 * week, what they offer, and a button that opens the booking page with them.
 * Their hours are their own, already clamped to the profile's hours
 * (AvailabilityService::weekPeriods()), so a practitioner working 10:00 to
 * 15:00 inside reception's 08:00 to 17:00 shows 10:00 to 15:00.
 *
 * A day can hold several periods at different places, some for only some
 * services (T29): each period is its own line with where and what.
 *
 * @var array $p one row from PublicBookingService::practitioners()
 */
$first = \App\Services\Bookings\PublicBookingService::shortName((string) $p['name']);
?>
<div class="team-booking" data-practitioner="<?= (int) $p['id'] ?>">
    <?php if ($p['week'] !== []): ?>
        <dl class="team-hours">
            <?php foreach ($p['week'] as $line): ?>
                <div class="team-hours-row">
                    <dt><?= esc($line['days']) ?></dt>
                    <dd>
                        <?php foreach ($line['periods'] as $t): ?>
                            <span class="team-hours-period">
                                <?= esc($t['start']) ?>&ndash;<?= esc($t['end']) ?><?php if ($t['place'] !== ''): ?> <span class="team-hours-place">at <?= esc($t['place']) ?></span><?php endif; ?>
                                <?php if ($t['services'] !== []): ?><span class="team-hours-place">for <?= esc(implode(', ', $t['services'])) ?></span><?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
    <div class="taglist" aria-label="Offers">
        <?php foreach ($p['services'] as $s): ?><span class="tag"><?= esc($s['name']) ?></span><?php endforeach; ?>
    </div>
    <a class="btn-ghost team-book" href="<?= esc($p['book_url'], 'attr') ?>" data-stat="booking">
        <?= lucide('calendar', 'h-4 w-4 shrink-0') ?> Book with <?= esc($first) ?>
    </a>
</div>
