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
        <?php // Neutral on purpose (owner, 18 Nov 2026): a demo can be a real
              // practice's profile used to show the platform, so it never
              // says the business is not real. ?>
        <strong>This is a demo profile.</strong>
        It shows what a profile, online booking and vacancies look like on <?= esc(config('Directory')->siteName()) ?>.
        <?= $onBooking ? 'Try the steps; nothing' : 'Nothing' ?> here is booked or sent, and nobody is contacted.
        <a class="underline" href="<?= base_url('add-profile') ?>">Add your business</a>
    </div>
<?php if (! $onBooking): ?></div><?php endif; ?>
