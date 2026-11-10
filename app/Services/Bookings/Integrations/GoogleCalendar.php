<?php

namespace App\Services\Bookings\Integrations;

use App\Models\Bookings\BookingOutboxModel as O;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\Notifications\BookingIntegrations;

/**
 * Google Calendar for one business: its appointments appear in the Google
 * calendar of the account that connected, and follow every new time and
 * cancellation. Bookings had the sign in and never pushed an event
 * (parity.md §11); this is new.
 *
 * - One OAuth client for the platform (Config\Bookings::$googleClientId,
 *   decision T22), not one per business as in Bookings: a business presses
 *   Connect and says yes at Google, nothing to paste.
 * - Scope `calendar.events` only (plus the email address, to show which
 *   account is connected). Tokens are encrypted in xs_booking_integrations
 *   (channel google), never shown.
 * - One way: our appointments go to Google. Busy times in Google do not
 *   block bookings (parity.md, Later).
 * - Google saying the connection is no longer valid (`invalid_grant`, the
 *   person removed access) switches it off and the settings screen asks for
 *   a new connection.
 */
class GoogleCalendar
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE    = 'https://oauth2.googleapis.com/revoke';
    private const API       = 'https://www.googleapis.com/calendar/v3';
    public const SCOPE      = 'https://www.googleapis.com/auth/calendar.events openid email';

    private BookingIntegrations $integrations;

    public function __construct(private array $listing)
    {
        $this->integrations = new BookingIntegrations((int) $listing['id']);
    }

    public static function offered(): bool
    {
        return config('Bookings')->googleCalendarOffered();
    }

    public function redirectUri(): string
    {
        helper('site');

        return listing_site_url($this->listing, 'business/connections/google/callback');
    }

    /** Where to send the admin to say yes at Google. */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => config('Bookings')->googleClientId,
            'redirect_uri'           => $this->redirectUri(),
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }

    /** @return array{connected:bool,active:bool,email:string,health:string,last_error:?string} */
    public function status(): array
    {
        $account = $this->integrations->account('google');
        $view    = $this->integrations->view('google');

        return [
            'connected'  => $account !== null && ($account['config']['refresh_token'] ?? '') !== '',
            'active'     => $this->integrations->active('google'),
            'email'      => (string) ($account['config']['email'] ?? ''),
            'health'     => $view['health'],
            'last_error' => $view['last_error'],
        ];
    }

    /**
     * Swap the code Google sent back for tokens and save them. Returns an
     * error for the admin, or null.
     */
    public function connect(string $code, ?int $accountId): ?string
    {
        $r = Http::request('POST', self::TOKEN_URL, ['form_params' => [
            'code'          => $code,
            'client_id'     => config('Bookings')->googleClientId,
            'client_secret' => config('Bookings')->googleClientSecret,
            'redirect_uri'  => $this->redirectUri(),
            'grant_type'    => 'authorization_code',
        ]]);
        $t = $r['json'] ?? [];
        if ($r['error'] !== null || empty($t['access_token'])) {
            return 'Google did not connect. Please try again.';
        }
        if (empty($t['refresh_token'])) {
            return 'Google did not give lasting access. Remove WebScheduler Local under your Google account\'s third party access, then connect again.';
        }
        if (! str_contains((string) ($t['scope'] ?? ''), 'calendar.events')) {
            return 'Please allow access to your calendar events when Google asks.';
        }

        $ok = $this->integrations->store('google', 'google', [
            'access_token'  => (string) $t['access_token'],
            'refresh_token' => (string) $t['refresh_token'],
            'expires_at'    => time() + (int) ($t['expires_in'] ?? 3600),
            'calendar_id'   => 'primary',
            'email'         => self::emailFrom((string) ($t['id_token'] ?? '')),
        ], true);
        if (! $ok) {
            return 'We cannot save the connection right now. Please try again later.';
        }
        (new BookingAudit())->record((int) $this->listing['id'], $accountId, 'connections.google', 'settings');
        (new Outbox($this->listing))->backfillGoogle();

        return null;
    }

    /** Stop sending, tell Google to forget us, and remove the tokens. Events already in the calendar stay. */
    public function disconnect(?int $accountId): void
    {
        $token = (string) ($this->integrations->account('google')['config']['refresh_token'] ?? '');
        if ($token !== '') {
            Http::request('POST', self::REVOKE, ['form_params' => ['token' => $token]], 5);
        }
        (new Outbox($this->listing))->dropQueued(O::GOOGLE);
        $this->integrations->remove('google', $accountId);
    }

    /** @return array{ok:bool,error:?string} */
    public function check(): array
    {
        $r = $this->call('GET', '/calendars/primary');
        $this->integrations->recordHealth('google', $r['error'] === null, $r['error'], true);

        return ['ok' => $r['error'] === null, 'error' => $r['error']];
    }

    /**
     * Make or change an event.
     *
     * @param array<string,mixed> $event Google event resource
     *
     * @return array{ok:bool,error:?string,id:?string,auth:bool}
     */
    public function save(?string $eventId, array $event): array
    {
        $calendar = rawurlencode($this->calendarId());
        $r        = $eventId !== null
            ? $this->call('PUT', '/calendars/' . $calendar . '/events/' . rawurlencode($eventId), $event)
            : $this->call('POST', '/calendars/' . $calendar . '/events', $event);
        if ($eventId !== null && in_array($r['status'], [404, 410], true)) {
            // Deleted in Google by hand: make it again.
            $r = $this->call('POST', '/calendars/' . $calendar . '/events', $event);
        }
        $this->integrations->recordHealth('google', $r['error'] === null, $r['error']);

        return [
            'ok'    => $r['error'] === null,
            'error' => $r['error'],
            'id'    => $r['error'] === null ? (string) ($r['json']['id'] ?? $eventId) : null,
            'auth'  => $r['status'] === -1,
        ];
    }

    /** @return array{ok:bool,error:?string,auth:bool} */
    public function delete(string $eventId): array
    {
        $r  = $this->call('DELETE', '/calendars/' . rawurlencode($this->calendarId()) . '/events/' . rawurlencode($eventId));
        $ok = $r['error'] === null || in_array($r['status'], [404, 410], true);
        $this->integrations->recordHealth('google', $ok, $ok ? null : $r['error']);

        return ['ok' => $ok, 'error' => $ok ? null : $r['error'], 'auth' => $r['status'] === -1];
    }

    private function calendarId(): string
    {
        return (string) ($this->integrations->account('google')['config']['calendar_id'] ?? 'primary') ?: 'primary';
    }

    /**
     * @param array<string,mixed>|null $json
     *
     * @return array{status:int,json:array<string,mixed>|null,error:?string} status -1: the connection no longer works
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return ['status' => -1, 'json' => null, 'error' => 'Google no longer accepts the connection. Connect Google Calendar again.'];
        }
        $options = ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $r = Http::request($method, self::API . $path, $options);
        if ($r['error'] !== null && isset($r['json']['error']['message'])) {
            $r['error'] = 'Google said: ' . mb_substr((string) $r['json']['error']['message'], 0, 200);
        }

        return ['status' => $r['status'], 'json' => $r['json'], 'error' => $r['error']];
    }

    /** A working access token, refreshed when it is about to run out; null when access was withdrawn. */
    private function accessToken(): ?string
    {
        $account = $this->integrations->account('google');
        $config  = $account['config'] ?? [];
        if (($config['refresh_token'] ?? '') === '') {
            return null;
        }
        if (($config['access_token'] ?? '') !== '' && (int) ($config['expires_at'] ?? 0) > time() + 60) {
            return (string) $config['access_token'];
        }

        $r = Http::request('POST', self::TOKEN_URL, ['form_params' => [
            'client_id'     => config('Bookings')->googleClientId,
            'client_secret' => config('Bookings')->googleClientSecret,
            'refresh_token' => $config['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]]);
        if ($r['error'] !== null || empty($r['json']['access_token'])) {
            if (($r['json']['error'] ?? '') === 'invalid_grant') {
                $this->integrations->setActive('google', false);
                $this->integrations->recordHealth('google', false, 'Google no longer accepts the connection. Connect Google Calendar again.');
            }

            return null;
        }
        $config['access_token'] = (string) $r['json']['access_token'];
        $config['expires_at']   = time() + (int) ($r['json']['expires_in'] ?? 3600);
        $this->integrations->store('google', 'google', $config, $this->integrations->view('google')['active'], false);

        return $config['access_token'];
    }

    /**
     * The email in Google's id token. It came straight from Google over TLS
     * in answer to our own request, so the payload is read, not verified.
     */
    private static function emailFrom(string $idToken): string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return '';
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), false), true);

        return is_array($payload) && filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL) ? (string) $payload['email'] : '';
    }
}
