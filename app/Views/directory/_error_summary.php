<?php
/**
 * Every validation message from a rejected save, listed right above the form.
 *
 * The flash at the top of the page only ever says "Please correct the
 * highlighted fields", and the inline messages sit down a long form, some in
 * sections that start folded — so owners landed on the alert with no idea
 * what was missing. Listed from $errors itself, not from the page, so a key
 * with no inline slot still gets said. directory.js turns each item into a
 * link to its field and scrolls to the first one.
 *
 * @var array $errors field => message (nested arrays allowed)
 */
$messages = [];
array_walk_recursive($errors, static function ($m) use (&$messages): void {
    if (is_string($m) && trim($m) !== '') $messages[] = trim($m);
});
$messages = array_values(array_unique($messages));
if ($messages === []) return;
?>
<div class="alert alert-error mb-6" role="alert" tabindex="-1" data-error-summary>
    <strong><?= count($messages) === 1 ? 'One thing needs fixing before we can save:' : count($messages) . ' things need fixing before we can save:' ?></strong>
    <ul class="alert-list">
        <?php foreach ($messages as $m): ?>
            <li><?= esc($m) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
