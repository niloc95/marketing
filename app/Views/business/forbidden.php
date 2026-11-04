<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-md panel">
    <h1 class="mb-2 text-xl font-bold text-slate-900 dark:text-white">Not available to you</h1>
    <p class="text-sm text-slate-600 dark:text-slate-300">Your role in this business does not include this page. Ask an admin of the business if you need it.</p>
    <a class="btn btn-ghost mt-4" href="<?= base_url('business') ?>">Back</a>
</div>
<?= $this->endSection() ?>
