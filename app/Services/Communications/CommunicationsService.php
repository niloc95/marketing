<?php

namespace App\Services\Communications;

use App\Libraries\Mailer;
use App\Models\CampaignMessageModel;
use App\Models\CampaignModel;
use App\Models\CampaignRecipientModel;
use App\Models\CampaignStepModel;
use App\Models\DirectoryListingModel;

/**
 * Communications: emails the admin sends to a chosen group of profiles from
 * /admin/communications (owner, 21 Nov 2026). The only writer of
 * xs_campaigns and xs_campaign_recipients.
 *
 * The life of a campaign:
 * 1. Draft: pick a template (Config\Campaigns), edit the subject and
 *    paragraphs, pick the audience and the daily limit.
 * 2. Test: send it to an admin address, rendered for a real profile. A
 *    campaign cannot start before a test has gone out.
 * 3. Start: the audience is snapshotted into xs_campaign_recipients (queued),
 *    one row per profile. The unique (campaign, profile) key is what stops
 *    anyone getting the same campaign twice.
 * 4. Send: `comms:send` (cron, every 10 minutes in working hours) sends a
 *    share of the day's limit per run. Each recipient is checked again first
 *    (opted out, claimed, address changed, unpublished: skipped with the
 *    reason). Sent is stamped only when Mailer says it went. After
 *    Config\Campaigns::$pauseAfterFailures failures in a row the campaign
 *    pauses itself, so a mail outage does not burn the list.
 * 5. Done when nothing is queued. Pause, resume and cancel at any time.
 *
 * Sequences (owner, 27 Nov 2026; Config\Campaigns kinds with `steps`): a
 * recipient row stays queued between steps, `step` counting what went and
 * `next_send_at` the profile's own next date, so follow ups go first and
 * every profile keeps its own clock. The claim sequence snapshots like a one
 * off. The Verified sequence starts empty and, every run, takes in imported
 * profiles claimed since its start (first step `delay_days` after the claim),
 * closing their claim rows: one sequence at a time. Every send is checked
 * again first; a paused profile (comms_paused_at) or an address mailed in
 * the last Config\Campaigns::$recentContactDays is held, not dropped. A row
 * is never queued twice for a campaign, so nothing restarts.
 *
 * Every email that goes is a row in xs_campaign_messages (the daily limit,
 * the gap between emails and a claim link's hash).
 */
class CommunicationsService
{
    public const MAX_BODY = 6000;

    public const SEQUENCE_CLAIM    = 'claim';
    public const SEQUENCE_VERIFIED = 'verified';

    /** Sends in a run: the day's limit spread over this many runs (10 minutes, 08:00 to 17:00). */
    public const RUNS_PER_DAY = 54;

    private CampaignModel $campaigns;
    private CampaignRecipientModel $recipients;
    private CampaignStepModel $steps;
    private CampaignMessageModel $messages;
    private CampaignAudience $audience;
    private CampaignRenderer $renderer;

    public function __construct(?CampaignAudience $audience = null, ?CampaignRenderer $renderer = null)
    {
        $this->campaigns  = new CampaignModel();
        $this->recipients = new CampaignRecipientModel();
        $this->steps      = new CampaignStepModel();
        $this->messages   = new CampaignMessageModel();
        $this->audience   = $audience ?? new CampaignAudience();
        $this->renderer   = $renderer ?? new CampaignRenderer();
    }

