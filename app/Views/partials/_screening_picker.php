<?php
/**
 * Choose a role's screening level (App\Libraries\Screening). Each applicant
 * then gets 3 questions drawn from that level's templates, each with its own
 * numbers, so there is nothing to pick question by question.
 *
 * "See examples" draws one of every template fresh on each page load, with
 * its answer, so whoever posts the role knows what applicants will face.
 *
 * @var App\Libraries\Screening  $screening
 * @var string                   $level  the chosen level, '' for none
 * @var string                   $error
 */
$bank = $screening->bank();
?>
<div class="field" data-screening-picker>
    <label for="s-level">Screening questions <span class="font-normal text-slate-400">(optional)</span></label>
    <select id="s-level" name="screening_level" data-screening-level>
        <option value="">No screening questions</option>
        <?php foreach ($screening->levels() as $key => $label): ?>
            <option value="<?= esc($key, 'attr') ?>" <?= $level === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="hint">
        Each applicant answers one reading comprehension, one language and one everyday numbers question, drawn at random
        from this level with their own names and numbers, so answers cannot be shared or searched. The answers are marked for you, with the time taken.
        Nobody is turned away on a low score.
    </div>
    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

    <?php foreach ($screening->levels() as $key => $label): ?>
        <details class="disclosure mt-2" data-screening-set="<?= esc($key, 'attr') ?>">
            <summary class="disclosure-summary">See examples: <?= esc($label) ?>, <?= esc($screening->clock($screening->limitFor($key))) ?> for all <?= App\Libraries\Screening::COUNT ?></summary>
            <ol class="disclosure-body text-sm">
                <?php foreach ($bank->ids($key) as $id): ?>
                    <?php $q = $bank->generate($id); ?>
                    <li class="mb-2">
                        <?php if ($q['passage'] !== null): ?><div class="text-xs text-slate-500 dark:text-slate-400"><?= esc($q['passage']) ?></div><?php endif; ?>
                        <?= esc($q['prompt']) ?>
                        <div class="text-xs text-slate-500 dark:text-slate-400">Answer: <?= esc($q['options'][$q['answer']]) ?></div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </details>
    <?php endforeach; ?>
</div>
