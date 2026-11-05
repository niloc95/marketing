<?php

namespace App\Services\Bookings\Integrations;

use App\Models\Bookings\BookingOutboxModel as O;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\Notifications\BookingIntegrations;

/**
 * The business's webhook: one https address that hears about its bookings,
 * signed with a secret we make (Bookings defined webhooks and never sent
 * one; this is new, parity.md §11).
 *
 * Signing (docs on /developers/bookings): header
 * `WebScheduler-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">`
 * under the secret. The time is part of what is signed, so a captured
 * request cannot be replayed later; endpoints should refuse a `t` older than
 * five minutes.
 *
 * Sending is the worker's (IntegrationWorker), except the "Send a test"
 * button, which the admin presses and waits for, as with the WhatsApp test.
 */
class Webhooks
{
    public const EVENTS = [
        'appointment.created'     => 'A booking is made',
        'appointment.rescheduled' => 'A booking moves to a new time',
        'appointment.confirmed'   => 'A booking is confirmed',
        'appointment.pending'     => 'A booking goes back to waiting for confirmation',
        'appointment.completed'   => 'A booking is marked completed',
        'appointment.no_show'     => 'A booking is marked no show',
        'appointment.cancelled'   => 'A booking is cancelled',
        'deposit.paid'            => 'A deposit is paid',
    ];

    public const SIGNATURE_HEADER = 'WebScheduler-Signature';

    private BookingIntegrations $integrations;

    public function __construct(private int $listingId)
    {
        $this->integrations = new BookingIntegrations($listingId);
    }

    /** @return array{set:bool,active:bool,url:string,events:list<string>,health:string,last_error:?string,last_tested_at:?string} */
    public function settings(): array
    {
        $account = $this->integrations->account('webhook');
        $view    = $this->integrations->view('webhook');
        $config  = $account['config'] ?? [];

        return [
            'set'            => $account !== null && ($config['url'] ?? '') !== '',
            'active'         => $account !== null && $view['active'] && ($config['url'] ?? '') !== '',
            'url'            => (string) ($config['url'] ?? ''),
            'events'         => array_values(array_intersect(array_keys(self::EVENTS), (array) ($config['events'] ?? []))),
            'health'         => $view['health'],
            'last_error'     => $view['last_error'],
            'last_tested_at' => $view['last_tested_at'],
        ];
    }

    /** The signing secret, for the admin to copy. */
    public function secret(): string
    {
        return (string) ($this->integrations->account('webhook')['config']['secret'] ?? '');
    }

    /**
     * @param list<string> $events
     *
     * @return array<string,string> errors
     */
    public function save(string $url, array $events, bool $active, ?int $accountId): array
    {
        $url    = trim($url);
        $events = array_values(array_intersect(array_keys(self::EVENTS), $events));
        $errors = [];
        if (($problem = SafeUrl::problem($url)) !== null) {
            $errors['url'] = $problem;
        }
        if ($events === []) {
            $errors['events'] = 'Choose at least one thing to hear about.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $old    = $this->integrations->account('webhook')['config'] ?? [];
        $secret = (string) ($old['secret'] ?? '') ?: self::makeSecret();
        if (! $this->integrations->store('webhook', 'custom', ['url' => $url, 'secret' => $secret, 'events' => $events], $active, ($old['url'] ?? '') !== $url)) {
            return ['url' => 'We cannot save this right now. Please try again later.'];
        }
        if (! $active) {
            Outbox::forListing($this->listingId)?->dropQueued(O::WEBHOOK);
        }
        (new BookingAudit())->record($this->listingId, $accountId, 'connections.webhook', 'settings', null, null, ['url' => $url, 'events' => $events, 'active' => $active]);

        return [];
    }

    /** A new secret; the old one stops working at once. */
    public function newSecret(?int $accountId): bool
    {
        $account = $this->integrations->account('webhook');
        if ($account === null) {
            return false;
        }
        $config           = $account['config'];
        $config['secret'] = self::makeSecret();
        $active           = $this->integrations->view('webhook')['active'];
        if (! $this->integrations->store('webhook', 'custom', $config, $active, false)) {
            return false;
        }
        (new BookingAudit())->record($this->listingId, $accountId, 'connections.webhook_secret', 'settings');

        return true;
    }

    public function remove(?int $accountId): void
    {
        Outbox::forListing($this->listingId)?->dropQueued(O::WEBHOOK);
        $this->integrations->remove('webhook', $accountId);
    }

    /**
     * Send a `ping` now and say how it went.
     *
     * @return array{ok:bool,error:?string}
     */
    public function test(): array
    {
        $s = $this->settings();
        if (! $s['set']) {
            return ['ok' => false, 'error' => 'Save an address first.'];
        }
        $body = json_encode([
            'id'       => 'evt_' . bin2hex(random_bytes(12)),
            'type'     => 'ping',
            'created'  => gmdate('Y-m-d\TH:i:s\Z'),
            'business' => ['id' => $this->listingId],
            'data'     => ['message' => 'This is a test from WebScheduler Local.'],
        ], JSON_UNESCAPED_SLASHES);
        $result = $this->send($s['url'], 'ping', (string) $body, 'test');
        $this->integrations->recordHealth('webhook', $result['ok'], $result['error'], true);

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /**
     * Send one queued event (the worker's call).
     *
     * @param array<string,mixed> $row xs_booking_outbox
     *
     * @return array{ok:bool,status:int,error:?string,final:bool}
     */
    public function deliver(array $row): array
    {
        $s = $this->settings();
        if (! $s['active']) {
            return ['ok' => false, 'status' => 0, 'error' => 'The webhook is off.', 'final' => true];
        }
        $result = $this->send($s['url'], (string) $row['event'], (string) $row['payload'], 'del_' . $row['id']);
        $this->integrations->recordHealth('webhook', $result['ok'], $result['error']);

        return $result + ['final' => false];
    }

    /**
     * The latest deliveries, newest first, for the settings screen.
     *
     * @return list<array<string,mixed>>
     */
    public function deliveries(int $limit = 20): array
    {
        return O::forBusiness($this->listingId)->where('target', O::WEBHOOK)->orderBy('id', 'DESC')->findAll($limit);
    }

    /** `t=...,v1=...` for a body at a time. */
    public static function signature(string $secret, string $body, int $time): string
    {
        return 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret);
    }

    /** @return array{ok:bool,status:int,error:?string} */
    private function send(string $url, string $event, string $body, string $deliveryId): array
    {
        if (($problem = SafeUrl::problem($url)) !== null) {
            return ['ok' => false, 'status' => 0, 'error' => $problem];
        }
        $result = Http::request('POST', $url, [
            'body'    => $body,
            'headers' => [
                'Content-Type'            => 'application/json',
                'User-Agent'              => 'WebScheduler-Local-Webhooks/1',
                'WebScheduler-Event'      => $event,
                'WebScheduler-Delivery'   => $deliveryId,
                self::SIGNATURE_HEADER    => self::signature($this->secret(), $body, time()),
            ],
        ]);

        return ['ok' => $result['error'] === null, 'status' => $result['status'], 'error' => $result['error']];
    }

    private static function makeSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }
}