    public function audience(): CampaignAudience
    {
        return $this->audience;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->campaigns->find($id);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> the stored audience, normalised */
    public static function audienceOf(array $campaign): array
    {
        $decoded = json_decode((string) ($campaign['audience'] ?? ''), true);

        return CampaignAudience::normalise(is_array($decoded) ? $decoded : []);
    }

    /**
     * Form values for a new campaign of $kind: the template's wording.
     *
     * @return array<string,mixed>|null null for an unknown kind
     */
    public function defaults(string $kind): ?array
    {
        $template = config('Campaigns')->kinds[$kind] ?? null;
        if ($template === null) {
            return null;
        }

        $steps = array_map(static fn (array $st): array => [
            'subject'    => $st['subject'],
            'body'       => implode("\n\n", $st['paragraphs']),
            'delay_days' => (int) $st['delay_days'],
        ], $template['steps'] ?? []);

        return [
            'kind'        => $kind,
            'name'        => $template['label'] . ' ' . date('j M Y'),
            'subject'     => $steps[0]['subject'] ?? $template['subject'],
            'body'        => $steps[0]['body'] ?? implode("\n\n", $template['paragraphs']),
            'steps'       => $steps,
            'daily_limit' => 150,
            'segment'     => $template['segments'][0],
            'site'        => '',
            'group'       => '',
            'province'    => '',
            'source'      => '',
        ];
    }

    /**
     * Save a draft (new when $id is null). Wording may also change while
     * paused; the audience only while a draft, since it is snapshotted at start.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,id?:int,errors:array<string,string>}
     */
    public function save(?int $id, array $input): array
    {
        $existing = $id === null ? null : $this->find($id);
        if ($id !== null && ($existing === null || ! in_array($existing['status'], [CampaignModel::STATUS_DRAFT, CampaignModel::STATUS_PAUSED], true))) {
            return ['ok' => false, 'errors' => ['form' => 'Only a draft or a paused campaign can be edited.']];
        }

        $kind   = $existing['kind'] ?? (string) ($input['kind'] ?? '');
        $steps  = self::stepInput($kind, $input);
        $values = [
            'name'        => trim((string) ($input['name'] ?? '')),
            'subject'     => $steps === null ? trim((string) ($input['subject'] ?? '')) : $steps[0]['subject'],
            'body'        => $steps === null ? trim(str_replace("\r\n", "\n", (string) ($input['body'] ?? ''))) : $steps[0]['body'],
            'daily_limit' => (int) ($input['daily_limit'] ?? 0),
        ];
        $audience = $existing !== null && $existing['status'] !== CampaignModel::STATUS_DRAFT
            ? self::audienceOf($existing)
            : CampaignAudience::normalise($input);

        $errors = $this->validate($kind, $values, $audience, $steps);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row = $values + ['audience' => json_encode($audience)];
        if ($existing === null) {
            $id = (int) $this->campaigns->insert($row + [
                'kind'     => $kind,
                'sequence' => config('Campaigns')->kinds[$kind]['sequence'] ?? null,
                'status'   => CampaignModel::STATUS_DRAFT,
            ]);
            $changed = true;
        } else {
            $changed = $existing['subject'] !== $values['subject'] || $existing['body'] !== $values['body'];
        }
        if ($steps !== null) {
            $changed = $this->saveSteps((int) $id, $steps) || $changed;
        }
        if ($existing !== null) {
            // A changed email needs a fresh test before it can start.
            $this->campaigns->update($id, $row + ($changed ? ['test_sent_at' => null] : []));
        }

        return ['ok' => true, 'id' => (int) $id, 'errors' => []];
    }

    /**
     * Render the campaign for one profile without sending: the admin preview.
     * $listingId null takes the first profile in the audience.
     *
     * @return array{subject:string,html:string,unsubscribe:string,listing:array<string,mixed>}|null
     */
    public function preview(array $campaign, ?int $listingId = null, int $step = 1): ?array
    {
        $listing = $this->sampleListing($campaign, $listingId);
        $steps   = $this->stepsOf($campaign);
        $current = $steps[max(1, $step) - 1] ?? $steps[0];
        if ($listing === null) {
            return null;
        }

        return $this->renderer->render(self::asStep($campaign, $current), $listing, false) + ['listing' => $listing, 'step' => $current['step'], 'steps' => count($steps)];
    }

    /**
     * The steps of a campaign, in order: the stored steps of a sequence, or
     * the one off campaign as its only step. Each with its button.
     *
     * @return list<array{step:int,subject:string,body:string,delay_days:int,button:array{0:string,1:string},extras:list<array{0:string,1:string}>}>
     */
    public function stepsOf(array $campaign): array
    {
        $template = config('Campaigns')->kinds[(string) $campaign['kind']] ?? [];
        if (empty($campaign['sequence'])) {
            return [[
                'step' => 1, 'subject' => (string) $campaign['subject'], 'body' => (string) $campaign['body'],
                'delay_days' => 0, 'button' => $template['button'] ?? ['Open my profile', 'business'], 'extras' => [],
            ]];
        }

        $out = [];
        foreach ($this->steps->where('campaign_id', (int) $campaign['id'])->orderBy('step')->findAll() as $row) {
            $n     = (int) $row['step'];
            $out[] = [
                'step' => $n, 'subject' => (string) $row['subject'], 'body' => (string) $row['body'],
                'delay_days' => (int) $row['delay_days'],
                'button' => $template['steps'][$n - 1]['button'] ?? ['Open my profile', 'business'],
                'extras' => $template['steps'][$n - 1]['extras'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * Send the campaign to an admin address, rendered for a sample profile.
     * Never logged as a recipient, and the profile's own sign in link is not
     * replaced. Unlocks Start.
     *
     * @return array{ok:bool,message:string}
     */
    public function sendTest(int $id, string $to, ?int $listingId = null): array
    {
        $campaign = $this->find($id);
        $to       = trim($to);
        if ($campaign === null) {
            return ['ok' => false, 'message' => 'Campaign not found.'];
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Enter an email address for the test.'];
        }

        // A sequence's test sends every step, in order, so each is read once.
        $count = count($this->stepsOf($campaign));
        $name  = '';
        for ($n = 1; $n <= $count; $n++) {
            $email = $this->preview($campaign, $listingId, $n);
            if ($email === null) {
                return ['ok' => false, 'message' => 'No profile to render the test for: the audience is empty and no profile id was given.'];
            }
            $tag = $count > 1 ? '[Test ' . $n . ' of ' . $count . '] ' : '[Test] ';
            if (! (new Mailer())->send($to, $tag . $email['subject'], $email['html'])) {
                return ['ok' => false, 'message' => 'The test did not go out. Check Status and health for the mail error.'];
            }
            $name = (string) $email['listing']['display_name'];
        }

        $this->campaigns->update($id, ['test_sent_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'message' => ($count > 1 ? $count . ' test emails' : 'Test') . ' sent to ' . $to . ', rendered for ' . $name . '.'];
    }

    /**
     * Start a tested draft: snapshot the audience into the send log.
     *
     * @return array{ok:bool,message:string,queued?:int}
     */
    public function start(int $id): array
    {
        $campaign = $this->find($id);
        if ($campaign === null || $campaign['status'] !== CampaignModel::STATUS_DRAFT) {
            return ['ok' => false, 'message' => 'Only a draft can be started.'];
        }
        if (empty($campaign['test_sent_at'])) {
            return ['ok' => false, 'message' => 'Send yourself a test first.'];
        }

        $now = date('Y-m-d H:i:s');

        // The Verified sequence starts empty: profiles join when they claim.
        if (($campaign['sequence'] ?? null) === self::SEQUENCE_VERIFIED) {
            $this->campaigns->update($id, ['status' => CampaignModel::STATUS_SENDING, 'started_at' => $now, 'paused_reason' => null]);

            return ['ok' => true, 'message' => 'Started. Every imported profile claimed from now on joins this sequence.', 'queued' => 0];
        }

        $rows = $this->audience->rows(self::audienceOf($campaign), $id);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nobody is in this audience.'];
        }

        $db  = db_connect();
        // resetTransStatus(): the shared connection keeps a failed flag from
        // any earlier refused query, which would fail this start for nothing.
        $db->resetTransStatus()->transStart();
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('campaign_recipients')->ignore(true)->insertBatch(array_map(static fn (array $l): array => [
                'campaign_id' => $id,
                'listing_id'  => (int) $l['id'],
                'email'       => CampaignAudience::normaliseEmail((string) $l['email']),
                'status'      => CampaignRecipientModel::STATUS_QUEUED,
                'attempts'    => 0,
                'step'        => 0,
                'entered_at'  => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ], $chunk));
        }
        $this->campaigns->update($id, ['status' => CampaignModel::STATUS_SENDING, 'started_at' => $now, 'paused_reason' => null]);
        $db->transComplete();

        if (! $db->transStatus()) {
            return ['ok' => false, 'message' => 'Could not start the campaign. Nothing was queued.'];
        }

        return ['ok' => true, 'message' => count($rows) . ' profiles queued.', 'queued' => count($rows)];
    }

    public function pause(int $id, ?string $reason = null): bool
    {
        return $this->move($id, [CampaignModel::STATUS_SENDING], ['status' => CampaignModel::STATUS_PAUSED, 'paused_reason' => $reason]);
    }

    public function resume(int $id): bool
    {
        return $this->move($id, [CampaignModel::STATUS_PAUSED], ['status' => CampaignModel::STATUS_SENDING, 'paused_reason' => null]);
    }

    /** Stop for good. Queued rows stay as they are, so the log shows who was never sent. */
    public function cancel(int $id): bool
    {
        return $this->move($id, [CampaignModel::STATUS_DRAFT, CampaignModel::STATUS_SENDING, CampaignModel::STATUS_PAUSED], [
            'status' => CampaignModel::STATUS_CANCELLED, 'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * One cron run: send each sending campaign's share of today's limit.
     * $perRun overrides the share (the command's --limit). $dryRun counts what
     * would go and writes nothing.
     *
     * @return array{sent:int,failed:int,skipped:int,paused:int,done:int,would:int}
     */
    public function sendDue(?int $perRun = null, bool $dryRun = false): array
    {
        $config = config('Campaigns');
        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'held' => 0, 'joined' => 0, 'paused' => 0, 'done' => 0, 'would' => 0];

        foreach ($this->campaigns->where('status', CampaignModel::STATUS_SENDING)->orderBy('id')->findAll() as $campaign) {
            $id        = (int) $campaign['id'];
            $isVerified = ($campaign['sequence'] ?? null) === self::SEQUENCE_VERIFIED;
            if ($isVerified && ! $dryRun) {
                $totals['joined'] += $this->enrolClaimed($campaign);
            }

            $limit     = (int) $campaign['daily_limit'];
            $allowance = max(0, $limit - $this->sentToday($id));
            $share     = $perRun ?? max(1, (int) ceil($limit / self::RUNS_PER_DAY));
            $take      = min($allowance, $share);

            if ($take > 0) {
                $queued = $this->due($id, $take);

                if ($dryRun) {
                    $totals['would'] += count($queued);

                    continue;
                }

                $audience = self::audienceOf($campaign);
                $steps    = $this->stepsOf($campaign);
                $streak   = 0;
                foreach ($queued as $r) {
                    $outcome = $this->sendOne($campaign, $r, $audience, $steps);
                    $totals[$outcome]++;
                    $streak = $outcome === 'failed' ? $streak + 1 : 0;
                    if ($streak >= $config->pauseAfterFailures) {
                        $this->pause($id, $streak . ' sends failed in a row at ' . date('Y-m-d H:i') . '. Check Status and health, then resume.');
                        $totals['paused']++;

                        continue 2;
                    }
                }
            }

            // The Verified sequence never runs out: it waits for the next claim.
            if (! $dryRun && ! $isVerified && $this->recipients->where('campaign_id', $id)->where('status', CampaignRecipientModel::STATUS_QUEUED)->countAllResults() === 0) {
                $this->campaigns->update($id, ['status' => CampaignModel::STATUS_DONE, 'finished_at' => date('Y-m-d H:i:s')]);
                $totals['done']++;
            }
        }

        return $totals;
    }

    /**
     * The numbers for the campaign page.
     *
     * @return array{queued:int,sent:int,failed:int,skipped:int,reasons:array<string,int>,optedOutSince:int,sentToday:int}
     */
    public function stats(int $id): array
    {
        $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reasons' => [], 'optedOutSince' => 0, 'sentToday' => $this->sentToday($id),
            'perStep' => [], 'held' => 0, 'medianDaysToClaim' => null];

        $db = db_connect();
        foreach ($db->table('campaign_recipients')->select('status, reason, COUNT(*) AS n')
            ->where('campaign_id', $id)->groupBy(['status', 'reason'])->get()->getResultArray() as $row) {
            $out[$row['status']] = ($out[$row['status']] ?? 0) + (int) $row['n'];
            if ($row['status'] === CampaignRecipientModel::STATUS_SKIPPED) {
                $out['reasons'][(string) $row['reason']] = (int) $row['n'];
            }
        }

        $campaign = $this->find($id);
        if (! empty($campaign['started_at'])) {
            $out['optedOutSince'] = $db->table('campaign_recipients r')
                ->join('directory_listings l', 'l.id = r.listing_id')
                ->where('r.campaign_id', $id)
                ->where('l.comms_opt_out_at >=', $campaign['started_at'])
                ->where('EXISTS (SELECT 1 FROM ' . $db->prefixTable('campaign_messages') . ' cm WHERE cm.recipient_id = r.id)', null, false)
                ->countAllResults();
        }

        foreach ($db->table('campaign_messages')->select('step, COUNT(*) AS n')->where('campaign_id', $id)
            ->groupBy('step')->orderBy('step')->get()->getResultArray() as $row) {
            $out['perStep'][(int) $row['step']] = (int) $row['n'];
        }
        $out['held'] = $db->table('campaign_recipients r')
            ->join('directory_listings l', 'l.id = r.listing_id')
            ->where('r.campaign_id', $id)->where('r.status', CampaignRecipientModel::STATUS_QUEUED)
            ->where('l.comms_paused_at IS NOT NULL', null, false)
            ->countAllResults();

        // Days from a profile's first email in this campaign to its claim.
        $days = [];
        foreach ($db->table('campaign_recipients r')
            ->select("MIN(cm.sent_at) AS first_sent, COALESCE(l.claimed_at, (SELECT MIN(m.created_at) FROM {$db->prefixTable('business_members')} m WHERE m.listing_id = l.id AND m.status = 'active')) AS claimed_on", false)
            ->join('directory_listings l', 'l.id = r.listing_id')
            ->join('campaign_messages cm', 'cm.recipient_id = r.id')
            ->where('r.campaign_id', $id)->where('r.reason', 'claimed')
            ->groupBy('r.id')->get()->getResultArray() as $row) {
            if (! empty($row['claimed_on']) && strtotime((string) $row['claimed_on']) >= strtotime((string) $row['first_sent'])) {
                $days[] = (strtotime((string) $row['claimed_on']) - strtotime((string) $row['first_sent'])) / DAY;
            }
        }
        if ($days !== []) {
            sort($days);
            $mid                      = intdiv(count($days), 2);
            $out['medianDaysToClaim'] = round(count($days) % 2 === 1 ? $days[$mid] : ($days[$mid - 1] + $days[$mid]) / 2, 1);
        }

        return $out;
    }

    /**
     * Every campaign, newest first, with its sent and queued counts.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $rows   = $this->campaigns->orderBy('id', 'DESC')->findAll();
        $counts = [];
        foreach (db_connect()->table('campaign_recipients')->select('campaign_id, status, COUNT(*) AS n')
            ->groupBy(['campaign_id', 'status'])->get()->getResultArray() as $c) {
            $counts[(int) $c['campaign_id']][$c['status']] = (int) $c['n'];
        }

        return array_map(static fn (array $r): array => $r + ['counts' => $counts[(int) $r['id']] ?? []], $rows);
    }

    /** @return list<array<string,mixed>> the latest rows of the send log */
    public function log(int $id, int $limit = 50): array
    {
        return db_connect()->table('campaign_recipients r')
            ->select('r.*, l.display_name, l.slug, l.country_code, l.comms_paused_at')
            ->join('directory_listings l', 'l.id = r.listing_id', 'left')
            ->where('r.campaign_id', $id)
            ->orderBy('r.updated_at', 'DESC')->orderBy('r.id', 'DESC')
            ->limit($limit)->get()->getResultArray();
    }

    /**
     * Hold every campaign email to a profile (the owner replied, or reported
     * a mistake on the claim page). Queued rows wait; nothing is dropped.
     */
    public function pauseProfile(int $listingId, string $note): bool
    {
        $note = mb_substr(trim((string) preg_replace('/\s+/', ' ', $note)), 0, 190);

        return (bool) (new DirectoryListingModel())->update($listingId, [
            'comms_paused_at'   => date('Y-m-d H:i:s'),
            'comms_paused_note' => $note !== '' ? $note : 'Paused by admin.',
        ]);
    }

    /** Let a paused profile's emails go again: anything overdue is due now. */
    public function resumeProfile(int $listingId): bool
    {
        return (bool) (new DirectoryListingModel())->update($listingId, ['comms_paused_at' => null, 'comms_paused_note' => null]);
    }

    /** @return list<array<string,mixed>> paused profiles, newest first */
    public function pausedProfiles(int $limit = 100): array
    {
        return db_connect()->table('directory_listings')
            ->select('id, display_name, slug, country_code, status, comms_paused_at, comms_paused_note')
            ->where('comms_paused_at IS NOT NULL', null, false)
            ->orderBy('comms_paused_at', 'DESC')->limit($limit)->get()->getResultArray();
    }

    // ------------------------------------------------------------ internals

    /**
     * Send a queued recipient its next step, after checking the profile again.
     *
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $r        a due queued recipient row
     * @param list<array<string,mixed>> $steps stepsOf($campaign)
     * @return 'sent'|'failed'|'skipped'|'held'
     */
    private function sendOne(array $campaign, array $r, array $audience, array $steps): string
    {
        $rid     = (int) $r['id'];
        $next    = (int) $r['step'] + 1;
        $step    = $steps[$next - 1] ?? null;
        $listing = (new DirectoryListingModel())->withDeleted()->find((int) $r['listing_id']);
        $reason  = $step === null ? 'no_step' : (is_array($listing)
            ? $this->audience->blockedReason($listing, (string) $r['email'], $audience)
            : 'unpublished');

        // Held, not dropped: paused by admin or the owner, or the address had
        // a campaign email too recently. The row waits for its next turn.
        if ($reason === 'paused') {
            return 'held';
        }
        if ($reason === null && ($until = $this->contactGapUntil((string) $r['email'])) !== null) {
            $this->recipients->update($rid, ['next_send_at' => $until]);

            return 'held';
        }
        if ($reason !== null) {
            $this->recipients->update($rid, ['status' => CampaignRecipientModel::STATUS_SKIPPED, 'reason' => $reason, 'next_send_at' => null]);

            return 'skipped';
        }

        $attempts = (int) $r['attempts'] + 1;
        $email    = null;
        try {
            $email = $this->renderer->render(self::asStep($campaign, $step), $listing, true);
            $ok    = (new Mailer())->send((string) $listing['email'], $email['subject'], $email['html'], '', [
                'headers' => [
                    'List-Unsubscribe'      => '<' . $email['unsubscribe'] . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Campaign {c} could not render for listing {l}: {m}', [
                'c' => $campaign['id'], 'l' => $r['listing_id'], 'm' => Mailer::oneLine($e->getMessage()),
            ]);
            $ok = false;
        }

        if ($ok && $email !== null) {
            $now = date('Y-m-d H:i:s');
            $this->messages->insert([
                'campaign_id'  => (int) $campaign['id'],
                'recipient_id' => $rid,
                'listing_id'   => (int) $listing['id'],
                'step'         => $next,
                'email'        => (string) $r['email'],
                'subject'      => mb_substr($email['subject'], 0, 190),
                'link_hash'    => $email['linkHash'],
                'link_expires' => $email['linkExpires'],
                'sent_at'      => $now,
            ]);
            $after = $steps[$next] ?? null;
            $this->recipients->update($rid, [
                'status'       => $after === null ? CampaignRecipientModel::STATUS_SENT : CampaignRecipientModel::STATUS_QUEUED,
                'step'         => $next,
                'attempts'     => 0,
                'sent_at'      => $now,
                'reason'       => null,
                'next_send_at' => $after === null ? null : date('Y-m-d H:i:s', time() + (int) $after['delay_days'] * DAY),
            ]);

            return 'sent';
        }

        // Left queued for the next run until it has had its tries.
        $this->recipients->update($rid, [
            'attempts' => $attempts,
            'status'   => $attempts >= config('Campaigns')->maxAttempts ? CampaignRecipientModel::STATUS_FAILED : CampaignRecipientModel::STATUS_QUEUED,
        ]);

        return 'failed';
    }

    /**
     * Due recipients of a campaign: queued, due now, profile not paused.
     * Follow ups first (a profile already in the sequence keeps its rhythm),
     * then the longest waiting.
     *
     * @return list<array<string,mixed>>
     */
    private function due(int $campaignId, int $take): array
    {
        return db_connect()->table('campaign_recipients r')
            ->select('r.*')
            ->join('directory_listings l', 'l.id = r.listing_id', 'left')
            ->where('r.campaign_id', $campaignId)
            ->where('r.status', CampaignRecipientModel::STATUS_QUEUED)
            ->groupStart()->where('r.next_send_at', null)->orWhere('r.next_send_at <=', date('Y-m-d H:i:s'))->groupEnd()
            ->where('l.comms_paused_at', null)
            ->orderBy('r.step', 'DESC')->orderBy('r.next_send_at', 'ASC')->orderBy('r.attempts', 'ASC')->orderBy('r.id', 'ASC')
            ->limit($take)->get()->getResultArray();
    }

    /**
     * Take imported profiles claimed since the Verified sequence started into
     * it, the first step due its delay after the claim. Their open claim
     * sequence rows close as claimed, so no profile is in two at once.
     *
     * @return int how many joined
     */
    private function enrolClaimed(array $campaign): int
    {
        if (empty($campaign['started_at'])) {
            return 0;
        }
        $id    = (int) $campaign['id'];
        $rows  = $this->audience->rows(self::audienceOf($campaign), $id, (string) $campaign['started_at']);
        if ($rows === []) {
            return 0;
        }
        $first = $this->stepsOf($campaign)[0]['delay_days'] ?? 0;
        $now   = date('Y-m-d H:i:s');
        $db    = db_connect();
        $ids   = array_map(static fn (array $l): int => (int) $l['id'], $rows);

        $db->table('campaign_recipients')->ignore(true)->insertBatch(array_map(static fn (array $l): array => [
            'campaign_id'  => $id,
            'listing_id'   => (int) $l['id'],
            'email'        => CampaignAudience::normaliseEmail((string) $l['email']),
            'status'       => CampaignRecipientModel::STATUS_QUEUED,
            'attempts'     => 0,
            'step'         => 0,
            'entered_at'   => $now,
            'next_send_at' => date('Y-m-d H:i:s', strtotime((string) $l['claimed_on']) + (int) $first * DAY),
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $rows));

        $claimCampaigns = array_column($db->table('campaigns')->select('id')->where('sequence', self::SEQUENCE_CLAIM)->get()->getResultArray(), 'id');
        if ($claimCampaigns !== []) {
            $db->table('campaign_recipients')
                ->whereIn('campaign_id', array_map('intval', $claimCampaigns))
                ->whereIn('listing_id', $ids)
                ->where('status', CampaignRecipientModel::STATUS_QUEUED)
                ->update(['status' => CampaignRecipientModel::STATUS_SKIPPED, 'reason' => 'claimed', 'next_send_at' => null, 'updated_at' => $now]);
        }

        return count($rows);
    }

    /** When the address may next get a campaign email, or null when it may now. */
    private function contactGapUntil(string $email): ?string
    {
        $gap  = config('Campaigns')->recentContactDays * DAY;
        $last = $this->messages->selectMax('sent_at', 'last')->where('email', $email)->first();
        $at   = is_array($last) && ! empty($last['last']) ? strtotime((string) $last['last']) : null;

        return $at !== null && $at + $gap > time() ? date('Y-m-d H:i:s', $at + $gap) : null;
    }

    /** The campaign as the renderer wants one step of it. */
    private static function asStep(array $campaign, array $step): array
    {
        return ['subject' => $step['subject'], 'body' => $step['body'], 'button' => $step['button'], 'extras' => $step['extras'] ?? []] + $campaign;
    }

    /**
     * A sequence's posted steps, trimmed, or null for a one off kind. Steps
     * missing from the post take the template's wording.
     *
     * @return list<array{subject:string,body:string,delay_days:int}>|null
     */
    private static function stepInput(string $kind, array $input): ?array
    {
        $template = config('Campaigns')->kinds[$kind]['steps'] ?? null;
        if ($template === null) {
            return null;
        }
        $posted = is_array($input['steps'] ?? null) ? $input['steps'] : [];
        $out    = [];
        foreach ($template as $i => $t) {
            $p     = is_array($posted[$i] ?? null) ? $posted[$i] : [];
            $out[] = [
                'subject'    => trim((string) ($p['subject'] ?? $t['subject'])),
                'body'       => trim(str_replace("\r\n", "\n", (string) ($p['body'] ?? implode("\n\n", $t['paragraphs'])))),
                'delay_days' => (int) ($p['delay_days'] ?? $t['delay_days']),
            ];
        }

        return $out;
    }

    /**
     * Write a sequence's steps. Returns whether any wording changed (a delay
     * alone does not need a new test).
     *
     * @param list<array{subject:string,body:string,delay_days:int}> $steps
     */
    private function saveSteps(int $campaignId, array $steps): bool
    {
        $changed = false;
        foreach ($steps as $i => $st) {
            $n   = $i + 1;
            $row = $this->steps->where('campaign_id', $campaignId)->where('step', $n)->first();
            if (is_array($row)) {
                $changed = $changed || $row['subject'] !== $st['subject'] || $row['body'] !== $st['body'];
                $this->steps->update((int) $row['id'], $st);
            } else {
                $changed = true;
                $this->steps->insert($st + ['campaign_id' => $campaignId, 'step' => $n]);
            }
        }

        return $changed;
    }

    private function sentToday(int $id): int
    {
        return $this->messages->where('campaign_id', $id)
            ->where('sent_at >=', date('Y-m-d 00:00:00'))
            ->countAllResults();
    }

    /** @return array<string,mixed>|null */
    private function sampleListing(array $campaign, ?int $listingId): ?array
    {
        if ($listingId !== null && $listingId > 0) {
            $row = (new DirectoryListingModel())->find($listingId);

            return is_array($row) ? $row : null;
        }

        $rows = $this->audience->rows(self::audienceOf($campaign));
        if ($rows === []) {
            return null;
        }
        $row = (new DirectoryListingModel())->find((int) $rows[0]['id']);

        return is_array($row) ? $row : null;
    }

    /** @param list<string> $from */
    private function move(int $id, array $from, array $set): bool
    {
        $campaign = $this->find($id);
        if ($campaign === null || ! in_array($campaign['status'], $from, true)) {
            return false;
        }

        return (bool) $this->campaigns->update($id, $set);
    }

    /**
     * @param array{name:string,subject:string,body:string,daily_limit:int} $values
     * @param array<string,mixed>                                             $audience
     * @return array<string,string>
     */
    private function validate(string $kind, array $values, array $audience, ?array $steps = null): array
    {
        $config   = config('Campaigns');
        $template = $config->kinds[$kind] ?? null;
        $errors   = [];

        if ($template === null) {
            return ['kind' => 'Pick a template.'];
        }
        if ($values['name'] === '' || mb_strlen($values['name']) > 120) {
            $errors['name'] = 'Give the campaign a name of up to 120 characters.';
        }
        if ($values['subject'] === '' || mb_strlen($values['subject']) > 160) {
            $errors['subject'] = 'The subject is needed, up to 160 characters.';
        }
        if ($values['body'] === '' || mb_strlen($values['body']) > self::MAX_BODY) {
            $errors['body'] = 'Write the email, up to ' . number_format(self::MAX_BODY) . ' characters.';
        }
        foreach (['subject', 'body'] as $field) {
            if (isset($errors[$field])) {
                continue;
            }
            $unknown = CampaignRenderer::unknownFields($values[$field]);
            if ($unknown !== []) {
                $errors[$field] = 'Unknown merge field ' . implode(', ', $unknown) . '. Use ' . implode(', ', CampaignRenderer::FIELDS) . '.';
            } elseif (($problem = self::copyProblem($values[$field])) !== null) {
                $errors[$field] = $problem;
            }
        }
        foreach ($steps ?? [] as $i => $st) {
            $n = $i + 1;
            foreach (['subject' => 160, 'body' => self::MAX_BODY] as $field => $max) {
                $text = $st[$field];
                if ($text === '' || mb_strlen($text) > $max) {
                    $errors["steps.{$n}.{$field}"] = 'Email ' . $n . ': the ' . $field . ' is needed, up to ' . number_format($max) . ' characters.';
                } elseif (($unknown = CampaignRenderer::unknownFields($text)) !== []) {
                    $errors["steps.{$n}.{$field}"] = 'Email ' . $n . ': unknown merge field ' . implode(', ', $unknown) . '.';
                } elseif (($problem = self::copyProblem($text)) !== null) {
                    $errors["steps.{$n}.{$field}"] = 'Email ' . $n . ': ' . $problem;
                }
            }
            if ($st['delay_days'] < ($n === 1 ? 0 : 1) || $st['delay_days'] > $config->maxDelayDays) {
                $errors["steps.{$n}.delay_days"] = 'Email ' . $n . ': between ' . ($n === 1 ? 0 : 1) . ' and ' . $config->maxDelayDays . ' days.';
            }
        }
        if ($values['daily_limit'] < 1 || $values['daily_limit'] > $config->maxDailyLimit) {
            $errors['daily_limit'] = 'Between 1 and ' . $config->maxDailyLimit . ' a day.';
        }
        if ($audience['segment'] === null || ! in_array($audience['segment'], $template['segments'], true)) {
            $errors['segment'] = 'This template goes to: ' . implode(', ', array_map(
                static fn (string $s): string => $config->segments[$s],
                $template['segments']
            )) . '.';
        }

        return $errors;
    }

    /** The customer copy rules a form can check: no dashes, no hyphenated words, no links. */
    private static function copyProblem(string $text): ?string
    {
        if (preg_match('/[\x{2014}\x{2013}]/u', $text) === 1) {
            return 'No dashes in customer copy. Use a comma or a full stop.';
        }
        if (preg_match('/\b\p{L}+-\p{L}+\b/u', $text, $m) === 1) {
            return 'No hyphenated words in customer copy ("' . $m[0] . '").';
        }
        if (preg_match('~https?://|www\.~i', $text) === 1) {
            return 'No links in the text: the button carries the link.';
        }

        return null;
    }
}
