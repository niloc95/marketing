<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingIntegrationModel;
use App\Services\Bookings\BookingAudit;

/**
 * The WhatsApp account a business brings (decision D8: the business pays its
 * own provider; we do not resell; SMS was removed by the owner, T20), and since Phase 8 the PayFast or
 * Paystack account its deposits go into (channel 'payment', decision D6: the
 * money goes to the business, never through us). Ported from Bookings'
 * NotificationWhatsAppService integration settings
 * and HandlesNotificationIntegrations. The only writer of
 * xs_booking_integrations.
 *
 * - Credentials are encrypted with the CI4 Encrypter. Without encryption.key
 *   nothing is saved (as the Partner Program does for bank details), so a
 *   missing key can never leave tokens in plain text. Decision T21: set the
 *   key on production before this is released.
 * - Secrets are write only: the settings screen shows that one is saved,
 *   never the value, and leaving the field empty keeps it.
 * - WhatsApp "link" sends nothing by itself: the team opens a prefilled chat
 *   from the appointment (wa.me). So `active()` is false for it, and
 *   `linkMode()` says when the button shows.
 * - Phase 9 adds the business's Zoom app and Jitsi server (typed in, so in
 *   PROVIDERS), and its Google Calendar tokens and webhook endpoint, which
 *   we make or receive rather than the business typing them: those are
 *   written with store() by Integrations\GoogleCalendar and
 *   Integrations\Webhooks.
 */
class BookingIntegrations
{
    /**
     * provider => field => [label, secret]
     *
     * @var array<string,array<string,array<string,array{0:string,1:bool}>>>
     */
    public const PROVIDERS = [
        'whatsapp' => [
            'link'   => [],
            'twilio' => ['account_sid' => ['Account SID', false], 'auth_token' => ['Auth token', true], 'from_number' => ['WhatsApp number on Twilio, like +27871234567', false]],
            'meta'   => [
                'phone_number_id' => ['Phone number ID', false],
                'access_token'    => ['Access token', true],
                'language'        => ['Template language code, like en', false],
                'template_pending' => ['Template name: request received', false],
                'template_confirmed' => ['Template name: confirmed', false],
                'template_rescheduled' => ['Template name: new time', false],
                'template_cancelled' => ['Template name: cancelled', false],
                'template_reminder' => ['Template name: reminder', false],
                'template_no_show' => ['Template name: no show', false],
            ],
        ],
        // The business's own merchant account. 'mode' is live or test (PayFast's sandbox);
        // a Paystack key says its own mode (sk_test_).
        'payment' => [
            'payfast'  => ['merchant_id' => ['Merchant ID', false], 'merchant_key' => ['Merchant key', false], 'passphrase' => ['Passphrase', true], 'mode' => ['Mode', false]],
            'paystack' => ['secret_key' => ['Secret key', true]],
        ],
        // The business's own Zoom app (Server to Server OAuth), as in Bookings.
        'zoom' => [
            'zoom' => ['account_id' => ['Account ID', false], 'client_id' => ['Client ID', false], 'client_secret' => ['Client secret', true]],
        ],
        // Empty server: meet.jit.si.
        'jitsi' => [
            'jitsi' => ['server_url' => ['Jitsi server address (leave empty for meet.jit.si)', false]],
        ],
    ];

    /** Channels written only by store(): what we make or receive, never typed in. */
    public const STORED = ['google', 'webhook'];

    public const PROVIDER_LABELS = [
        'payfast'    => 'PayFast',
        'paystack'   => 'Paystack',
        'twilio'     => 'Twilio',
        'link'       => 'Open a chat from the appointment (no account needed)',
        'meta'       => 'WhatsApp Business (Meta Cloud API)',
        'zoom'       => 'Zoom',
        'jitsi'      => 'Jitsi Meet',
        'google'     => 'Google Calendar',
        'custom'     => 'Your address',
    ];

    /** @var array<string,array<string,mixed>|null> */
    private array $rows = [];

    public function __construct(private int $listingId)
    {
    }

    /** Automatic sending is on for this channel. */
    public function active(string $channel): bool
    {
        $row = $this->row($channel);

        return $row !== null && (int) $row['is_active'] === 1 && $row['provider'] !== 'link';
    }

    /** WhatsApp is set to "open a chat from the appointment". */
    public function linkMode(): bool
    {
        $row = $this->row('whatsapp');

        return $row !== null && (int) $row['is_active'] === 1 && $row['provider'] === 'link';
    }

