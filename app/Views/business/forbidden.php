<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="mx-auto max-w-md panel">
    <p class="text-sm text-slate-600 dark:text-slate-300">Your role in this business does not include this page. Ask an admin of the business if you need it.</p>
    <a class="btn btn-ghost mt-4" href="<?= base_url('business') ?>">Back</a>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?>Not available to you<?= $this->endSection() ?>
