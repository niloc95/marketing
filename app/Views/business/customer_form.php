<?php
/**
 * @var null                      $customer
 * @var array<string,mixed>       $old
 * @var array<string,string>      $errors
 * @var list<array<string,mixed>> $customFields
 */
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('bookings/customers') ?>">Customers</a></p>
<form method="post" action="<?= base_url('bookings/customers/new') ?>" class="form-card form-card-narrow">
    <?= csrf_field() ?>
    <h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Add a customer</h1>
    <?= view('business/_customer_fields', ['old' => $old, 'errors' => $errors, 'customFields' => $customFields]) ?>
    <button type="submit" class="btn btn-primary">Add customer</button>
</form>
<?= $this->endSection() ?>
