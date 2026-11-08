<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Libraries\Mailer;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Notifications\BookingIntegrations;
use App\Services\Bookings\Payments\DepositService;
use App\Services\Bookings\Payments\GatewayConfig;
use Config\Services;

/**
 * Settings, Payments tab (/bookings/settings/payments): the business's own
 * PayFast or Paystack account that deposits are paid into (decision D6),
 * and how long an unpaid deposit holds a time. Admins only (settings.manage).
 *
 * The keys are write only (BookingIntegrations), every change is in the
 * audit log, and every admin of the business gets an email about it: a
 * changed account is where a deposit would go astray.
 */
class PaymentSettings extends BaseController
{
    private const BACK = 'bookings/settings/payments';

    /** @return array<string,mixed> what the tab shows */
    public static function viewData(int $listingId): array
    {
        $services = array_values(array_filter(
            (new CatalogueService($listingId))->services(),
            static fn (array $s): bool => (int) $s['deposit_pct'] > 0,
        ));

        return [
            'account'     => (new BookingIntegrations($listingId))->view('payment'),
            'canEncrypt'  => self::canEncrypt(),
            'ready'       => DepositService::gatewayFor($listingId) !== null,
            'hold'        => BookingSettings::for($listingId)->int('payments.hold_minutes'),
            'services'    => array_map(static fn (array $s): array => [
                'id'      => (int) $s['id'],
                'name'    => (string) $s['name'],
                'pct'     => (int) $s['deposit_pct'],
                'deposit' => DepositService::amountFor($s),
            ], $services),
        ];
    }

    public function save()
    {
        $context  = service('businessContext');
        $provider = (string) $this->request->getPost('provider');
        $before   = (new BookingIntegrations($context->listingId()))->view('payment');
        $errors   = (new BookingIntegrations($context->listingId()))->save(
            'payment',
            $provider,
            (array) ($this->request->getPost('cfg')[$provider] ?? []),
            $this->request->getPost('active') !== null,
            $context->accountId(),
        );
        if ($errors !== []) {
            return redirect()->to(base_url(self::BACK))->with('error', implode(' ', $errors));
        }

        $result = BookingSettings::for($context->listingId())->save([
            'payments.hold_minutes' => (string) ((int) $this->request->getPost('hold_minutes')),
        ], $context->accountId());
        if ($result['errors'] !== []) {
            return redirect()->to(base_url(self::BACK))->with('error', 'Hold the time for 15 to 120 minutes.');
        }
        if ($result['changed'] !== []) {
            (new BookingAudit())->record($context->listingId(), $context->accountId(), 'settings.saved', 'settings', null,
                array_map(static fn (array $c): string => $c[0], $result['changed']),
                array_map(static fn (array $c): string => $c[1], $result['changed']));
        }

        $after = (new BookingIntegrations($context->listingId()))->view('payment');
        if ($before['provider'] !== $after['provider'] || $before['active'] !== $after['active'] || $before['values'] !== $after['values'] || $this->secretTyped($provider)) {
            $this->tellAdmins($context->listing(), $after, (string) (($context->account()['name'] ?? '') ?: ($context->account()['email'] ?? '')));
        }

        return redirect()->to(base_url(self::BACK))->with('success', $provider === '' ? 'Removed. Deposits are no longer taken.' : 'Saved.');
    }

    /** Ask PayFast or Paystack whether the saved keys work. */
    public function check()
    {
        $context      = service('businessContext');
        $integrations = new BookingIntegrations($context->listingId());
        $account      = $integrations->account('payment');
        if ($account === null || ! in_array($account['provider'], ['payfast', 'paystack'], true)) {
            return redirect()->to(base_url(self::BACK))->with('error', 'Save your account first.');
        }
        $config = new GatewayConfig($account['provider'], $account['config']);
        $ok     = $account['provider'] === 'paystack'
            ? Services::bookingPaystack($config)->keyWorks()
            : Services::bookingPayFast($config)->ping();
        $name = BookingIntegrations::PROVIDER_LABELS[$account['provider']];
        $integrations->recordHealth('payment', $ok, $ok ? null : $name . ' did not accept the details.', true);

        return redirect()->to(base_url(self::BACK))->with(
            $ok ? 'success' : 'error',
            $ok ? $name . ' accepted your details.' : $name . ' did not accept these details. Check them against your ' . $name . ' dashboard and save again.'
        );
    }

    private function secretTyped(string $provider): bool
    {
        foreach ((array) ($this->request->getPost('cfg')[$provider] ?? []) as $field => $value) {
            if ((BookingIntegrations::PROVIDERS['payment'][$provider][$field][1] ?? false) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every admin of the business hears that the payment account changed,
     * and who changed it.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $account BookingIntegrations::view('payment')
     */
    private function tellAdmins(array $listing, array $account, string $by): void
    {
        $admins = db_connect()->table('xs_business_members m')
            ->select('a.email')
            ->join('xs_accounts a', 'a.id = m.account_id')
            ->where('m.listing_id', (int) $listing['id'])->where('m.role', 'admin')->where('m.status', 'active')->where('a.status', 'active')
            ->get()->getResultArray();
        $body = view('emails/booking-payment-account', [
            'listing' => $listing,
            'account' => $account,
            'by'      => $by,
            'url'     => listing_site_url($listing, self::BACK),
        ], ['saveData' => false]);
        foreach (array_unique(array_column($admins, 'email')) as $email) {
            (new Mailer())->send((string) $email, 'Payment account changed for ' . $listing['display_name'], $body);
        }
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
