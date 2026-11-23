<?php
/**
 * The same tools at the right of the /business top bar on every page (owner,
 * 23 Nov 2026): Find (customers and appointments), Your booking page, Book an
 * appointment, Add a customer. Each only for a member allowed to use it, and
 * only while the profile takes bookings. A page's own buttons (shell_actions)
 * stay beside its title.
 *
 * Below lg the buttons show their icon only and Find folds to an icon that
 * opens the field over the top bar (find.js). Without the script Find is a
 * plain form to the Customers list.
 *
 * @var bool   $canFind
 * @var bool   $canBook
 * @var bool   $canAddCustomer
 * @var string $bookUrl  the public booking page, '' when it takes no bookings
 * @var string $bookHref the Book button's target (a customer page sends its customer)
 */
?>
<div class="shell-tools">
    <?php if ($canFind): ?>
        <form class="shell-find" method="get" action="<?= base_url('business/customers') ?>" role="search" data-shell-find data-find-url="<?= esc(base_url('business/api/find'), 'attr') ?>">
            <label class="sr-only" for="shell-find-q">Find a customer or appointment</label>
            <span class="shell-find-icon" aria-hidden="true"><?= lucide('search', 'h-4 w-4') ?></span>
            <input id="shell-find-q" type="text" name="q" placeholder="Find a customer or appointment" autocomplete="off" data-find-input>
            <button type="button" class="shell-icon-btn shell-find-close" data-find-close aria-label="Close"><?= lucide('x', 'h-4 w-4') ?></button>
            <div class="shell-find-results" data-find-results hidden></div>
        </form>
        <button type="button" class="shell-icon-btn shell-find-open" data-find-open aria-label="Find a customer or appointment"><?= lucide('search', 'h-5 w-5') ?></button>
    <?php endif ?>
    <?php if ($bookUrl !== ''): ?>
        <a class="btn btn-ghost btn-xs shell-tool" href="<?= esc($bookUrl, 'attr') ?>" target="_blank" rel="noopener" title="Your booking page"><?= lucide('external-link', 'h-4 w-4') ?><span class="shell-tool-label">Your booking page</span></a>
    <?php endif ?>
    <?php if ($canAddCustomer): ?>
        <a class="btn btn-ghost btn-xs shell-tool" href="<?= base_url('business/customers/new') ?>" title="Add a customer"><?= lucide('user-plus', 'h-4 w-4') ?><span class="shell-tool-label">Add a customer</span></a>
    <?php endif ?>
    <?php if ($canBook): ?>
        <?php // data-shell-book: the calendar and availability pages open their Book dialog in place instead. ?>
        <a class="btn btn-primary btn-xs shell-tool" href="<?= esc($bookHref, 'attr') ?>" title="Book an appointment" data-shell-book><?= lucide('calendar-plus', 'h-4 w-4') ?><span class="shell-tool-label">Book an appointment</span></a>
    <?php endif ?>
</div>
