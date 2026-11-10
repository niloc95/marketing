<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Models\Bookings\BookingOutboxModel;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Integrations\GoogleCalendar;
use App\Services\Bookings\Integrations\Outbox;
use App\Services\Bookings\Integrations\Webhooks;
use App\Services\Bookings\Integrations\Zoom;
use App\Services\Bookings\Notifications\BookingIntegrations;

/**
 * Settings, Connections tab (/business/settings/connections, Phase 9):
 * online meetings (Zoom, Jitsi), Google Calendar and the webhook. Admins
 * only (settings.manage). Everything a connection sends goes out from
 * bookings:dispatch, except the two buttons an admin presses and waits for:
 * "Check" and "Send a test".
 */
class ConnectionSettings extends BaseController
{
    private const BACK = 'business/settings/connections';

    /** Session key for the Google sign in in progress: state, business, time. */
    private const GOOGLE_STATE = 'bookings_google_state';

    /** @return array<string,mixed> what the tab shows */
    public static function viewData(array $listing): array
    {
        $listingId    = (int) $listing['id'];
        $integrations = new BookingIntegrations($listingId);
        $webhooks     = new Webhooks($listingId);
        $online       = [];
        foreach ((new CatalogueService($listingId))->services() as $s) {
            foreach (['online_zoom', 'online_jitsi'] as $mode) {
                if (in_array($mode, (array) $s['delivery_modes'], true)) {
                    $online[$mode][] = (string) $s['name'];
                }
            }
        }

        return [
            'canEncrypt'     => BookingIntegrations::canEncrypt(),
            'zoom'           => $integrations->view('zoom'),
            'jitsi'          => $integrations->view('jitsi'),
            'online'         => $online,
            'googleOffered'  => GoogleCalendar::offered(),
            'google'         => (new GoogleCalendar($listing))->status(),
            'webhook'        => $webhooks->settings(),
            'secret'         => $webhooks->secret(),
            'deliveries'     => $webhooks->deliveries(20),
            'events'         => Webhooks::EVENTS,
            'timezone'       => service('businessContext')->timezone(),
        ];
    }

    /** Zoom's app details, or a Jitsi server. */
    public function saveVideo(string $channel)
    {
        $context = service('businessContext');
        $remove  = $this->request->getPost('remove') !== null;
        $errors  = (new BookingIntegrations($context->listingId()))->save(
            $channel,
            $remove ? '' : $channel,
            (array) ($this->request->getPost('cfg') ?? []),
            $this->request->getPost('active') !== null || $channel === 'jitsi',
            $context->accountId(),
        );
        if ($errors !== []) {
            return redirect()->to(base_url(self::BACK . '#' . $channel))->with('error', implode(' ', $errors));
        }
        if ($remove && $channel === 'zoom') {
            Outbox::forListing($context->listingId())?->dropQueued(BookingOutboxModel::ZOOM);
        }

        return redirect()->to(base_url(self::BACK . '#' . $channel))->with('success', $remove ? 'Removed.' : 'Saved.');
    }

    public function checkZoom()
    {
        $context      = service('businessContext');
        $integrations = new BookingIntegrations($context->listingId());
        $account      = $integrations->account('zoom');
        if ($account === null) {
            return redirect()->to(base_url(self::BACK . '#zoom'))->with('error', 'Save your Zoom app details first.');
        }
        $r = (new Zoom($account['config']))->check();
        $integrations->recordHealth('zoom', $r['ok'], $r['error'], true);

        return redirect()->to(base_url(self::BACK . '#zoom'))->with(
            $r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'Zoom accepted your details' . ($r['email'] ? ' (' . $r['email'] . ')' : '') . '.' : (string) $r['error'],
        );
    }

