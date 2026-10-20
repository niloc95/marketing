<?php
/**
 * The listing form's step tracker: five numbered dots, one per section card
 * (listing_form_steps(), ids form-step-1..5), in the sticky bar that follows
 * the visitor down the form. directory.js ("form step tracker") marks the
 * step on screen as current and keeps the label beside the dots in step.
 * Each dot is a plain link to its section, so with the script off they still
 * jump there.
 */
$steps = listing_form_steps();
?>
<nav class="form-steps-nav" aria-label="Form sections" data-form-steps>
    <?php foreach ($steps as $i => $step): ?>
        <a class="form-steps-dot <?= esc($step['class'], 'attr') ?>" href="#form-step-<?= $i ?>"
           data-form-steps-dot="<?= $i ?>" title="<?= esc($step['title'], 'attr') ?>"
           aria-label="Step <?= $i ?>: <?= esc($step['title'], 'attr') ?>"><?= $i ?></a>
    <?php endforeach; ?>
    <span class="form-steps-label" data-form-steps-label>1 of <?= count($steps) ?> &middot; <?= esc($steps[1]['title']) ?></span>
</nav>
