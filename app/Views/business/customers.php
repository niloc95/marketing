<?php
/**
 * @var string                                                                  $q
 * @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}      $result
 */
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
    <div class="table-scroll mt-5">
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Added</th></tr></thead>
            <tbody>
                <?php foreach ($result['rows'] as $c): ?>
                    <tr>
                        <td><a class="underline font-medium" href="<?= base_url('business/customers/' . $c['hash']) ?>"><?= esc($c['name']) ?></a></td>
                        <td><?= esc((string) ($c['email'] ?? '')) ?></td>
                        <td class="whitespace-nowrap"><?= esc((string) ($c['phone'] ?? '')) ?></td>
                        <td class="whitespace-nowrap"><?= esc((new DateTimeImmutable((string) $c['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(service('businessContext')->timezone()))->format('j M Y')) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= view('directory/_pager', [
        'result'  => pager_result($result['page'], \App\Services\Bookings\CustomerService::PAGE_SIZE, $result['total']),
        'baseUrl' => base_url('business/customers'),
        'query'   => ['q' => $q],
        'noun'    => 'customers',
    ], ['saveData' => false]) ?>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Customers <span class="page-title-count">(<?= number_format((int) $result['total']) ?>)</span><?= $this->endSection() ?>

<?= $this->section('shell_actions') ?>
<a href="<?= base_url('business/customers/new') ?>" class="btn btn-primary btn-xs"><?= lucide('plus', 'h-4 w-4') ?> Add a customer</a>
<?= $this->endSection() ?>
