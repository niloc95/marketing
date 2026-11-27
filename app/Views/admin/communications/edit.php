<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => ($campaign === null ? 'New campaign' : esc($campaign['name'])) . ' | Admin']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Models\CampaignModel;
use App\Models\CampaignRecipientModel;
use App\Services\Communications\CampaignRenderer;
use App\Services\Communications\CommunicationsService;

/**
 * A campaign: the form (new, draft or paused), then its controls, numbers
 * and send log once it exists.
 *
 * Customer copy rules apply to what is typed here: no dashes, no hyphenated
 * words, practice profile, Verified or Plus. The service refuses dashes,
 * hyphenated words, links and unknown merge fields.
 *
 * @var array<string,mixed>|null      $campaign
 * @var array<string,mixed>           $values
 * @var array<string,string>          $errors
 * @var Config\Campaigns              $config
 * @var list<string>                  $groups
 * @var list<string>                  $provinces
 * @var list<string>                  $sources
 * @var string                        $adminTo
 * @var array<string,mixed>|null      $stats    CommunicationsService::stats()
 * @var list<array<string,mixed>>     $log
 * @var int|null                      $count    profiles the audience reaches now (new and draft)
 * @var list<array<string,mixed>>     $steps    the campaign's steps (CommunicationsService::stepsOf), empty when new
 */
$v       = static fn (string $f): string => (string) ($values[$f] ?? '');
$err     = static fn (string $f): string => isset($errors[$f]) ? '<div class="err">' . esc($errors[$f]) . '</div>' : '';
$kindKey = (string) ($campaign['kind'] ?? $values['kind'] ?? '');
$kind    = $config->kinds[$kindKey] ?? null;
$status  = (string) ($campaign['status'] ?? CampaignModel::STATUS_DRAFT);
$isNew   = $campaign === null;
$isSeq   = isset($kind['steps']);
$seqKey  = (string) ($kind['sequence'] ?? '');
$formSteps = is_array($values['steps'] ?? null) ? array_values($values['steps']) : [];
$isDraft = $status === CampaignModel::STATUS_DRAFT;
$canEdit = $isNew || in_array($status, [CampaignModel::STATUS_DRAFT, CampaignModel::STATUS_PAUSED], true);
$action  = base_url($isNew ? 'admin/communications/new' : 'admin/communications/' . $campaign['id']);
$num     = static fn (int $n): string => number_format($n);
$reasons = [
    'opted_out'     => 'Opted out',
    'claimed'       => 'Claimed before the send',
    'email_changed' => 'Email changed',
    'no_email'      => 'No email',
    'unpublished'   => 'No longer published',
    'left_audience' => 'Left the audience',
    'paused'        => 'Paused',
    'converted'     => 'Took Verified or Plus',
    'no_step'       => 'No step left',
];
?>

