<?php

namespace App\Services\Communications;

use App\Libraries\Mailer;
use App\Models\CampaignModel;
use App\Models\CampaignRecipientModel;
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
 */
class CommunicationsService
{
    public const MAX_BODY = 6000;

    /** Sends in a run: the day's limit spread over this many runs (10 minutes, 08:00 to 17:00). */
    public const RUNS_PER_DAY = 54;

    private CampaignModel $campaigns;
    private CampaignRecipientModel $recipients;
    private CampaignAudience $audience;
    private CampaignRenderer $renderer;

    public function __construct(?CampaignAudience $audience = null, ?CampaignRenderer $renderer = null)
    {
        $this->campaigns  = new CampaignModel();
        $this->recipients = new CampaignRecipientModel();
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

        return [
            'kind'        => $kind,
            'name'        => $template['label'] . ' ' . date('j M Y'),
            'subject'     => $template['subject'],
            'body'        => implode("\n\n", $template['paragraphs']),
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
        $values = [
            'name'        => trim((string) ($input['name'] ?? '')),
            'subject'     => trim((string) ($input['subject'] ?? '')),
            'body'        => trim(str_replace("\r\n", "\n", (string) ($input['body'] ?? ''))),
            'daily_limit' => (int) ($input['daily_limit'] ?? 0),
        ];
        $audience = $existing !== null && $existing['status'] !== CampaignModel::STATUS_DRAFT
            ? self::audienceOf($existing)
            : CampaignAudience::normalise($input);

        $errors = $this->validate($kind, $values, $audience);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $row = $values + ['audience' => json_encode($audience)];
        if ($existing === null) {
            $id = (int) $this->campaigns->insert($row + ['kind' => $kind, 'status' => CampaignModel::STATUS_DRAFT]);
        } else {
            // A changed email needs a fresh test before it can start.
            $changed = $existing['subject'] !== $values['subject'] || $existing['body'] !== $values['body'];
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
    public function preview(array $campaign, ?int $listingId = null): ?array
    {
        $listing = $this->sampleListing($campaign, $listingId);
        if ($listing === null) {
            return null;
        }

        return $this->renderer->render($campaign, $listing, false) + ['listing' => $listing];
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

        $email = $this->preview($campaign, $listingId);
        if ($email === null) {
            return ['ok' => false, 'message' => 'No profile to render the test for: the audience is empty and no profile id was given.'];
        }

        if (! (new Mailer())->send($to, '[Test] ' . $email['subject'], $email['html'])) {
            return ['ok' => false, 'message' => 'The test did not go out. Check Status and health for the mail error.'];
        }

        $this->campaigns->update($id, ['test_sent_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'message' => 'Test sent to ' . $to . ', rendered for ' . $email['listing']['display_name'] . '.'];
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

        $rows = $this->audience->rows(self::audienceOf($campaign), $id);
        if ($rows === []) {
            return ['ok' => false, 'message' => 'Nobody is in this audience.'];
        }

        $now = date('Y-m-d H:i:s');
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
        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'paused' => 0, 'done' => 0, 'would' => 0];

        foreach ($this->campaigns->where('status', CampaignModel::STATUS_SENDING)->orderBy('id')->findAll() as $campaign) {
            $id        = (int) $campaign['id'];
            $limit     = (int) $campaign['daily_limit'];
            $allowance = max(0, $limit - $this->sentToday($id));
            $share     = $perRun ?? max(1, (int) ceil($limit / self::RUNS_PER_DAY));
            $take      = min($allowance, $share);

            if ($take > 0) {
                $queued = $this->recipients->where('campaign_id', $id)
                    ->where('status', CampaignRecipientModel::STATUS_QUEUED)
                    ->orderBy('attempts', 'ASC')->orderBy('id', 'ASC')
                    ->findAll($take);

                if ($dryRun) {
                    $totals['would'] += count($queued);

                    continue;
                }

                $audience = self::audienceOf($campaign);
                $streak   = 0;
                foreach ($queued as $r) {
                    $outcome = $this->sendOne($campaign, $r, $audience);
                    $totals[$outcome]++;
                    $streak = $outcome === 'failed' ? $streak + 1 : 0;
                    if ($streak >= $config->pauseAfterFailures) {
                        $this->pause($id, $streak . ' sends failed in a row at ' . date('Y-m-d H:i') . '. Check Status and health, then resume.');
                        $totals['paused']++;

                        continue 2;
                    }
                }
            }

            if (! $dryRun && $this->recipients->where('campaign_id', $id)->where('status', CampaignRecipientModel::STATUS_QUEUED)->countAllResults() === 0) {
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
        $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reasons' => [], 'optedOutSince' => 0, 'sentToday' => $this->sentToday($id)];

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
                ->where('r.status', CampaignRecipientModel::STATUS_SENT)
                ->where('l.comms_opt_out_at >=', $campaign['started_at'])
                ->countAllResults();
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
            ->select('r.*, l.display_name, l.slug, l.country_code')
            ->join('directory_listings l', 'l.id = r.listing_id', 'left')
            ->where('r.campaign_id', $id)
            ->orderBy('r.updated_at', 'DESC')->orderBy('r.id', 'DESC')
            ->limit($limit)->get()->getResultArray();
    }

    // ------------------------------------------------------------ internals

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $r        a queued recipient row
     * @return 'sent'|'failed'|'skipped'
     */
    private function sendOne(array $campaign, array $r, array $audience): string
    {
        $rid     = (int) $r['id'];
        $listing = (new DirectoryListingModel())->withDeleted()->find((int) $r['listing_id']);
        $reason  = is_array($listing)
            ? $this->audience->blockedReason($listing, (string) $r['email'], $audience)
            : 'unpublished';

        if ($reason !== null) {
            $this->recipients->update($rid, ['status' => CampaignRecipientModel::STATUS_SKIPPED, 'reason' => $reason]);

            return 'skipped';
        }

        $attempts = (int) $r['attempts'] + 1;
        try {
            $email = $this->renderer->render($campaign, $listing, true);
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

        if ($ok) {
            $this->recipients->update($rid, [
                'status' => CampaignRecipientModel::STATUS_SENT, 'attempts' => $attempts, 'sent_at' => date('Y-m-d H:i:s'), 'reason' => null,
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

    private function sentToday(int $id): int
    {
        return $this->recipients->where('campaign_id', $id)
            ->where('status', CampaignRecipientModel::STATUS_SENT)
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
    private function validate(string $kind, array $values, array $audience): array
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
