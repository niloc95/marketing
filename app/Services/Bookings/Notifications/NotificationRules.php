<?php

namespace App\Services\Bookings\Notifications;

use App\Models\Bookings\BookingNotificationRuleModel;
use App\Services\Bookings\BookingAudit;
use Config\BookingNotifications as Catalog;

/**
 * Which messages a business sends: event × channel × recipient (customer or
 * team). Ported from Bookings' NotificationPolicyService and
 * xs_business_notification_rules. The only writer of
 * xs_booking_notification_rules.
 *
 * A rule with no row takes the default in Config\BookingNotifications. SMS
 * and WhatsApp also need the business's own account to be on
 * (BookingIntegrations::active()); the rule alone sends nothing there.
 * Team messages are email only (and the notification centre, always).
 */
class NotificationRules
{
    /** @var array<string,bool>|null "recipient.event.channel" => on */
    private ?array $stored = null;

    public function __construct(private int $listingId, private ?Catalog $catalog = null)
    {
        $this->catalog ??= config('BookingNotifications');
    }

    public function enabled(string $event, string $channel, string $recipient = Catalog::CUSTOMER): bool
    {
        if (! $this->known($event, $channel, $recipient)) {
            return false;
        }

        return $this->stored()[$recipient . '.' . $event . '.' . $channel]
            ?? ($this->catalog->defaultRules[$recipient][$event . '.' . $channel] ?? false);
    }

    /**
     * Every rule for the settings screen.
     *
     * @return array{customer:array<string,array<string,bool>>,team:array<string,bool>}
     */
    public function matrix(): array
    {
        $out = ['customer' => [], 'team' => []];
        foreach (array_keys(Catalog::EVENTS) as $event) {
            foreach (Catalog::CHANNELS as $channel) {
                $out['customer'][$event][$channel] = $this->enabled($event, $channel);
            }
        }
        foreach (Catalog::TEAM_EVENTS as $event) {
            $out['team'][$event] = $this->enabled($event, 'email', Catalog::TEAM);
        }

        return $out;
    }

    /**
     * Save the whole matrix from the settings form: a box ticked is on, a box
     * not posted is off.
     *
     * @param array<string,mixed> $customer event => channel => '1'
     * @param array<string,mixed> $team     event => '1'
     */
    public function save(array $customer, array $team, ?int $accountId): void
    {
        $changed = [];
        foreach (array_keys(Catalog::EVENTS) as $event) {
            foreach (Catalog::CHANNELS as $channel) {
                $on = ! empty($customer[$event][$channel]);
                if ($this->set($event, $channel, Catalog::CUSTOMER, $on)) {
                    $changed[] = 'customer.' . $event . '.' . $channel . '=' . ($on ? 'on' : 'off');
                }
            }
        }
        foreach (Catalog::TEAM_EVENTS as $event) {
            $on = ! empty($team[$event]);
            if ($this->set($event, 'email', Catalog::TEAM, $on)) {
                $changed[] = 'team.' . $event . '=' . ($on ? 'on' : 'off');
            }
        }
        if ($changed !== []) {
            (new BookingAudit())->record($this->listingId, $accountId, 'notifications.rules', 'settings', null, null, $changed);
        }
    }

    private function set(string $event, string $channel, string $recipient, bool $on): bool
    {
        if ($this->enabled($event, $channel, $recipient) === $on) {
            return false;
        }
        $row = BookingNotificationRuleModel::forBusiness($this->listingId)
            ->where(['event' => $event, 'channel' => $channel, 'recipient' => $recipient])->first();
        if (is_array($row)) {
            BookingNotificationRuleModel::forBusiness($this->listingId)->update((int) $row['id'], ['enabled' => $on ? 1 : 0]);
        } else {
            BookingNotificationRuleModel::forBusiness($this->listingId)->insert(['event' => $event, 'channel' => $channel, 'recipient' => $recipient, 'enabled' => $on ? 1 : 0]);
        }
        $this->stored = null;

        return true;
    }

    private function known(string $event, string $channel, string $recipient): bool
    {
        if ($recipient === Catalog::TEAM) {
            return $channel === 'email' && in_array($event, Catalog::TEAM_EVENTS, true);
        }

        return $recipient === Catalog::CUSTOMER && isset(Catalog::EVENTS[$event]) && in_array($channel, Catalog::CHANNELS, true);
    }

    /** @return array<string,bool> */
    private function stored(): array
    {
        if ($this->stored === null) {
            $this->stored = [];
            foreach (BookingNotificationRuleModel::forBusiness($this->listingId)->findAll() as $row) {
                $this->stored[$row['recipient'] . '.' . $row['event'] . '.' . $row['channel']] = (int) $row['enabled'] === 1;
            }
        }

        return $this->stored;
    }
}
