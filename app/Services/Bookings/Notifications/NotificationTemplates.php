<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingTemplateModel;
use App\Services\Bookings\BookingAudit;
use Config\BookingNotifications as Catalog;

/**
 * The wording of customer messages: the business's own, or the default in
 * Config\BookingNotifications. Ported from Bookings' NotificationTemplateService
 * (37 placeholders there; the 17 here are the ones Local can fill, see
 * Catalog::PLACEHOLDERS). The only writer of xs_booking_templates.
 *
 * Templates are plain text. Values go in as text and the email escapes the
 * whole result, so a customer's name can never become markup; nothing here
 * produces HTML. Team messages are not editable (Catalog::$teamTemplates).
 */
class NotificationTemplates
{
    public function __construct(private int $listingId, private ?Catalog $catalog = null)
    {
        $this->catalog ??= config('BookingNotifications');
    }

    /**
     * The template in use, and whether it is the business's own.
     *
     * @return array{subject:string,body:string,custom:bool}|null
     */
    public function get(string $event, string $channel): ?array
    {
        $default = $this->catalog->customerTemplates[$event][$channel] ?? null;
        if ($default === null) {
            return null;
        }
        $row = BookingTemplateModel::forBusiness($this->listingId)
            ->where(['event' => $event, 'channel' => $channel, 'recipient' => Catalog::CUSTOMER])->first();
        if (is_array($row)) {
            return ['subject' => (string) ($row['subject'] ?? ''), 'body' => (string) $row['body'], 'custom' => true];
        }

        return ['subject' => $default['subject'] ?? '', 'body' => $default['body'], 'custom' => false];
    }

    /**
     * Save the business's own wording. Saving the default text again, or an
     * empty body, goes back to the default.
     *
     * @return array<string,string> errors, field => message
     */
    public function save(string $event, string $channel, string $subject, string $body, ?int $accountId): array
    {
        $default = $this->catalog->customerTemplates[$event][$channel] ?? null;
        if ($default === null) {
            return ['body' => 'Unknown message.'];
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $body    = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' || ($body === $default['body'] && ($channel !== 'email' || $subject === ($default['subject'] ?? '')))) {
            $this->reset($event, $channel, $accountId);

            return [];
        }

        $errors = [];
        $max    = $channel === 'email' ? Catalog::BODY_MAX : Catalog::SMS_MAX;
        if (mb_strlen($body) > $max) {
            $errors['body'] = 'Keep it to ' . $max . ' characters.';
        }
        if ($channel === 'email') {
            if ($subject === '') {
                $errors['subject'] = 'Give the email a subject.';
            } elseif (mb_strlen($subject) > Catalog::SUBJECT_MAX) {
                $errors['subject'] = 'Keep the subject to ' . Catalog::SUBJECT_MAX . ' characters.';
            }
        }
        $unknown = self::unknownPlaceholders($subject . ' ' . $body);
        if ($unknown !== []) {
            $errors['body'] = implode(', ', $unknown) . (count($unknown) === 1 ? ' is not' : ' are not') . ' one of the placeholders listed.';
        } elseif ($channel !== 'email' && str_contains($body, '{answers}')) {
            $errors['body'] = '{answers} can only go in an email.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $model = BookingTemplateModel::forBusiness($this->listingId);
        $row   = $model->where(['event' => $event, 'channel' => $channel, 'recipient' => Catalog::CUSTOMER])->first();
        $data  = ['subject' => $channel === 'email' ? $subject : null, 'body' => $body, 'updated_by' => $accountId];
        if (is_array($row)) {
            BookingTemplateModel::forBusiness($this->listingId)->update((int) $row['id'], $data);
        } else {
            BookingTemplateModel::forBusiness($this->listingId)->insert($data + ['event' => $event, 'channel' => $channel, 'recipient' => Catalog::CUSTOMER]);
        }
        (new BookingAudit())->record($this->listingId, $accountId, 'notifications.template', 'settings', null, null, $event . '.' . $channel);

        return [];
    }

    public function reset(string $event, string $channel, ?int $accountId): void
    {
        $model = BookingTemplateModel::forBusiness($this->listingId);
        $row   = $model->where(['event' => $event, 'channel' => $channel, 'recipient' => Catalog::CUSTOMER])->first();
        if (is_array($row)) {
            BookingTemplateModel::forBusiness($this->listingId)->delete((int) $row['id']);
            (new BookingAudit())->record($this->listingId, $accountId, 'notifications.template_reset', 'settings', null, null, $event . '.' . $channel);
        }
    }

    /**
     * Fill a template. {answers} becomes its own lines; anything unknown is
     * left as typed (save() refuses unknown ones, so only old rows have any).
     *
     * @param array<string,string> $values Catalog::PLACEHOLDERS key => value
     *
     * @return array{subject:string,body:string}
     */
    public static function fill(array $template, array $values): array
    {
        $subjectValues = $values;
        $subjectValues['{answers}'] = '';

        $body = strtr($template['body'], $values);
        // A blank {answers} leaves an empty paragraph behind.
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $body));

        return [
            'subject' => trim((string) preg_replace('/\s+/', ' ', strtr($template['subject'] ?? '', $subjectValues))),
            'body'    => $body,
        ];
    }

    /** @return list<string> */
    public static function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{[a-z_]+\}/', $text, $m);

        return array_values(array_diff(array_unique($m[0]), array_keys(Catalog::PLACEHOLDERS)));
    }
}
