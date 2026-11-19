<?php
/**
 * What a bookable person adds to their team card (decision T28): a button that
 * opens the booking page with them.
 *
 * Only the button since 19 Nov 2026 (owner, on Dr Bhatta's profile): the
 * person's week (where, when, for what) and the list of what they offer made
 * the card long and repeated the Services panel; the booking page asks for
 * the service and shows the real free times. PublicBookingService still builds
 * `week` (weekLines()) for anything else that wants it.
 *
 * @var array $p one row from PublicBookingService::practitioners()
 */
$first = \App\Services\Bookings\PublicBookingService::shortName((string) $p['name']);
?>
<div class="team-booking" data-practitioner="<?= (int) $p['id'] ?>">
    <a class="btn-ghost team-book" href="<?= esc($p['book_url'], 'attr') ?>" data-stat="booking">
        <?= lucide('calendar', 'h-4 w-4 shrink-0') ?> Book with <?= esc($first) ?>
    </a>
</div>
