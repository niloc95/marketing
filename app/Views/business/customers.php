<?php
/**
 * @var string                                                                  $q
 * @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}      $result
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="flex flex-wrap items-center gap-3">
    <h1 class="text-xl font-bold text-slate-900 dark:text-white">Customers</h1>
    <a href="<?= base_url('business/customers/new') ?>" class="btn btn-primary btn-xs ml-auto"><?= lucide('plus', 'h-4 w-4') ?> Add a customer</a>
</div>
<form method="get" action="<?= base_url('business/customers') ?>" class="mt-4 flex items-start gap-2" role="search">
    <div class="field mb-0 flex-1">
        <label class="sr-only" for="cust-q">Find a customer</label>
        <input id="cust-q" type="text" name="q" value="<?= esc($q, 'attr') ?>" placeholder="Find a customer by name, email or phone">
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
    <?php if ($result['pages'] > 1): ?>
        <nav class="mt-4 flex items-center gap-3 text-sm" aria-label="Pages">
            <?php if ($result['page'] > 1): ?><a class="underline" href="<?= base_url('business/customers?' . http_build_query(['q' => $q, 'page' => $result['page'] - 1])) ?>">Previous</a><?php endif ?>
            <span>Page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
            <?php if ($result['page'] < $result['pages']): ?><a class="underline" href="<?= base_url('business/customers?' . http_build_query(['q' => $q, 'page' => $result['page'] + 1])) ?>">Next</a><?php endif ?>
        </nav>
    <?php endif ?>
<?php endif ?>
<?= $this->endSection() ?>
