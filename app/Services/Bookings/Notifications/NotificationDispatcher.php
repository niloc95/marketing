<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BookingNotificationQueueModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AppointmentStatus;
use Config\BookingNotifications as Catalog;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sends what the queue holds (`php spark bookings:dispatch`, cron each
 * minute, every business in one run). Ported from Bookings'
 * NotificationQueueDispatcher, keeping its contract:
 *
 * - Claim with a random lock token; a lock older than 15 minutes is a dead
 *   worker's and is taken again.
 * - Before sending, check again that it is still wanted: the business still
 *   takes bookings, the rule and the account are still on, the customer has
 *   not opted out, and the message still matches the appointment. A reminder
 *   or a "new time" whose fingerprint no longer matches (moved again since),
 *   or a status message the appointment has since left, is cancelled, not
 *   sent: the newer message says what is true.
 * - A failed send is tried again after 1, 2, 4, 8 minutes, up to 60, then
 *   given up after max_attempts. Every attempt is a delivery log line.
 * - Per run limits per channel; the rest wait for the next minute.
 *
 * The one place that reads the queue across businesses (the due list); each
 * business's rows are then read and written through its own scope.
 */
class NotificationDispatcher
{
    private Catalog $catalog;

    /** @var array<string,int> */
    private array $sentPerChannel = [];

    public function __construct(private ?DateTimeImmutable $now = null)
    {
        $this->catalog = config('BookingNotifications');
    }

    /**
     * Queue due reminders, then send, for every business with bookings on.
     *
     * @return array{reminders:int,claimed:int,sent:int,failed:int,retrying:int,cancelled:int}
     */
    public function run(int $limit = 500, ?float $deadline = null): array
    {
        $stats = ['reminders' => 0, 'claimed' => 0, 'sent' => 0, 'failed' => 0, 'retrying' => 0, 'cancelled' => 0];
        if (! config('Bookings')->enabled()) {
            return $stats;
        }
        $this->sentPerChannel = array_fill_keys(Catalog::CHANNELS, 0);

        $listings = (new DirectoryListingModel())->where('bookings_enabled', 1)->findColumn('id') ?? [];
        foreach ($listings as $listingId) {
            $stats['reminders'] += (new NotificationQueue((int) $listingId, $this->now))->dueReminders()['queued'];
        }

        $due = db_connect()->table('xs_booking_notification_queue')->distinct()->select('listing_id')
            ->where('status', 'queued')->where('run_after <=', $this->nowUtc())->get()->getResultArray();
        foreach (array_column($due, 'listing_id') as $listingId) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            foreach ($this->dispatch((int) $listingId, $limit) as $key => $n) {
                $stats[$key] += $n;
            }
        }