    /** Off to Google to say yes. */
    public function connectGoogle()
    {
        if (! GoogleCalendar::offered()) {
            return redirect()->to(base_url(self::BACK))->with('error', 'Google Calendar is not available yet.');
        }
        if (! BookingIntegrations::canEncrypt()) {
            return redirect()->to(base_url(self::BACK))->with('error', 'Connections are not available yet.');
        }
        $context = service('businessContext');
        $state   = bin2hex(random_bytes(16));
        session()->set(self::GOOGLE_STATE, ['state' => $state, 'listing' => $context->listingId(), 'at' => time()]);

        return redirect()->to((new GoogleCalendar($context->listing()))->authUrl($state));
    }

    /** Google sends the admin back here with a code (or a no). */
    public function googleCallback()
    {
        $context = service('businessContext');
        $saved   = session()->get(self::GOOGLE_STATE);
        session()->remove(self::GOOGLE_STATE);
        $state = (string) $this->request->getGet('state');
        if (! is_array($saved) || ! hash_equals((string) $saved['state'], $state)
            || (int) $saved['listing'] !== $context->listingId() || time() - (int) $saved['at'] > 900) {
            return redirect()->to(base_url(self::BACK . '#google'))->with('error', 'That Google sign in had expired. Please connect again.');
        }
        if ($this->request->getGet('error') !== null || (string) $this->request->getGet('code') === '') {
            return redirect()->to(base_url(self::BACK . '#google'))->with('error', 'Google Calendar was not connected.');
        }

        $error = (new GoogleCalendar($context->listing()))->connect((string) $this->request->getGet('code'), $context->accountId());

        return redirect()->to(base_url(self::BACK . '#google'))->with(
            $error === null ? 'success' : 'error',
            $error ?? 'Google Calendar is connected. Your upcoming appointments are being added now.',
        );
    }

    public function checkGoogle()
    {
        $r = (new GoogleCalendar(service('businessContext')->listing()))->check();

        return redirect()->to(base_url(self::BACK . '#google'))->with($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Google Calendar answers.' : (string) $r['error']);
    }

    public function disconnectGoogle()
    {
        $context = service('businessContext');
        (new GoogleCalendar($context->listing()))->disconnect($context->accountId());

        return redirect()->to(base_url(self::BACK . '#google'))->with('success', 'Google Calendar is disconnected. Events already in it stay there.');
    }

    public function saveWebhook()
    {
        $context = service('businessContext');
        $errors  = (new Webhooks($context->listingId()))->save(
            (string) $this->request->getPost('url'),
            array_map('strval', (array) ($this->request->getPost('events') ?? [])),
            $this->request->getPost('active') !== null,
            $context->accountId(),
        );
        if ($errors !== []) {
            return redirect()->to(base_url(self::BACK . '#webhook'))->with('error', implode(' ', $errors));
        }

        return redirect()->to(base_url(self::BACK . '#webhook'))->with('success', 'Saved.');
    }

    public function testWebhook()
    {
        $r = (new Webhooks(service('businessContext')->listingId()))->test();

        return redirect()->to(base_url(self::BACK . '#webhook'))->with(
            $r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'Your address answered the test.' : 'The test did not go through: ' . $r['error'],
        );
    }

    public function newSecret()
    {
        $context = service('businessContext');
        $ok      = (new Webhooks($context->listingId()))->newSecret($context->accountId());

        return redirect()->to(base_url(self::BACK . '#webhook'))->with($ok ? 'success' : 'error', $ok ? 'New secret made. The old one no longer signs anything.' : 'Save an address first.');
    }

    public function removeWebhook()
    {
        $context = service('businessContext');
        (new Webhooks($context->listingId()))->remove($context->accountId());

        return redirect()->to(base_url(self::BACK . '#webhook'))->with('success', 'Removed.');
    }

    public function retry(int $id)
    {
        $ok = Outbox::forListing(service('businessContext')->listingId())?->retry($id) ?? false;

        return redirect()->to(base_url(self::BACK . '#deliveries'))->with($ok ? 'success' : 'error', $ok ? 'It goes again within a minute.' : 'Only a failed delivery can be sent again.');
    }
}
