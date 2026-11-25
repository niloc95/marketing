<?php
/**
 * Customers as cards: name, how to reach them, and their next appointment
 * (or their last one and how many), with Book and Call. Book opens the top
 * bar's Book an appointment already set to the customer.
 *
 * @var string                                                                  $q
 * @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}      $result
 * @var array<int,array{next:?string,last:?string,total:int}>                   $summaries by customer id
 * @var string                                                                  $timezone
 */
$tz = new DateTimeZone($timezone);
$on = static fn (string $utc, string $format): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<form method="get" action="<?= base_url('business/customers') ?>" class="flex items-start gap-2" role="search">
    <div class="field mb-0 flex-1">
        <label class="sr-only" for="cust-q">Find a customer</label>
        <input id="cust-q" type="text" name="q" value="<?= esc($q, 'attr') ?>" placeholder="Find by name, email, phone or reference">
    </div>
    <button type="submit" class="btn btn-ghost">Find</button>
</form>

<?php if ($result['rows'] === []): ?>
    <p class="mt-6 text-sm text-slate-500 dark:text-slate-400"><?= $q === '' ? 'No customers yet. They appear here when you book them or they book online.' : 'Nobody matches that.' ?></p>
<?php else: ?>
    <ul class="cust-cards mt-5">
        <?php foreach ($result['rows'] as $c): ?>
            <?php
            $s        = $summaries[(int) $c['id']] ?? ['next' => null, 'last' => null, 'total' => 0];
            $phone    = (string) ($c['phone'] ?? '');
            $email    = (string) ($c['email'] ?? '');
            $initials = mb_strtoupper(mb_substr((string) $c['first_name'], 0, 1) . mb_substr((string) ($c['last_name'] ?? ''), 0, 1));
            $visits   = $s['total'] === 1 ? '1 appointment' : $s['total'] . ' appointments';
            ?>
            <li class="panel cust-card">
                <a class="cust-card-head" href="<?= base_url('business/customers/' . $c['hash']) ?>">
                    <span class="cust-avatar" aria-hidden="true"><?= esc($initials) ?></span>
                    <span class="min-w-0">
                        <span class="cust-card-name"><?= esc($c['name']) ?></span>
                        <span class="cust-card-contact"><?= esc(implode(' · ', array_filter([$phone, $email]))) ?></span>
                    </span>
                </a>
                <p class="cust-card-line">
                    <?php if ($s['next'] !== null): ?>
                        <?= lucide('calendar', 'h-4 w-4') ?> <span><span class="font-semibold">Next</span> <?= esc($on($s['next'], 'D j M, H:i')) ?></span>
                    <?php elseif ($s['last'] !== null): ?>
                        <?= lucide('history', 'h-4 w-4') ?> <span><span class="font-semibold">Last</span> <?= esc($on($s['last'], 'j M Y')) ?> · <?= $visits ?></span>
                    <?php else: ?>
                        <?= lucide('user-plus', 'h-4 w-4') ?> <span>Added <?= esc($on((string) $c['created_at'], 'j M Y')) ?></span>
                    <?php endif ?>
                </p>
                <div class="cust-card-actions">
                    <a class="btn btn-primary btn-xs" href="<?= base_url('business/appointments/new?customer=' . $c['hash']) ?>"><?= lucide('calendar-plus', 'h-4 w-4') ?> Book</a>
                    <?php if ($phone !== ''): ?>
                        <a class="btn btn-ghost btn-xs" href="tel:<?= esc(preg_replace('/[^\d+]/', '', $phone), 'attr') ?>"><?= lucide('phone', 'h-4 w-4') ?> Call</a>
                    <?php endif ?>
                </div>
            </li>
        <?php endforeach ?>
    </ul>
    <?= view('directory/_pager', [
        'result'  => pager_result($result['page'], \App\Services\Bookings\CustomerService::PAGE_SIZE, $result['total']),
        'baseUrl' => base_url('business/customers'),
        'query'   => ['q' => $q],
        'noun'    => 'customers',
    ], ['saveData' => false]) ?>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Customers <span class="page-title-count">(<?= number_format((int) $result['total']) ?>)</span><?= $this->endSection() ?>

<?php // Add a customer is in the top bar on every page (business/_topbar_tools.php). ?>
