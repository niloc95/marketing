<?php
/**
 * The notice on a demo profile (listing_is_demo()) and its booking page, so
 * nobody takes a made up business for a real one. Renders nothing otherwise.
 *
 * @var array<string,mixed> $listing
 * @var string              $where   profile|booking
 */
if (! listing_is_demo($listing ?? [])) {
    return;
}
$onBooking = ($where ?? '') === 'booking';
?>
<?php if (! $onBooking): ?><div class="container"><?php endif; ?>
    <div class="alert alert-info my-4" role="note" data-demo-banner>
        <strong>This is a demo business profile.</strong>
        <?= esc((string) ($listing['display_name'] ?? 'This business')) ?> is not a real business. It shows what a
        <?= bookings_in_premium() ? 'business profile and online booking look' : 'business profile looks' ?> like on <?= esc(config('Directory')->siteName()) ?>.
        <?php if ($onBooking): ?>
            Try the steps; nothing is booked and nobody is contacted.
        <?php endif; ?>
        <a class="underline" href="<?= base_url('add-profile') ?>">Add your business</a>
    </div>
<?php if (! $onBooking): ?></div><?php endif; ?>
