<?php
/**
 * The appointment summary: what is being booked, in one place. The same
 * card on the public booking page (filled here, step by step, no script)
 * and in every /business Book form (empty rows that free-times.js fills as
 * the fields change, keyed by data-sum).
 *
 * @var list<array{key:string,label:string,text?:string,missing?:bool}> $rows
 * @var string $attrs  extra attributes for the card (already escaped)
 * @var bool   $hidden hidden until a script shows it
 */
$attrs  ??= '';
$hidden ??= false;
?>
<section class="book-summary" <?= $attrs ?><?= $hidden ? ' hidden' : '' ?>>
    <h3 class="book-summary-title">Appointment summary</h3>
    <dl class="book-summary-list">
        <?php foreach ($rows as $r): ?>
            <div><dt><?= esc($r['label']) ?></dt><dd data-sum="<?= esc($r['key'], 'attr') ?>"<?= ! empty($r['missing']) ? ' class="is-missing"' : '' ?>><?= esc($r['text'] ?? '') ?></dd></div>
        <?php endforeach ?>
    </dl>
</section>