<div class="shell-page">
    <nav class="crumbs" aria-label="Breadcrumb">
        <a href="<?= base_url('admin/communications') ?>">Communications</a>
        <span class="crumbs-sep">/</span>
        <span><?= $isNew ? 'New campaign' : esc($campaign['name']) ?></span>
    </nav>

    <?php if (! $isNew): ?>
        <?php $id = (int) $campaign['id']; ?>
        <div class="form-card mb-6">
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                <?= esc($kind['label'] ?? $kindKey) ?> · Status: <strong><?= esc(ucfirst($status)) ?></strong>
                <?php if (! empty($campaign['paused_reason'])): ?> · <?= esc($campaign['paused_reason']) ?><?php endif; ?>
                · Up to <?= (int) $campaign['daily_limit'] ?> a day
                <?php if ($count !== null): ?> · Reaches <strong><?= $num($count) ?></strong> profiles now<?php endif; ?>
            </p>

            <div class="flex flex-wrap items-end gap-3">
                <?php if ($isSeq): ?>
                    <?php foreach ($steps as $st): ?>
                        <a class="btn btn-ghost" href="<?= base_url('admin/communications/' . $id . '/preview?step=' . (int) $st['step']) ?>">Preview email <?= (int) $st['step'] ?></a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <a class="btn btn-ghost" href="<?= base_url('admin/communications/' . $id . '/preview') ?>">Preview</a>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <form method="post" action="<?= base_url('admin/communications/' . $id . '/test') ?>" class="flex flex-wrap items-end gap-2">
                        <?= csrf_field() ?>
                        <div class="field mb-0">
                            <label for="t-to">Send a test to</label>
                            <input type="email" id="t-to" name="to" required value="<?= esc($adminTo, 'attr') ?>">
                        </div>
                        <div class="field mb-0">
                            <label for="t-listing">For profile id</label>
                            <input type="number" id="t-listing" name="listing" min="1" placeholder="First in audience">
                        </div>
                        <button type="submit" class="btn btn-ghost" data-submit-once>Send test</button>
                    </form>
                <?php endif; ?>

                <?php if ($isDraft): ?>
                    <?php if (empty($campaign['test_sent_at'])): ?>
                        <span class="text-sm text-slate-500 dark:text-slate-400">Send yourself a test to unlock Start.</span>
                    <?php else: ?>
                        <form method="post" action="<?= base_url('admin/communications/' . $id . '/start') ?>"
                              data-confirm="<?= esc($seqKey === CommunicationsService::SEQUENCE_VERIFIED
                                  ? 'Start the Verified sequence? Every imported profile claimed from now on joins it and gets its emails, up to ' . (int) $campaign['daily_limit'] . ' a day.'
                                  : 'Queue ' . $num((int) $count) . ' profiles and send up to ' . (int) $campaign['daily_limit'] . ' a day, starting with the next run? ' . ($isSeq ? 'Each gets these ' . count($steps) . ' emails once, spaced out, until they claim.' : 'Each gets this email once.'), 'attr') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-primary">Start sending</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php foreach ([
                    'pause'  => [$status === CampaignModel::STATUS_SENDING, 'Pause', null],
                    'resume' => [$status === CampaignModel::STATUS_PAUSED, 'Resume', null],
                    'cancel' => [in_array($status, [CampaignModel::STATUS_DRAFT, CampaignModel::STATUS_SENDING, CampaignModel::STATUS_PAUSED], true), 'Cancel campaign', 'Cancel for good? Profiles not yet sent will never get it.'],
                ] as $act => [$show, $label, $confirm]): ?>
                    <?php if ($show): ?>
                        <form method="post" action="<?= base_url('admin/communications/' . $id . '/status') ?>"<?= $confirm ? ' data-confirm="' . esc($confirm, 'attr') . '"' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $act ?>">
                            <button type="submit" class="btn btn-ghost"><?= $label ?></button>
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if (! empty($campaign['test_sent_at'])): ?>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Last test <?= esc(date('j M Y H:i', strtotime((string) $campaign['test_sent_at']))) ?>. Changing the subject or the email needs a new test.</p>
            <?php endif; ?>
        </div>

        <?php if ($stats !== null && ! empty($campaign['started_at'])): ?>
            <dl class="stats-tiles mb-6">
                <?php
                $tiles = $isSeq
                    ? ['Finished' => $stats['sent'], 'Sent today' => $stats['sentToday'], 'In the sequence' => $stats['queued'], 'Held (paused)' => $stats['held'], 'Failed' => $stats['failed'], 'Stopped' => $stats['skipped'], 'Opted out since' => $stats['optedOutSince']]
                    : ['Sent' => $stats['sent'], 'Sent today' => $stats['sentToday'], 'Queued' => $stats['queued'], 'Failed' => $stats['failed'], 'Skipped' => $stats['skipped'], 'Opted out since' => $stats['optedOutSince']];
                if ($isSeq) {
                    foreach ($steps as $st) {
                        $tiles['Email ' . $st['step'] . ' sent'] = $stats['perStep'][(int) $st['step']] ?? 0;
                    }
                    $tiles[$seqKey === CommunicationsService::SEQUENCE_CLAIM ? 'Claimed' : 'Took the plan'] = $stats['reasons'][$seqKey === CommunicationsService::SEQUENCE_CLAIM ? 'claimed' : 'converted'] ?? 0;
                }
                ?>
                <?php foreach ($tiles as $label => $n): ?>
                    <div class="stats-tile">
                        <dt class="stats-tile-label"><?= esc($label) ?></dt>
                        <dd class="stats-tile-value"><?= $num((int) $n) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php if ($stats['reasons'] !== [] || $stats['medianDaysToClaim'] !== null): ?>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    <?php if ($stats['medianDaysToClaim'] !== null): ?>Median <?= esc((string) $stats['medianDaysToClaim']) ?> days from the first email to the claim. <?php endif; ?>
                    <?= $isSeq ? 'Stopped' : 'Skipped' ?>: <?= esc(implode(', ', array_map(static fn (string $r, int $n): string => ($reasons[$r] ?? $r) . ' ' . $n, array_keys($stats['reasons']), $stats['reasons']))) ?>.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div class="form-card mb-6">
            <?php if ($kind !== null): ?>
                <p class="mb-5 text-sm text-slate-500 dark:text-slate-400"><?= esc($kind['summary']) ?></p>
            <?php endif; ?>
            <form method="post" action="<?= $action ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="kind" value="<?= esc($kindKey, 'attr') ?>">

                <div class="form-row">
                    <div class="field">
                        <label for="c-name">Name (admin only)</label>
                        <input type="text" id="c-name" name="name" required maxlength="120" value="<?= esc($v('name'), 'attr') ?>">
                        <?= $err('name') ?>
                    </div>
                    <div class="field">
                        <label for="c-limit">Emails a day</label>
                        <input type="number" id="c-limit" name="daily_limit" required min="1" max="<?= (int) $config->maxDailyLimit ?>" value="<?= esc($v('daily_limit'), 'attr') ?>">
                        <div class="hint">Spread over the working day, a few every 10 minutes.</div>
                        <?= $err('daily_limit') ?>
                    </div>
                </div>

                <?php if ($isSeq): ?>
                    <?php foreach ($kind['steps'] as $i => $tpl): ?>
                        <?php $st = $formSteps[$i] ?? ['subject' => $tpl['subject'], 'body' => implode("\n\n", $tpl['paragraphs']), 'delay_days' => $tpl['delay_days']]; $n = $i + 1; ?>
                        <fieldset class="mb-6 rounded-surface border border-slate-200 p-4 dark:border-slate-700">
                            <legend class="px-1 text-sm font-semibold text-slate-900 dark:text-white">Email <?= $n ?> · button: <?= esc($tpl['button'][0]) ?></legend>
                            <div class="field">
                                <label for="s<?= $n ?>-delay"><?= $n === 1 ? ($seqKey === CommunicationsService::SEQUENCE_VERIFIED ? 'Days after the claim' : 'Days after the start (0 = in the first batches)') : 'Days after email ' . ($n - 1) ?></label>
                                <input type="number" id="s<?= $n ?>-delay" name="steps[<?= $i ?>][delay_days]" required min="<?= $n === 1 ? 0 : 1 ?>" max="<?= (int) $config->maxDelayDays ?>" value="<?= esc((string) $st['delay_days'], 'attr') ?>">
                                <?= $err("steps.{$n}.delay_days") ?>
                            </div>
                            <div class="field">
                                <label for="s<?= $n ?>-subject">Subject</label>
                                <input type="text" id="s<?= $n ?>-subject" name="steps[<?= $i ?>][subject]" required maxlength="160" value="<?= esc((string) $st['subject'], 'attr') ?>">
                                <?= $err("steps.{$n}.subject") ?>
                            </div>
                            <div class="field mb-0">
                                <label for="s<?= $n ?>-body">Email</label>
                                <textarea id="s<?= $n ?>-body" name="steps[<?= $i ?>][body]" rows="10" required maxlength="<?= CommunicationsService::MAX_BODY ?>"><?= esc((string) $st['body']) ?></textarea>
                                <?= $err("steps.{$n}.body") ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                    <div class="hint mb-5">
                        Plain text, a blank line between paragraphs. Merge fields: <?= esc(implode(' ', CampaignRenderer::FIELDS)) ?>.
                        {greeting} is "Dear" and the name for a profile that is one person, else "Hello,". {plan} is Verified on co.za and Plus on .app.
                        Each email is checked again just before it goes: claimed, upgraded, opted out, paused or mailed in the last <?= (int) $config->recentContactDays ?> days.
                    </div>
                <?php else: ?>
                <div class="field">
                    <label for="c-subject">Subject</label>
                    <input type="text" id="c-subject" name="subject" required maxlength="160" value="<?= esc($v('subject'), 'attr') ?>">
                    <?= $err('subject') ?>
                </div>

                <div class="field">
                    <label for="c-body">Email</label>
                    <textarea id="c-body" name="body" rows="12" required maxlength="<?= CommunicationsService::MAX_BODY ?>"><?= esc($v('body')) ?></textarea>
                    <div class="hint">
                        Plain text, a blank line between paragraphs. Merge fields: <?= esc(implode(' ', CampaignRenderer::FIELDS)) ?>.
                        A paragraph with {appearances} is left out for a profile Google has not shown yet.
                        The button (<?= esc($kind['button'][0] ?? '') ?>), the opt out line and the company footer below are added for you.
                    </div>
                    <?= $err('body') ?>
                </div>
                <?php endif; ?>

                <div class="mb-5">
                    <?= $this->include('admin/communications/_footer_preview') ?>
                </div>

                <fieldset class="mb-5"<?= ($isNew || $isDraft) ? '' : ' disabled' ?>>
                    <legend class="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Audience<?= ($isNew || $isDraft) ? '' : ' (fixed once started)' ?></legend>
                    <div class="form-row">
                        <div class="field">
                            <label for="c-segment">Profiles</label>
                            <select id="c-segment" name="segment">
                                <?php foreach ($kind['segments'] ?? [] as $s): ?>
                                    <option value="<?= esc($s, 'attr') ?>" <?= $v('segment') === $s ? 'selected' : '' ?>><?= esc($config->segments[$s]) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $err('segment') ?>
                        </div>
                        <div class="field">
                            <label for="c-site">Site</label>
                            <select id="c-site" name="site">
                                <option value="">Both sites</option>
                                <option value="za" <?= $v('site') === 'za' ? 'selected' : '' ?>>co.za (South Africa)</option>
                                <option value="app" <?= $v('site') === 'app' ? 'selected' : '' ?>>.app (every other country)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <?php foreach (['group' => ['Main category', $groups], 'province' => ['Province or region', $provinces], 'source' => ['Signup source', $sources]] as $f => [$label, $options]): ?>
                            <div class="field">
                                <label for="c-<?= $f ?>"><?= esc($label) ?></label>
                                <select id="c-<?= $f ?>" name="<?= $f ?>">
                                    <option value="">Any</option>
                                    <?php foreach ($options as $o): ?>
                                        <option value="<?= esc($o, 'attr') ?>" <?= $v($f) === $o ? 'selected' : '' ?>><?= esc($o) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="hint">
                        Always left out: demo and unpublished profiles, profiles without an email, anyone who opted
                        out of emails about their profile, and a second profile on the same address.
                        <?php if ($isNew && $count !== null): ?> With the template's default audience this reaches <strong><?= $num($count) ?></strong> profiles; save to see the count for your filters.<?php endif; ?>
                    </div>
                </fieldset>

                <button type="submit" class="btn btn-primary"><?= $isNew ? 'Save draft' : 'Save' ?></button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($log !== []): ?>
        <h2 class="mb-2 text-base font-bold text-slate-900 dark:text-white">Latest in the send log</h2>
        <div class="tablewrap">
            <table class="table">
                <thead><tr><th>Profile</th><th>Status</th><?php if ($isSeq): ?><th>Emails sent</th><th>Next</th><?php endif; ?><th>Tries</th><th>Last sent</th><th>Profile emails</th></tr></thead>
                <tbody>
                <?php foreach ($log as $r): ?>
                    <tr>
                        <td><a href="<?= base_url('admin/edit/' . (int) $r['listing_id']) ?>"><?= esc((string) ($r['display_name'] ?? ('#' . $r['listing_id']))) ?></a></td>
                        <td>
                            <?= esc(ucfirst((string) $r['status'])) ?>
                            <?php if ($r['status'] === CampaignRecipientModel::STATUS_SKIPPED): ?>
                                <span class="text-xs text-slate-500 dark:text-slate-400"><?= esc($reasons[$r['reason']] ?? (string) $r['reason']) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($isSeq): ?>
                            <td><?= (int) $r['step'] ?> of <?= count($steps) ?></td>
                            <td class="whitespace-nowrap text-sm"><?= $r['status'] === CampaignRecipientModel::STATUS_QUEUED ? (! empty($r['next_send_at']) ? esc(date('j M', strtotime((string) $r['next_send_at']))) : 'Next batch') : '' ?></td>
                        <?php endif; ?>
                        <td><?= (int) $r['attempts'] ?></td>
                        <td class="whitespace-nowrap text-sm"><?= ! empty($r['sent_at']) ? esc(date('j M H:i', strtotime((string) $r['sent_at']))) : '' ?></td>
                        <td><?= view('admin/communications/_pause_button', ['listingId' => (int) $r['listing_id'], 'paused' => ! empty($r['comms_paused_at']), 'back' => 'admin/communications/' . $id], ['saveData' => false]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