    /**
     * For the settings screen: provider, active, health and the fields, with
     * secrets shown only as saved or not.
     *
     * @return array{provider:string,active:bool,health:string,last_error:?string,last_tested_at:?string,values:array<string,string>,saved:array<string,bool>}
     */
    public function view(string $channel): array
    {
        $row    = $this->row($channel);
        $config = $row !== null ? $this->decrypt((string) ($row['config'] ?? '')) : [];
        $out    = [
            'provider'       => (string) ($row['provider'] ?? ''),
            'active'         => $row !== null && (int) $row['is_active'] === 1,
            'health'         => (string) ($row['health'] ?? 'unknown'),
            'last_error'     => $row['last_error'] ?? null,
            'last_tested_at' => $row['last_tested_at'] ?? null,
            'values'         => [],
            'saved'          => [],
        ];
        foreach (self::PROVIDERS[$channel] ?? [] as $provider => $fields) {
            foreach ($fields as $field => [, $secret]) {
                $value = $out['provider'] === $provider ? (string) ($config[$field] ?? '') : '';
                $out['values'][$provider . '.' . $field] = $secret ? '' : $value;
                $out['saved'][$provider . '.' . $field]  = $secret && $value !== '';
            }
        }

        return $out;
    }

    /**
     * Save a channel's account. An empty secret keeps the saved one.
     *
     * @param array<string,mixed> $input field => value for the chosen provider
     *
     * @return array<string,string> errors
     */
    public function save(string $channel, string $provider, array $input, bool $active, ?int $accountId): array
    {
        if (! isset(self::PROVIDERS[$channel])) {
            return ['provider' => 'Unknown channel.'];
        }
        if ($provider === '') {
            $this->remove($channel, $accountId);

            return [];
        }
        $fields = self::PROVIDERS[$channel][$provider] ?? null;
        if ($fields === null) {
            return ['provider' => 'Choose a provider.'];
        }

        $row    = $this->row($channel);
        $old    = $row !== null && $row['provider'] === $provider ? $this->decrypt((string) ($row['config'] ?? '')) : [];
        $config = [];
        $errors = [];
        foreach ($fields as $field => [$label, $secret]) {
            $value = trim(str_replace(["\r", "\n"], '', (string) ($input[$field] ?? '')));
            if ($value === '' && $secret) {
                $value = (string) ($old[$field] ?? '');
            }
            if (mb_strlen($value) > 500) {
                $errors[$field] = 'That is too long.';
            }
            $config[$field] = $value;
        }
        $errors += $this->check($channel, $provider, $config);
        if ($errors !== []) {
            return $errors;
        }

        if (! $this->write($channel, $provider, $fields !== [] ? $config : null, $active, $row === null || $row['provider'] !== $provider || $old != $config)) {
            return ['provider' => 'We cannot save account details right now. Please try again later.'];
        }
        (new BookingAudit())->record($this->listingId, $accountId, 'notifications.integration', 'settings', null, null, ['channel' => $channel, 'provider' => $provider, 'active' => $active]);

        return [];
    }

    /**
     * Save what we made or received for a channel in STORED (Google tokens,
     * a webhook endpoint and its secret). False when it cannot be encrypted.
     *
     * @param array<string,mixed> $config
     */
    public function store(string $channel, string $provider, array $config, bool $active, bool $resetHealth = true): bool
    {
        if (! in_array($channel, self::STORED, true)) {
            throw new \InvalidArgumentException('Use save() for ' . $channel);
        }

        return $this->write($channel, $provider, $config, $active, $resetHealth);
    }

    /** Switch a channel on or off without touching its account. */
    public function setActive(string $channel, bool $active): void
    {
        $row = $this->row($channel);
        if ($row !== null && (int) $row['is_active'] !== ($active ? 1 : 0)) {
            BookingIntegrationModel::forBusiness($this->listingId)->update((int) $row['id'], ['is_active' => $active ? 1 : 0]);
            unset($this->rows[$channel]);
        }
    }

    /** @param array<string,mixed>|null $config */
    private function write(string $channel, string $provider, ?array $config, bool $active, bool $resetHealth): bool
    {
        $sealed = null;
        if ($config !== null) {
            try {
                $sealed = base64_encode(service('encrypter')->encrypt(json_encode($config)));
            } catch (\Throwable $e) {
                log_message('error', 'Booking integration not saved, encryption unavailable: ' . $e->getMessage());

                return false;
            }
        }

        $row  = $this->row($channel);
        $data = ['provider' => $provider, 'config' => $sealed, 'is_active' => $active ? 1 : 0];
        if ($row === null) {
            BookingIntegrationModel::forBusiness($this->listingId)->insert($data + ['channel' => $channel, 'health' => 'unknown']);
        } else {
            if ($resetHealth) {
                $data += ['health' => 'unknown', 'last_error' => null, 'last_tested_at' => null];
            }
            BookingIntegrationModel::forBusiness($this->listingId)->update((int) $row['id'], $data);
        }
        unset($this->rows[$channel]);

        return true;
    }

