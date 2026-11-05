<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\Notifications\BookingIntegrations;
use App\Services\Bookings\Notifications\Channels;
use App\Services\Bookings\Notifications\NotificationRules;
use App\Services\Bookings\Notifications\NotificationTemplates;
use Config\BookingNotifications as Catalog;

/**
 * Settings, Messages tab (/business/settings/notifications): which messages
 * go out, reminders, the sender, the wording, and the SMS and WhatsApp
 * accounts. Admins only (settings.manage). Ported from Bookings' Settings
 * notifications tab (NotificationSettingsService).
 */
class NotificationSettings extends BaseController
{
    private const BACK = 'business/settings/notifications';

    /** @return array<string,mixed> what the tab shows */
    public static function viewData(int $listingId, string $edit): array
    {
        $templates = new NotificationTemplates($listingId);
        $all       = [];
        foreach (array_keys(Catalog::EVENTS) as $event) {
            foreach (Catalog::CHANNELS as $channel) {
                $all[$event][$channel] = $templates->get($event, $channel);
            }
        }
        [$editEvent, $editChannel] = array_pad(explode('.', $edit, 2), 2, '');
        $integrations = new BookingIntegrations($listingId);

        return [
            'rules'        => (new NotificationRules($listingId))->matrix(),
            'templates'    => $all,
            'edit'         => isset($all[$editEvent][$editChannel]) ? [$editEvent, $editChannel] : null,
            'sms'          => $integrations->view('sms'),
            'whatsapp'     => $integrations->view('whatsapp'),
            'canEncrypt'   => self::canEncrypt(),
        ];
    }

    public function save()
    {
        $context = service('businessContext');
        $post    = $this->request->getPost();
        (new NotificationRules($context->listingId()))->save((array) ($post['customer'] ?? []), (array) ($post['team'] ?? []), $context->accountId());

        $result = BookingSettings::for($context->listingId())->save([
            'notifications.reminder_primary'   => (string) ((int) ($post['reminder_primary'] ?? 0)),
            'notifications.reminder_secondary' => (string) ((int) ($post['reminder_secondary'] ?? 0)),
            'notifications.from_name'          => trim(strip_tags((string) ($post['from_name'] ?? ''))),
            'notifications.reply_to'           => trim((string) ($post['reply_to'] ?? '')),
        ], $context->accountId());
        if ($result['errors'] !== []) {
            return redirect()->to(base_url(self::BACK))->with('error', implode(' ', array_unique($result['errors'])));
        }
        if ($result['changed'] !== []) {
            (new BookingAudit())->record($context->listingId(), $context->accountId(), 'settings.saved', 'settings', null,
                array_map(static fn (array $c): string => $c[0], $result['changed']),
                array_map(static fn (array $c): string => $c[1], $result['changed']));
        }

        return redirect()->to(base_url(self::BACK))->with('success', 'Saved.');
    }

    public function saveTemplate()
    {
        $context = service('businessContext');
        $event   = (string) $this->request->getPost('event');
        $channel = (string) $this->request->getPost('channel');
        $back    = base_url(self::BACK . '?edit=' . rawurlencode($event . '.' . $channel) . '#wording');
        $service = new NotificationTemplates($context->listingId());
        if ($this->request->getPost('reset') !== null) {
            $service->reset($event, $channel, $context->accountId());

            return redirect()->to($back)->with('success', 'Back to the standard wording.');
        }
        $errors = $service->save($event, $channel, (string) $this->request->getPost('subject'), (string) $this->request->getPost('body'), $context->accountId());
        if ($errors !== []) {
            return redirect()->to($back)->withInput()->with('error', implode(' ', $errors));
        }

        return redirect()->to($back)->with('success', 'Saved.');
    }

    public function saveIntegration(string $channel)
    {
        $context  = service('businessContext');
        $provider = (string) $this->request->getPost('provider');
        $fields   = (array) ($this->request->getPost('cfg')[$provider] ?? []);
        $errors   = (new BookingIntegrations($context->listingId()))->save(
            $channel,
            $provider,
            $fields,
            $this->request->getPost('active') !== null,
            $context->accountId(),
        );
        if ($errors !== []) {
            return redirect()->to(base_url(self::BACK . '#' . $channel))->with('error', implode(' ', $errors));
        }

        return redirect()->to(base_url(self::BACK . '#' . $channel))->with('success', $provider === '' ? 'Removed.' : 'Saved.');
    }

    /**
     * Send one test message to a number the admin types, now. The one send
     * inside a request: the admin asked for it and waits to see the answer.
     */
    public function test(string $channel)
    {
        $context      = service('businessContext');
        $listing      = $context->listing();
        $integrations = new BookingIntegrations($context->listingId());
        $account      = $integrations->account($channel);
        $to           = Channels::e164((string) $this->request->getPost('phone'), (string) ($listing['country_code'] ?? 'ZA'));
        $back         = base_url(self::BACK . '#' . $channel);
        if ($account === null || $account['provider'] === 'link') {
            return redirect()->to($back)->with('error', 'Save an account first.');
        }
        if ($to === null) {
            return redirect()->to($back)->with('error', 'Type a mobile number to send the test to.');
        }
        if (! service('throttler')->check('biz-test-' . $context->listingId(), 10, HOUR)) {
            return redirect()->to($back)->with('error', 'That is a lot of tests. Please try again in an hour.');
        }
        $text   = 'Test from ' . $listing['display_name'] . ' on WebScheduler Local: your ' . Catalog::CHANNEL_LABELS[$channel] . ' messages work.';
        $values = ['{customer_first_name}' => 'Test', '{business}' => (string) $listing['display_name'], '{service}' => 'Test', '{when}' => 'now', '{manage_link}' => '', '{booking_link}' => listing_site_url($listing, 'directory/' . $listing['slug'] . '/book')];
        $result = $channel === 'sms' ? Channels::sms($account, $to, $text) : Channels::whatsapp($account, 'confirmed', $to, $text, $values);
        $integrations->recordHealth($channel, $result['ok'], $result['error'], true);

        return redirect()->to($back)->with($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Test sent. Check the phone.' : 'Not sent: ' . $result['error']);
    }

    private static function canEncrypt(): bool
    {
        try {
            service('encrypter')->encrypt('check');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
