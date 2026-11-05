<?php

namespace App\Services\Bookings\Integrations;

/**
 * The business's own Zoom app (Server to Server OAuth: account id, client id
 * and secret), ported from Bookings' ZoomIntegrationService. Bookings made a
 * meeting on booking and never touched it again; here a new time moves the
 * meeting and a cancellation deletes it (IntegrationSync).
 *
 * The access token lives for an hour and is kept only for this run, never
 * stored.
 */
class Zoom
{
    private const TOKEN_URL = 'https://zoom.us/oauth/token';

    private const API = 'https://api.zoom.us/v2';

    private ?string $token = null;

    /** @param array<string,string> $config account_id, client_id, client_secret */
    public function __construct(private array $config)
    {
    }

    /**
     * Do the details work? Returns the Zoom account's email when they do.
     *
     * @return array{ok:bool,error:?string,email:?string}
     */
    public function check(): array
    {
        $r = $this->call('GET', '/users/me');

        return $r['error'] === null
            ? ['ok' => true, 'error' => null, 'email' => (string) ($r['json']['email'] ?? '')]
            : ['ok' => false, 'error' => $r['error'], 'email' => null];
    }

    /**
     * @param array{topic:string,start:string,duration:int,timezone:string} $meeting start as UTC Y-m-d\TH:i:s\Z
     *
     * @return array{ok:bool,error:?string,id:?string,join_url:?string}
     */
    public function create(array $meeting): array
    {
        $r = $this->call('POST', '/users/me/meetings', [
            'topic'      => mb_substr($meeting['topic'], 0, 200),
            'type'       => 2,
            'start_time' => $meeting['start'],
            'duration'   => $meeting['duration'],
            'timezone'   => $meeting['timezone'],
            'settings'   => ['waiting_room' => true, 'join_before_host' => false],
        ]);
        if ($r['error'] !== null || empty($r['json']['join_url'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'Zoom did not give a meeting link.', 'id' => null, 'join_url' => null];
        }

        return ['ok' => true, 'error' => null, 'id' => (string) $r['json']['id'], 'join_url' => (string) $r['json']['join_url']];
    }

    /**
     * @param array{topic:string,start:string,duration:int,timezone:string} $meeting
     *
     * @return array{ok:bool,error:?string,gone:bool}
     */
    public function update(string $id, array $meeting): array
    {
        $r = $this->call('PATCH', '/meetings/' . rawurlencode($id), [
            'topic'      => mb_substr($meeting['topic'], 0, 200),
            'start_time' => $meeting['start'],
            'duration'   => $meeting['duration'],
            'timezone'   => $meeting['timezone'],
        ]);

        return ['ok' => $r['error'] === null, 'error' => $r['error'], 'gone' => $r['status'] === 404];
    }

    /** @return array{ok:bool,error:?string} */
    public function delete(string $id): array
    {
        $r = $this->call('DELETE', '/meetings/' . rawurlencode($id));

        // Already gone is what we wanted.
        return $r['error'] === null || $r['status'] === 404 ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => $r['error']];
    }

    /**
     * @param array<string,mixed>|null $json
     *
     * @return array{status:int,json:array<string,mixed>|null,error:?string}
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $token = $this->token();
        if ($token === null) {
            return ['status' => 0, 'json' => null, 'error' => 'Zoom did not accept the app details. Check the Account ID, Client ID and secret.'];
        }
        $options = ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $r = Http::request($method, self::API . $path, $options);
        if ($r['error'] !== null && isset($r['json']['message'])) {
            $r['error'] = 'Zoom said: ' . mb_substr((string) $r['json']['message'], 0, 200);
        }

        return $r;
    }

    private function token(): ?string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        if (($this->config['account_id'] ?? '') === '' || ($this->config['client_id'] ?? '') === '' || ($this->config['client_secret'] ?? '') === '') {
            return null;
        }
        $r = Http::request('POST', self::TOKEN_URL, [
            'auth'        => [$this->config['client_id'], $this->config['client_secret']],
            'form_params' => ['grant_type' => 'account_credentials', 'account_id' => $this->config['account_id']],
        ]);
        $this->token = $r['error'] === null && ! empty($r['json']['access_token']) ? (string) $r['json']['access_token'] : null;

        return $this->token;
    }
}