    /** Whether account details can be saved at all (encryption.key is set). */
    public static function canEncrypt(): bool
    {
        try {
            service('encrypter')->encrypt('check');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function remove(string $channel, ?int $accountId): void
    {
        $row = $this->row($channel);
        if ($row !== null) {
            BookingIntegrationModel::forBusiness($this->listingId)->delete((int) $row['id']);
            unset($this->rows[$channel]);
            (new BookingAudit())->record($this->listingId, $accountId, 'notifications.integration_removed', 'settings', null, null, $channel);
        }
    }

    /**
     * The saved account: provider and decrypted fields, or null.
     *
     * @return array{provider:string,config:array<string,string>}|null
     */
    public function account(string $channel): ?array
    {
        $row = $this->row($channel);
        if ($row === null) {
            return null;
        }

        return ['provider' => (string) $row['provider'], 'config' => $this->decrypt((string) ($row['config'] ?? ''))];
    }

    /** Record how the last send or test went, for the settings screen. */
    public function recordHealth(string $channel, bool $ok, ?string $error, bool $tested = false): void
    {
        $row = $this->row($channel);
        if ($row === null) {
            return;
        }
        $data = ['health' => $ok ? 'ok' : 'failed', 'last_error' => $ok ? null : mb_substr((string) $error, 0, 255)];
        if ($tested) {
            $data['last_tested_at'] = gmdate('Y-m-d H:i:s');
        }
        BookingIntegrationModel::forBusiness($this->listingId)->update((int) $row['id'], $data);
        unset($this->rows[$channel]);
    }

    /** @return array<string,string> */
    private function check(string $channel, string $provider, array $c): array
    {
        $errors = [];
        if ($provider === 'payfast') {
            if (! preg_match('/^\d{5,12}$/', $c['merchant_id'])) {
                $errors['merchant_id'] = 'The merchant ID is the number on your PayFast dashboard.';
            }
            if (! preg_match('/^[A-Za-z0-9]{8,32}$/', $c['merchant_key'])) {
                $errors['merchant_key'] = 'The merchant key is the letters and digits on your PayFast dashboard.';
            }
            // PayFast lets an account go without one; we do not, since it is what
            // stops anyone else signing a payment notification for this account.
            if (mb_strlen($c['passphrase']) < 8) {
                $errors['passphrase'] = 'Set a passphrase of at least 8 characters under Settings, Developer settings in PayFast, and paste it here.';
            }
            if (! in_array($c['mode'], ['live', 'test'], true)) {
                $errors['mode'] = 'Choose live or test.';
            }
        }
        if ($provider === 'paystack' && ! preg_match('/^sk_(live|test)_[A-Za-z0-9]{20,}$/', $c['secret_key'])) {
            $errors['secret_key'] = 'Paste the secret key from Settings, API keys in Paystack. It starts with sk_live_ or sk_test_.';
        }
        if ($provider === 'zoom') {
            if (! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $c['account_id'])) {
                $errors['account_id'] = 'Paste the Account ID from your Zoom app, under App credentials.';
            }
            if (! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $c['client_id'])) {
                $errors['client_id'] = 'Paste the Client ID from your Zoom app, under App credentials.';
            }
            if ($c['client_secret'] === '') {
                $errors['client_secret'] = 'Paste the Client secret from your Zoom app.';
            }
        }
        if ($provider === 'jitsi' && $c['server_url'] !== '' && ($problem = \App\Services\Bookings\Integrations\SafeUrl::problem($c['server_url'])) !== null) {
            $errors['server_url'] = $problem;
        }
        $phone  = static fn (string $v): bool => (bool) preg_match('/^\+\d{7,15}$/', $v);
        if ($provider === 'twilio') {
            if (! preg_match('/^AC[0-9a-fA-F]{32}$/', $c['account_sid'])) {
                $errors['account_sid'] = 'A Twilio Account SID starts with AC and has 34 characters.';
            }
            if ($c['auth_token'] === '') {
                $errors['auth_token'] = 'Paste the auth token from Twilio.';
            }
            $from = preg_replace('/^whatsapp:/', '', $c['from_number']);
            if (! $phone((string) $from)) {
                $errors['from_number'] = 'Use the full number with the country code, like +27871234567.';
            }
        }
        if ($provider === 'meta') {
            if (! preg_match('/^\d{5,20}$/', $c['phone_number_id'])) {
                $errors['phone_number_id'] = 'The phone number ID is the number Meta shows for your WhatsApp number.';
            }
            if ($c['access_token'] === '') {
                $errors['access_token'] = 'Paste the access token from Meta.';
            }
            if ($c['language'] !== '' && ! preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $c['language'])) {
                $errors['language'] = 'A language code is like en or en_US.';
            }
            foreach ($c as $field => $value) {
                if (str_starts_with($field, 'template_') && $value !== '' && ! preg_match('/^[a-z0-9_]{1,512}$/', $value)) {
                    $errors[$field] = 'Template names use small letters, digits and underscores.';
                }
            }
        }

        return $errors;
    }

    /** @return array<string,mixed>|null */
    private function row(string $channel): ?array
    {
        if (! array_key_exists($channel, $this->rows)) {
            $row                  = BookingIntegrationModel::forBusiness($this->listingId)->where('channel', $channel)->first();
            $this->rows[$channel] = is_array($row) ? $row : null;
        }

        return $this->rows[$channel];
    }

    /** @return array<string,string> */
    private function decrypt(string $sealed): array
    {
        if ($sealed === '') {
            return [];
        }
        try {
            $data = json_decode(service('encrypter')->decrypt((string) base64_decode($sealed, true)), true);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            log_message('error', 'Booking integration could not be read: ' . $e->getMessage());

            return [];
        }
    }
}
