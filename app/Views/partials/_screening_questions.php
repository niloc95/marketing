<?php
/**
 * The screening step on an apply form (App\Libraries\Screening).
 *
 * The questions are not in the page until the applicant presses Start:
 * directory.js asks $startUrl for them, which starts the clock on our server,
 * draws the 3 questions with their own numbers, and returns them without
 * the answers. Save my answers posts them to $answerUrl, which locks them
 * with our time; the token in the hidden input then goes with the
 * application. A reload, or a form sent back for a missing CV, gets the same
 * attempt back through $attempt, so the clock never restarts.
 *
 * Select, copy and the context menu are blocked on the questions and leaving
 * the page is counted. Both are speed bumps; the per applicant numbers are
 * what make a search useless.
 *
 * @var int                      $roleId
 * @var string                   $level
 * @var array<string,mixed>|null $attempt  Screening::view(), or null before Start
 * @var string                   $error
 * @var bool                     $off      rendered hidden and disabled (another role is picked)
 * @var string                   $who      who reads the answers: "the employer", or "us" on /careers
 * @var string                   $startUrl
 * @var string                   $answerUrl
 * @var App\Libraries\Screening  $screening
 */
$off     = $off ?? false;
$who     = $who ?? 'the employer';
$limit   = $screening->limitFor($level);
$minutes = (int) ceil($limit / 60);
?>
<fieldset class="field" data-screening data-screening-role="<?= (int) $roleId ?>"
          data-start-url="<?= esc($startUrl, 'attr') ?>" data-answer-url="<?= esc($answerUrl, 'attr') ?>" data-csrf-name="<?= esc(csrf_token(), 'attr') ?>"
          <?= $attempt !== null ? 'data-attempt="' . esc(json_encode($attempt, JSON_UNESCAPED_UNICODE), 'attr') . '"' : '' ?>
          <?= $off ? 'hidden disabled' : '' ?>>
    <legend class="mb-1 text-sm font-semibold">A few quick questions</legend>
    <p class="hint mb-3">
        Fill in your details first. Then start the <?= App\Libraries\Screening::COUNT ?> questions: you have about <?= $minutes ?> minutes,
        and leaving this page while the clock runs is noted. They help <?= esc($who) ?> see who fits the role.
        If you need more time because of a disability, say so in your message.
    </p>
    <noscript><p class="err">Please turn on JavaScript to answer the questions.</p></noscript>

    <input type="hidden" name="screening_token" value="<?= esc(($attempt['state'] ?? '') === 'answered' ? $attempt['token'] : '', 'attr') ?>" data-screening-token>

    <div data-screening-start <?= $attempt !== null ? 'hidden' : '' ?>>
        <button type="button" class="btn btn-primary" data-screening-go>Start the questions</button>
        <p class="hint mt-2" data-screening-wait hidden>Complete the fields marked * above to start the questions.</p>
    </div>

    <div class="screening-run" data-screening-run hidden>
        <p class="screening-clock" aria-live="polite" data-screening-clock></p>
        <div data-screening-items></div>
        <button type="button" class="btn btn-primary" data-screening-save>Save my answers</button>
    </div>

    <p class="hint mt-2" role="status" data-screening-status></p>
    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>
</fieldset>
