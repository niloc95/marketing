<?php

namespace App\Services;

/**
 * Amazon SES bounce and complaint notices, delivered by Amazon SNS to
 * POST /hooks/ses (owner, 27 Nov 2026). A permanent bounce or a spam
 * complaint opts that address out of every campaign
 * (MarketingConsentService::suppress), so a dead or unwilling address is
 * never mailed again and our sending reputation is kept.
 *
 * Trust: off until `ses.snsTopicArn` is set in .env. A message counts only
 * when its TopicArn is ours and its signature checks out against the signing
 * certificate, which must come over https from an sns.<region>.amazonaws.com
 * host. A subscription confirmation is accepted the same way and confirmed
 * by fetching its SubscribeURL (same host rule). Everything else is ignored.
 *
 * Setup (owner, at release): an SNS topic, SES identity `mail.` notifications
 * for Bounce and Complaint to it, an HTTPS subscription to
 * https://local.webscheduler.co.za/hooks/ses, and the topic ARN in .env.
 */
class SesNotificationService
{
    /** @return 'off'|'refused'|'confirmed'|'handled'|'ignored' */
    public function handle(string $body): string
    {
        $topic = trim((string) env('ses.snsTopicArn', ''));
        if ($topic === '') {
            return 'off';
        }

        $msg = json_decode($body, true);
        if (! is_array($msg) || ($msg['TopicArn'] ?? '') !== $topic || ! $this->verify($msg)) {
            return 'refused';
        }

        return match ($msg['Type'] ?? '') {
            'SubscriptionConfirmation' => $this->confirm((string) ($msg['SubscribeURL'] ?? '')) ? 'confirmed' : 'refused',
            'Notification'             => $this->notification((string) ($msg['Message'] ?? '')),
            default                    => 'ignored',
        };
    }

    /** Whether the SNS signature is good (SignatureVersion 1 = SHA1, 2 = SHA256). */
    public function verify(array $msg): bool
    {
        $certUrl = (string) ($msg['SigningCertURL'] ?? '');
        $sig     = base64_decode((string) ($msg['Signature'] ?? ''), true);
        $algo    = match ((string) ($msg['SignatureVersion'] ?? '')) {
            '1'     => OPENSSL_ALGO_SHA1,
            '2'     => OPENSSL_ALGO_SHA256,
            default => null,
        };
        if ($algo === null || $sig === false || ! self::isAmazonUrl($certUrl, true)) {
            return false;
        }

        $keys = match ($msg['Type'] ?? '') {
            'Notification'             => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation',
            'UnsubscribeConfirmation'  => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default                    => null,
        };
        if ($keys === null) {
            return false;
        }
        $text = '';
        foreach ($keys as $k) {
            if ($k === 'Subject' && ! isset($msg[$k])) {
                continue;
            }
            $text .= $k . "\n" . (string) ($msg[$k] ?? '') . "\n";
        }

        $pem = $this->certificate($certUrl);
        if ($pem === null) {
            return false;
        }
        $key = openssl_pkey_get_public($pem);

        return $key !== false && openssl_verify($text, $sig, $key, $algo) === 1;
    }

    /** https://sns.<region>.amazonaws.com/..., and a .pem path for the certificate. */
    public static function isAmazonUrl(string $url, bool $pem = false): bool
    {
        $p = parse_url($url);

        return is_array($p)
            && ($p['scheme'] ?? '') === 'https'
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', (string) ($p['host'] ?? '')) === 1
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['port'])
            && (! $pem || str_ends_with((string) ($p['path'] ?? ''), '.pem'));
    }

    /** The signing certificate, cached a day. Overridden in tests. */
    protected function certificate(string $url): ?string
    {
        $key = 'ses-cert-' . md5($url);
        $pem = cache($key);
        if (is_string($pem) && $pem !== '') {
            return $pem;
        }
        $pem = $this->fetch($url);
        if ($pem === null || ! str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }
        cache()->save($key, $pem, DAY);

        return $pem;
    }

    /** One GET to an Amazon URL. Overridden in tests. */
    protected function fetch(string $url): ?string
    {
        try {
            $res = service('curlrequest', ['timeout' => 5, 'http_errors' => false])->get($url);

            return $res->getStatusCode() === 200 ? (string) $res->getBody() : null;
        } catch (\Throwable $e) {
            log_message('error', 'SES hook could not fetch {url}: {m}', ['url' => parse_url($url, PHP_URL_HOST), 'm' => $e->getMessage()]);

            return null;
        }
    }

    private function confirm(string $url): bool
    {
        return self::isAmazonUrl($url) && $this->fetch($url) !== null;
    }

    /** @return 'handled'|'ignored' */
    private function notification(string $json): string
    {
        $n = json_decode($json, true);
        if (! is_array($n)) {
            return 'ignored';
        }
        $type   = (string) ($n['notificationType'] ?? $n['eventType'] ?? '');
        $emails = [];
        $source = '';
        if ($type === 'Bounce' && ($n['bounce']['bounceType'] ?? '') === 'Permanent') {
            $emails = array_column($n['bounce']['bouncedRecipients'] ?? [], 'emailAddress');
            $source = 'bounce';
        } elseif ($type === 'Complaint') {
            $emails = array_column($n['complaint']['complainedRecipients'] ?? [], 'emailAddress');
            $source = 'complaint';
        }
        if ($emails === []) {
            return 'ignored';
        }

        $consent = new MarketingConsentService();
        $changed = 0;
        foreach ($emails as $email) {
            $changed += $consent->suppress((string) $email, $source);
        }
        log_message('info', '[ses-hook] {type}: {n} profiles opted out', ['type' => $source, 'n' => $changed]);

        return 'handled';
    }
}
