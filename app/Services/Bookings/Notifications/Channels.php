<?php

namespace App\Services\Bookings\Notifications;

use App\Libraries\Mailer;
use Config\BookingNotifications as Catalog;

/**
 * Sending one message on one channel. Ported from Bookings'
 * NotificationEmailService, NotificationSmsService and
 * NotificationWhatsAppService, keeping their provider calls:
 *
 * - Email always through Local's Mailer (SES, decision T8): From is ours with
 *   the business's name, Reply-To is the business, and customer emails carry
 *   List-Unsubscribe with RFC 8058 one click (Book::unsubscribe).
 * - SMS: Clickatell (platform.clickatell.com/v1/message) or Twilio
 *   (Messages.json), with the business's own account.
 * - WhatsApp: Twilio (whatsapp: numbers) or Meta Cloud API, which only sends
 *   the business's approved templates, so its parameters are fixed:
 *   1 first name, 2 business, 3 service, 4 when, 5 link.
 *
 * Called by NotificationDispatcher from cron, and by the settings test button.
 * Provider HTTP goes through service('curlrequest') so tests can fake it.
 * Every method answers ['ok' => bool, 'error' => ?string] and never throws.
 */
class Channels
{
    /**
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $context MessageContext::build()
     * @param array{subject:string,body:string} $template customer template, or null for the team
     */
    public static function email(array $listing, array $context, string $event, string $to, ?array $template, bool $team): array
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'No valid email address.'];
        }
        $values = $context['values'];
        if ($team) {
            $t        = config('BookingNotifications')->teamTemplates[$event] ?? null;
            if ($t === null) {
                return ['ok' => false, 'error' => 'No team message for this event.'];
            }
            $filled   = NotificationTemplates::fill(['subject' => $t['subject'], 'body' => $t['body']], $values);
            $button   = ['Open the calendar', $context['calendar_url']];
            $answers  = $context['answers'];
            $footer   = 'You get these emails because you are on the team of ' . $values['{business}'] . ' on WebScheduler Local. You can turn them off under Notices.';
            $headers  = [];
            $replyTo  = '';
            $policy   = '';
            $unsub    = '';
        } else {
            $showAnswers = str_contains($template['body'], '{answers}');
            $filled      = NotificationTemplates::fill($template, ['{answers}' => ''] + $values);
            $button      = $context['manage_url'] !== '' && ! in_array($event, ['cancelled', 'no_show'], true)
                ? ['Change or cancel your booking', $context['manage_url']]
                : ['Book again', $context['booking_url']];
            $answers     = $showAnswers ? $context['answers'] : [];
            $footer      = 'Sent by WebScheduler Local for ' . $values['{business}'] . '. Reply to this email to reach them.';
            $unsub       = $context['customer'] !== null
                ? listing_site_url($listing, 'book/unsubscribe/' . $context['customer']['hash'])
                : '';
            $headers     = $unsub !== '' ? ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'] : [];
            $replyTo     = filter_var($context['reply_to'], FILTER_VALIDATE_EMAIL) !== false ? $context['reply_to'] : '';
            $policy      = in_array($event, ['pending', 'confirmed', 'rescheduled', 'reminder'], true) ? trim($values['{cancellation_policy}']) : '';
        }

        $paragraphs = array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", $filled['body']) ?: []), static fn (string $p): bool => $p !== ''));
        $body       = view('emails/booking-notification', [
            'business'    => $values['{business}'],
            'paragraphs'  => $paragraphs,
            'details'     => $context['details'],
            'answers'     => $answers,
            'button'      => $button,
            'policy'      => $policy,
            'unsubscribe' => $unsub,
            'footer'      => $footer,
        ], ['saveData' => false]);

        $ok = (new Mailer())->send($to, $filled['subject'], $body, $replyTo, ['fromName' => $context['from_name'], 'headers' => $headers]);

        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'The mail server did not accept the email.'];
    }

    /** @param array{provider:string,config:array<string,string>} $account */
    public static function sms(array $account, string $to, string $text): array
    {
        $text = mb_substr($text, 0, Catalog::SMS_MAX);
        $c    = $account['config'];

        return match ($account['provider']) {
            'clickatell' => self::post(
                'https://platform.clickatell.com/v1/message',
                ['Authorization' => (string) ($c['api_key'] ?? ''), 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                ['body' => json_encode(['messages' => [array_filter([
                    'channel' => 'sms', 'to' => ltrim($to, '+'), 'content' => $text, 'from' => (string) ($c['from'] ?? ''),
                ], static fn ($v) => $v !== '')]])],
                'Clickatell',
            ),
            'twilio' => self::twilio($c, $to, (string) ($c['from_number'] ?? ''), $text),
            default  => ['ok' => false, 'error' => 'No SMS provider set.'],
        };
    }

    /**
     * @param array{provider:string,config:array<string,string>} $account
     * @param array<string,string>                                 $values MessageContext values
     */
    public static function whatsapp(array $account, string $event, string $to, string $text, array $values): array
    {
        $c = $account['config'];
        if ($account['provider'] === 'twilio') {
            $from = (string) ($c['from_number'] ?? '');

            return self::twilio($c, 'whatsapp:' . $to, str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:' . $from, $text);
        }
        if ($account['provider'] === 'meta') {
            $template = trim((string) ($c['template_' . $event] ?? ''));
            if ($template === '') {
                return ['ok' => false, 'error' => 'No WhatsApp template name saved for this message.'];
            }
            $params = array_map(static fn (string $v): array => ['type' => 'text', 'text' => $v !== '' ? $v : '-'], [
                $values['{customer_first_name}'], $values['{business}'], $values['{service}'], $values['{when}'],
                $values['{manage_link}'] !== '' ? $values['{manage_link}'] : $values['{booking_link}'],
            ]);

            return self::post(
                'https://graph.facebook.com/v20.0/' . rawurlencode((string) ($c['phone_number_id'] ?? '')) . '/messages',
                ['Authorization' => 'Bearer ' . ($c['access_token'] ?? ''), 'Content-Type' => 'application/json'],
                ['body' => json_encode([
                    'messaging_product' => 'whatsapp',
                    'to'                => ltrim($to, '+'),
                    'type'              => 'template',
                    'template'          => [
                        'name'       => $template,
                        'language'   => ['code' => ($c['language'] ?? '') !== '' ? $c['language'] : 'en'],
                        'components' => [['type' => 'body', 'parameters' => $params]],
                    ],
                ])],
                'WhatsApp',
            );
        }

        return ['ok' => false, 'error' => 'WhatsApp is not set to send by itself.'];
    }

    /**
     * A customer's number in international form (+27821234567), from what
     * they typed and the business's country; null when it is not a number.
     */
    public static function e164(?string $phone, ?string $countryCode): ?string
    {
        helper('directory_ui');
        $dial   = (new \Config\Countries())->profile($countryCode ?: 'ZA')['dialCode'] ?? null;
        $digits = whatsapp_digits((string) $phone, $dial);

        return is_string($digits) && preg_match('/^\d{8,15}$/', $digits) ? '+' . $digits : null;
    }

    /** A wa.me link with the message filled in, for "open a chat" mode. */
    public static function whatsappLink(string $to, string $text): string
    {
        return 'https://wa.me/' . ltrim($to, '+') . '?text=' . rawurlencode($text);
    }

    /** @param array<string,string> $c */
    private static function twilio(array $c, string $to, string $from, string $text): array
    {
        $sid = (string) ($c['account_sid'] ?? '');

        return self::post(
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json',
            [],
            ['auth' => [$sid, (string) ($c['auth_token'] ?? '')], 'form_params' => ['To' => $to, 'From' => $from, 'Body' => $text]],
            'Twilio',
        );
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $options
     */
    private static function post(string $url, array $headers, array $options, string $who): array
    {
        try {
            $response = service('curlrequest')->post($url, $options + [
                'headers'     => $headers,
                'timeout'     => 15,
                'http_errors' => false,
            ]);
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return ['ok' => true, 'error' => null];
            }
            $json   = json_decode((string) $response->getBody(), true);
            $reason = '';
            foreach ([$json['message'] ?? null, $json['error']['message'] ?? null, $json['error'] ?? null] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $reason = $candidate;

                    break;
                }
            }

            return ['ok' => false, 'error' => $who . ' answered ' . $code . ($reason !== '' ? ': ' . mb_substr(Mailer::oneLine($reason), 0, 160) : '.')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $who . ' could not be reached: ' . mb_substr(Mailer::oneLine($e->getMessage()), 0, 160)];
        }
    }
}
