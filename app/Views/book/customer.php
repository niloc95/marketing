<?php
/**
 * A customer's bookings with one business (Book::customer), by their hash.
 *
 * @var array<string,mixed>                                                      $listing
 * @var array<string,mixed>                                                      $customer
 * @var array{upcoming:list<array<string,mixed>>,past:list<array<string,mixed>>} $list
 * @var DateTimeZone                                                             $timezone
 */
use App\Services\Bookings\AppointmentStatus;

$siteName = config('Directory')->siteName();
?>
<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Your bookings | ' . $siteName, 'description' => 'Your bookings with ' . $listing['display_name'] . '.', 'canonical' => base_url('book/find'), 'robots' => 'noindex, nofollow']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <p class="mb-2 text-sm"><a class="underline" href="<?= listing_url($listing) ?>"><?= esc($listing['display_name']) ?></a></p>
        <h1 class="mb-6 text-2xl font-extrabold text-slate-900 dark:text-white">Your bookings, <?= esc((string) $customer['first_name']) ?></h1>
        <?php foreach (['upcoming' => 'Coming up', 'past' => 'Earlier'] as $key => $heading): ?>
            <h2 class="mb-3 mt-6 font-bold"><?= $heading ?></h2>
            <?php if ($list[$key] === []): ?>
                <p class="text-sm text-slate-500"><?= $key === 'upcoming' ? 'Nothing booked.' : 'None.' ?></p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-700 dark:bg-slate-900">
                    <?php foreach ($list[$key] as $a): ?>
                        <li>
                            <a class="block px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800" href="<?= base_url('book/customer/' . $customer['hash'] . '/' . $a['hash']) ?>">
                                <span class="block font-semibold"><?= esc((string) $a['service_name']) ?> <span class="font-normal text-slate-500">with <?= esc((string) $a['provider_name']) ?></span></span>
                                <span class="block text-sm"><?= view('book/_when', ['a' => $a, 'timezone' => $timezone]) ?> · <?= esc(AppointmentStatus::short((string) $a['status'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        <?php endforeach ?>
        <p class="mt-8 text-sm"><a class="btn btn-accent" href="<?= base_url('directory/' . $listing['slug'] . '/book') ?>">Book again</a></p>
    </div>
</section>
<?= $this->endSection() ?>