        return $stats;
    }

    /**
     * Send one business's due messages.
     *
     * @return array{claimed:int,sent:int,failed:int,retrying:int,cancelled:int}
     */
    public function dispatch(int $listingId, int $limit = 500): array
    {
        $stats = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'retrying' => 0, 'cancelled' => 0];
        if ($this->sentPerChannel === []) {
            $this->sentPerChannel = array_fill_keys(Catalog::CHANNELS, 0);
        }
        $rows = $this->claim($listingId, $limit);
        if ($rows === []) {
            return $stats;
        }
        $stats['claimed'] = count($rows);

        $listing      = (new DirectoryListingModel())->find($listingId);
        $rules        = new NotificationRules($listingId);
        $integrations = new BookingIntegrations($listingId);
        $optOuts      = new OptOuts($listingId);
        $templates    = new NotificationTemplates($listingId);

        foreach ($rows as $row) {
            $outcome = $this->one($row, $listing, $rules, $integrations, $optOuts, $templates);
            $stats[$outcome]++;
        }

        return $stats;
    }

    /** @return string sent | failed | retrying | cancelled */
    private function one(array $row, ?array $listing, NotificationRules $rules, BookingIntegrations $integrations, OptOuts $optOuts, NotificationTemplates $templates): string
    {
        $channel = (string) $row['channel'];
        $event   = (string) $row['event'];
        $team    = $row['recipient_type'] === Catalog::TEAM;

        if ($listing === null || (int) $listing['bookings_enabled'] !== 1 || ! config('Bookings')->siteAllowed(site()->forRow($listing))) {
            return $this->cancel($row, 'The business no longer takes bookings.');
        }
        if (! $rules->enabled($event, $channel, $team ? Catalog::TEAM : Catalog::CUSTOMER) && ! str_contains((string) $row['idempotency_key'], ':manual:')) {
            return $this->cancel($row, 'This message is turned off.');
        }
        if ($channel !== 'email' && ! $integrations->active($channel)) {
            return $this->cancel($row, 'The ' . Catalog::CHANNEL_LABELS[$channel] . ' account is off.');
        }
        if (($this->sentPerChannel[$channel] ?? 0) >= ($this->catalog->perRunLimit[$channel] ?? 100)) {
            $this->release($row);

            return 'retrying';
        }

        $context = MessageContext::build($listing, (int) $row['appointment_id'], $this->now);
        if ($context === null) {
            return $this->fail($row, 'The appointment is gone.', null, true);
        }
        if (($stale = $this->stale($row, $context['appointment'])) !== null) {
            return $this->cancel($row, $stale);
        }
        // A Zoom link is on its way (the outbox runs first each minute): wait
        // for it, but never longer than ten minutes after the change.
        if (! $team && $this->zoomLinkComing($context['appointment'], $integrations)) {
            $this->release($row);

            return 'retrying';
        }

        // Who it goes to, as they are now.
        if ($team) {
            $recipient = $this->memberEmail((int) $listing['id'], (int) $row['member_id']);
            if ($recipient === null) {
                return $this->cancel($row, 'That team member no longer gets notices.');
            }
        } elseif ($context['customer'] === null) {
            return $this->cancel($row, 'The customer record is gone.');
        } else {
            $recipient = $channel === 'email'
                ? (string) ($context['customer']['email'] ?? '')
                : (string) (Channels::e164($context['customer']['phone'] ?? '', (string) ($listing['country_code'] ?? 'ZA')) ?? '');
            if ($recipient === '') {
                return $this->cancel($row, $channel === 'email' ? 'The customer has no email address.' : 'The customer has no mobile number.');
            }
            if ($optOuts->isOptedOut($channel, $recipient)) {
                return $this->cancel($row, 'The customer asked not to get these.', $recipient);
            }
        }

        $template = $team ? null : $templates->get($event, $channel);
        if (! $team && $template === null) {
            return $this->cancel($row, 'No message for this event.');
        }
        $result = match ($channel) {
            'email'    => Channels::email($listing, $context, $event, $recipient, $template, $team),
            'whatsapp' => Channels::whatsapp($integrations->account('whatsapp'), $event, $recipient, NotificationTemplates::fill($template, $context['values'])['body'], $context['values']),
        };
        if ($channel !== 'email') {
            $integrations->recordHealth($channel, $result['ok'], $result['error']);
        }

        if ($result['ok']) {
            $this->sentPerChannel[$channel]++;
            BookingNotificationQueueModel::forBusiness((int) $row['listing_id'])->update((int) $row['id'], [
                'status' => 'sent', 'sent_at' => $this->nowUtc(), 'attempts' => (int) $row['attempts'] + 1,
                'locked_at' => null, 'lock_token' => null, 'last_error' => null,
            ]);
            DeliveryLog::record($row, DeliveryLog::SENT, $recipient);
            if ($event === 'reminder' && ! $team) {
                // Not through the model: updated_at stays as it was.
                db_connect()->table('xs_booking_appointments')->where('id', (int) $row['appointment_id'])
                    ->where('listing_id', (int) $row['listing_id'])->update(['reminder_sent' => 1]);
            }

            return 'sent';
        }

        return $this->fail($row, (string) $result['error'], $recipient);
    }

    /**
     * Why a message no longer fits the appointment, or null when it does.
     *
     * @param array<string,mixed> $appointment
     */
    private function stale(array $row, array $appointment): ?string
    {
        $status = (string) $appointment['status'];
        $start  = (string) $appointment['start_at'];

        return match ((string) $row['event']) {
            'reminder' => ! in_array($status, AppointmentStatus::UPCOMING, true) ? 'The appointment is no longer coming up.'
                : ($start <= $this->nowUtc() ? 'The appointment has started.'
                : ($row['schedule_fingerprint'] !== NotificationQueue::fingerprint($appointment) ? 'The appointment moved since; a new reminder will go.' : null)),
            'rescheduled' => ! in_array($status, AppointmentStatus::UPCOMING, true) ? 'The appointment is no longer coming up.'
                : ($row['schedule_fingerprint'] !== null && $row['schedule_fingerprint'] !== NotificationQueue::fingerprint($appointment) ? 'The appointment moved again; the newer message says so.' : null),
            'pending', 'confirmed', 'cancelled', 'no_show' => NotificationQueue::statusEvent($status) !== $row['event'] && ! str_contains((string) $row['idempotency_key'], ':manual:')
                ? 'The appointment has changed since.'
                : null,
            default => null,
        };
    }

    private function memberEmail(int $listingId, int $memberId): ?string
    {
        $member = BusinessMemberModel::forBusiness($listingId)->find($memberId);
        if (! is_array($member) || $member['status'] !== BusinessMemberModel::STATUS_ACTIVE || (int) $member['notify_on_appointments'] !== 1) {
            return null;
        }
        $account = (new AccountModel())->find((int) $member['account_id']);

        return is_array($account) && $account['status'] === AccountModel::STATUS_ACTIVE ? (string) $account['email'] : null;
    }

    /** @return list<array<string,mixed>> */
    private function claim(int $listingId, int $limit): array
    {
        $token = bin2hex(random_bytes(16));
        $now   = $this->nowUtc();
        $stale = gmdate('Y-m-d H:i:s', $this->nowTs() - $this->catalog->staleLockMinutes * 60);
        $ids   = BookingNotificationQueueModel::forBusiness($listingId)
            ->where('status', 'queued')->where('run_after <=', $now)
            ->groupStart()->where('lock_token', null)->orWhere('locked_at <', $stale)->groupEnd()
            ->orderBy('id', 'ASC')->findColumn('id') ?? [];
        $ids = array_slice($ids, 0, max(1, $limit));
        if ($ids === []) {
            return [];
        }
        // One UPDATE: a row another worker took in between keeps its token.
        db_connect()->table('xs_booking_notification_queue')
            ->where('listing_id', $listingId)->where('status', 'queued')->whereIn('id', $ids)
            ->groupStart()->where('lock_token', null)->orWhere('locked_at <', $stale)->groupEnd()
            ->update(['lock_token' => $token, 'locked_at' => $now]);

        return BookingNotificationQueueModel::forBusiness($listingId)->where('lock_token', $token)->orderBy('id', 'ASC')->findAll();
    }

    private function cancel(array $row, string $why, ?string $recipient = null): string
    {
        BookingNotificationQueueModel::forBusiness((int) $row['listing_id'])->update((int) $row['id'], [
            'status' => 'cancelled', 'last_error' => $why, 'locked_at' => null, 'lock_token' => null,
        ]);
        DeliveryLog::record($row, DeliveryLog::CANCELLED, $recipient, $why);

        return 'cancelled';
    }

    private function fail(array $row, string $error, ?string $recipient, bool $final = false): string
    {
        $attempts = (int) $row['attempts'] + 1;
        $final    = $final || $attempts >= max(1, (int) $row['max_attempts']);
        $data     = ['attempts' => $attempts, 'last_error' => mb_substr($error, 0, 255), 'locked_at' => null, 'lock_token' => null];
        if ($final) {
            $data['status'] = 'failed';
        } else {
            $wait              = (int) min($this->catalog->maxBackoffMinutes, 2 ** ($attempts - 1));
            $data['run_after'] = gmdate('Y-m-d H:i:s', $this->nowTs() + $wait * 60);
        }
        BookingNotificationQueueModel::forBusiness((int) $row['listing_id'])->update((int) $row['id'], $data);
        DeliveryLog::record($row, $final ? DeliveryLog::FAILED : DeliveryLog::RETRYING, $recipient, $error);

        return $final ? 'failed' : 'retrying';
    }

    /** @param array<string,mixed> $a */
    private function zoomLinkComing(array $a, BookingIntegrations $integrations): bool
    {
        return $a['delivery_mode'] === 'online_zoom'
            && (string) ($a['video_link'] ?? '') === ''
            && in_array($a['status'], ['pending', 'confirmed'], true)
            && $integrations->active('zoom')
            && strtotime((string) $a['updated_at'] . ' UTC') > $this->nowTs() - 600;
    }

    private function release(array $row): void
    {
        BookingNotificationQueueModel::forBusiness((int) $row['listing_id'])->update((int) $row['id'], ['locked_at' => null, 'lock_token' => null]);
    }

    private function nowTs(): int
    {
        return ($this->now ?? new DateTimeImmutable())->getTimestamp();
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s', $this->nowTs());
    }
}
