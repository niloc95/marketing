<?php

namespace App\Services\Bookings\Notifications;

use App\Libraries\Mailer;
use Config\BookingNotifications as Catalog;

/**
 * Sending one message on one channel. Ported from Bookings'
 * NotificationEmailService and NotificationWhatsAppService, keeping their provider calls:
 *
 * - Email always through Local's Mailer (SES, decision T8): From is ours with
 *   the business's name, Reply-To is the business, and customer emails carry
 *   List-Unsubscribe with RFC 8058 one click (Book::unsubscribe).
 * - WhatsApp: Twilio (Messages.json, whatsapp: numbers) or Meta Cloud API, which only sends
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
        $config = config('BookingNotifications');
        $split  = static fn (string $body): array => array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", $body) ?: []), static fn (string $p): bool => $p !== ''));

        if ($team) {
            // A notice for staff: from the platform, Reply goes to the customer,
            // their contact details and links into /business. Not the customer's email.
            $t = $config->teamTemplates[$event] ?? null;
            if ($t === null) {
                return ['ok' => false, 'error' => 'No team message for this event.'];
            }
            $filled  = NotificationTemplates::fill(['subject' => $t['subject'], 'body' => $t['body']], $values);
            $contact = $context['customer_contact'];
            $body    = view('emails/booking-team', [
                'business'   => $values['{business}'],
                'heading'    => $config->teamHeadings[$event] ?? $t['subject'],
                'paragraphs' => $split($filled['body']),
                'contact'    => $contact,
                'details'    => $context['details'],
                'place'      => $context['place'],
                'answers'    => $context['answers'],
                'view_url'   => $context['view_url'],
                'customer_page_url' => $context['customer_page_url'],
                'booked'     => $context['booked'],
                'footer'     => 'You get these emails because you are on the team of ' . $values['{business}'] . ' on WebScheduler Local. You can turn them off under Notices.',
            ], ['saveData' => false]);
            $replyTo = filter_var($contact['email'], FILTER_VALIDATE_EMAIL) !== false ? $contact['email'] : '';
            $ok      = (new Mailer())->send($to, $filled['subject'], $body, $replyTo, ['fromName' => 'WebScheduler Local']);

            return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'The mail server did not accept the email.'];
        }

        $showAnswers = str_contains($template['body'], '{answers}');
        $filled      = NotificationTemplates::fill($template, ['{answers}' => ''] + $values);
        $upcoming    = in_array($event, ['pending', 'confirmed', 'rescheduled', 'reminder'], true);
        $button      = $context['manage_url'] !== '' && ! in_array($event, ['cancelled', 'no_show'], true)
            ? ['Change or cancel your booking', $context['manage_url']]
            : ['Book again', $context['booking_url']];
        $unsub       = $context['customer'] !== null
            ? listing_site_url($listing, 'book/unsubscribe/' . $context['customer']['hash'])
            : '';
        $headers     = $unsub !== '' ? ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'] : [];
        $replyTo     = filter_var($context['reply_to'], FILTER_VALIDATE_EMAIL) !== false ? $context['reply_to'] : '';

        $body = view('emails/booking-notification', [
            'business'      => $values['{business}'],
            'heading'       => $config->customerHeadings[$event] ?? '',
            'paragraphs'    => $split($filled['body']),
            'details'       => $context['details'],
            'place'         => $upcoming ? $context['place'] : null,
            'hours'         => $upcoming ? $context['hours'] : [],
            'timezone_note' => $context['timezone_note'],
            'enquiries'     => $context['enquiries'],
            'answers'       => $showAnswers ? $context['answers'] : [],
            'button'        => $button,
            'calendar_link' => $upcoming ? $context['calendar_link'] : '',
            'policy'        => $upcoming ? trim($values['{cancellation_policy}']) : '',
            'terms_url'     => $context['terms_url'],
            'privacy_url'   => $context['privacy_url'],
            'unsubscribe'   => $unsub,
            'footer'        => 'Sent by WebScheduler Local for ' . $values['{business}'] . '. Reply to this email to reach them.',
        ], ['saveData' => false]);

        $ok = (new Mailer())->send($to, $filled['subject'], $body, $replyTo, ['fromName' => $context['from_name'], 'headers' => $headers]);

        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'The mail server did not accept the email.'];
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
