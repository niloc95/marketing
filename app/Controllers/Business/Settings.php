<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Business settings (docs/bookings/settings.md). Phase 1 has two tabs:
 * General (read from the profile, edited at /manage/edit) and Local time.
 * More tabs arrive with the phases that use them.
 */
class Settings extends BaseController
{
    public const TABS = ['general' => 'General', 'booking' => 'Booking rules', 'questions' => 'Questions', 'localization' => 'Local time'];

    public function index()
    {
        return redirect()->to(base_url('business/settings/general'));
    }

    public function tab(string $tab)
    {
        if (! array_key_exists($tab, self::TABS)) {
            throw PageNotFoundException::forPageNotFound();
        }
        $context = service('businessContext');

        return view('business/settings', [
            'tab'      => $tab,
            'tabs'     => self::TABS,
            'listing'  => $context->listing(),
            'settings' => BookingSettings::for($context->listingId())->all(),
            'choices'  => config('Bookings')->settingChoices,
        ]);
    }

    public function saveLocalization()
    {
        $context = service('businessContext');
        $result  = BookingSettings::for($context->listingId())->save([
            'localization.time_format' => (string) $this->request->getPost('time_format'),
            'localization.first_day'   => (string) $this->request->getPost('first_day'),
        ], $context->accountId());

        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/settings/localization'))->with('error', 'Please choose from the options.');
        }
        if ($result['changed'] !== []) {
            (new BookingAudit())->record(
                $context->listingId(),
                $context->accountId(),
                'settings.saved',
                'settings',
                null,
                array_map(static fn (array $c): string => $c[0], $result['changed']),
                array_map(static fn (array $c): string => $c[1], $result['changed']),
            );
        }

        return redirect()->to(base_url('business/settings/localization'))->with('success', 'Saved.');
    }

    public function saveBooking()
    {
        $context = service('businessContext');
        $result  = BookingSettings::for($context->listingId())->save([
            'booking.slot_step'     => (string) $this->request->getPost('slot_step'),
            'booking.lead_minutes'  => (string) ((int) $this->request->getPost('lead_hours') * 60),
            'business.buffer_time'  => (string) $this->request->getPost('buffer_time'),
            'business.future_limit' => (string) $this->request->getPost('future_limit'),
        ], $context->accountId());

        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/settings/booking'))->with('error', implode(' ', array_unique($result['errors'])));
        }
        if ($result['changed'] !== []) {
            (new BookingAudit())->record(
                $context->listingId(),
                $context->accountId(),
                'settings.saved',
                'settings',
                null,
                array_map(static fn (array $c): string => $c[0], $result['changed']),
                array_map(static fn (array $c): string => $c[1], $result['changed']),
            );
        }

        return redirect()->to(base_url('business/settings/booking'))->with('success', 'Saved.');
    }

    /**
     * The business's own questions (custom fields 1 to 6): asked when
     * someone books, kept on the appointment and the customer.
     */
    public function saveQuestions()
    {
        $context = service('businessContext');
        $values  = [];
        for ($n = 1; $n <= \Config\Bookings::CUSTOM_FIELDS; $n++) {
            $p   = 'booking.custom_field_' . $n . '_';
            $row = (array) ($this->request->getPost('q')[$n] ?? []);
            $values[$p . 'title']     = trim(strip_tags((string) ($row['title'] ?? '')));
            $values[$p . 'type']      = (string) ($row['type'] ?? 'text');
            $values[$p . 'options']   = implode("\n", \App\Services\Bookings\CustomerService::options((string) ($row['options'] ?? '')));
            $values[$p . 'enabled']   = ! empty($row['enabled']) && $values[$p . 'title'] !== '' ? '1' : '0';
            $values[$p . 'required']  = ! empty($row['required']) ? '1' : '0';
            $values[$p . 'sensitive'] = ! empty($row['sensitive']) ? '1' : '0';
            if ($values[$p . 'enabled'] === '1' && $values[$p . 'type'] === 'select' && $values[$p . 'options'] === '') {
                return redirect()->to(base_url('business/settings/questions'))->with('error', 'Question ' . $n . ' is a list: add its choices, one per line.');
            }
        }
        $result = BookingSettings::for($context->listingId())->save($values, $context->accountId());
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/settings/questions'))->with('error', implode(' ', array_unique($result['errors'])));
        }
        if ($result['changed'] !== []) {
            (new BookingAudit())->record($context->listingId(), $context->accountId(), 'settings.saved', 'settings', null,
                array_map(static fn (array $c): string => $c[0], $result['changed']),
                array_map(static fn (array $c): string => $c[1], $result['changed']));
        }

        return redirect()->to(base_url('business/settings/questions'))->with('success', 'Saved.');
    }

    public function turnOff()
    {
        $context = service('businessContext');
        if ($this->request->getPost('confirm') !== 'yes') {
            return redirect()->to(base_url('business/settings/general'))->with('error', 'Tick the box to confirm.');
        }
        (new BookingSetupService())->turnOff($context->listingId(), $context->accountId());
        session()->remove(BusinessSession::LISTING);

        return redirect()->to(base_url('business/choose'))->with('success', 'Online bookings are off for ' . $context->listing()['display_name'] . '. Nothing was deleted.');
    }
}
