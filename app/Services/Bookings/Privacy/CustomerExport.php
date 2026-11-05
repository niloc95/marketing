<?php

namespace App\Services\Bookings\Privacy;

use App\Models\Bookings\BookingAppointmentFieldModel;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingPaymentModel;
use App\Services\Bookings\Api\Resources;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\Notifications\OptOuts;

/**
 * Everything one business holds about one customer, for a POPIA access
 * request (parity.md §7, Phase 10): the business is responsible for its
 * customers' data and answers the request; this gives it the whole record in
 * one file. Admins only, and every export is in the audit log.
 */
class CustomerExport
{
    public function __construct(private int $listingId)
    {
    }

    /** @return array<string,mixed>|null */
    public function build(int $customerId): ?array
    {
        $customers = new CustomerService($this->listingId);
        $c         = $customers->find($customerId);
        if ($c === null) {
            return null;
        }
        $titles = array_column($customers->customFields(), 'title', 'key');

        $appointments = [];
        foreach (BookingAppointmentModel::forBusiness($this->listingId)->where('customer_id', $customerId)->orderBy('start_at')->findAll() as $a) {
            $answers = [];
            foreach ((new BookingAppointmentFieldModel())->where('appointment_id', (int) $a['id'])->orderBy('field_key')->findAll() as $f) {
                $answers[$titles[$f['field_key']] ?? $f['field_key']] = (string) $f['value'];
            }
            $payments = array_map(static fn (array $p): array => [
                'reference' => $p['reference'], 'gateway' => $p['gateway'], 'amount_cents' => (int) $p['amount_cents'],
                'status' => $p['status'], 'paid_at' => Resources::utc((string) ($p['paid_at'] ?? '')), 'created_at' => Resources::utc((string) $p['created_at']),
            ], BookingPaymentModel::forBusiness($this->listingId)->where('appointment_id', (int) $a['id'])->orderBy('id')->findAll());
            $messages = array_map(static fn (array $m): array => [
                'event' => $m['event'], 'channel' => $m['channel'], 'outcome' => $m['outcome'], 'at' => Resources::utc((string) $m['created_at']),
            ], db_connect()->table('xs_booking_delivery_logs')->where('listing_id', $this->listingId)->where('appointment_id', (int) $a['id'])
                ->where('recipient_type', 'customer')->orderBy('id')->get()->getResultArray());

            $appointments[] = Resources::appointment($a, $c) + ['answers' => $answers, 'payments' => $payments, 'messages' => $messages];
        }

        $custom = [];
        foreach ((array) $c['custom_fields'] as $key => $value) {
            $custom[$titles[$key] ?? $key] = $value;
        }
        $optOuts = new OptOuts($this->listingId);

        return [
            'business'     => ['id' => $this->listingId],
            'exported_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            'customer'     => Resources::customer($c) + ['answers' => $custom],
            'stopped'      => [
                'email' => ! empty($c['email']) && $optOuts->isOptedOut('email', (string) $c['email']),
                'whatsapp' => ! empty($c['phone']) && $optOuts->isOptedOut('whatsapp', (string) $c['phone']),
            ],
            'appointments' => $appointments,
        ];
    }
}
