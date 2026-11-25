<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\Notifications\OptOuts;
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\CustomerService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Customers (Bookings' Customer Management): find, add, edit, history and
 * delete with its impact shown first. Customers are reached by their hash,
 * never their id. Limited to the member's scope: a provider or reception
 * sees customers with an appointment for one of their people; only an admin
 * deletes (customers.delete).
 */
class Customers extends BaseController
{
    public function index()
    {
        $context = service('businessContext');
        $q       = mb_substr(trim((string) $this->request->getGet('q')), 0, 100);
        $page    = max(1, (int) $this->request->getGet('page'));

        $result = $this->service()->list($q, $context->providerScope(), $page);

        return view('business/customers', [
            'q'         => $q,
            'result'    => $result,
            'summaries' => $this->service()->summaries(array_column($result['rows'], 'id'), $context->providerScope()),
            'timezone'  => $context->timezone(),
        ]);
    }

    public function new()
    {
        return view('business/customer_form', [
            'customer'     => null,
            'old'          => flash('customer_old') ?: [],
            'errors'       => flash('errors') ?: [],
            'customFields' => $this->service()->customFields(),
        ]);
    }

    public function create()
    {
        $input  = $this->input();
        $result = $this->service()->save(null, $input, service('businessContext')->accountId());
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/customers/new'))->with('errors', $result['errors'])->with('customer_old', $input)
                ->with('error', 'Please check the highlighted fields.');
        }

        // A provider or reception member sees a customer once they have an
        // appointment with one of their people, so book one straight away.
        if (service('businessContext')->providerScope() !== null) {
            return redirect()->to(base_url('business/appointments/new'))->with('appointment_old', $input)
                ->with('success', 'Customer added. Choose a time for their first appointment.');
        }

        return redirect()->to(base_url('business/customers/' . $this->service()->find((int) $result['id'])['hash']))->with('success', 'Customer added.');
    }

    public function show(string $hash)
    {
        $context  = service('businessContext');
        $customer = $this->visible($hash);

        // Upcoming soonest first (an appointment under way still counts), then the rest newest first.
        $now      = gmdate('Y-m-d H:i:s');
        $upcoming = [];
        $past     = [];
        foreach ($this->service()->history($customer['id'], $context->providerScope()) as $a) {
            if (in_array($a['status'], AppointmentStatus::UPCOMING, true) && $a['end_at'] > $now) {
                array_unshift($upcoming, $a);
            } else {
                $past[] = $a;
            }
        }

        return view('business/customer', [
            'customer'     => $customer,
            'old'          => flash('customer_old') ?: $customer,
            'errors'       => flash('errors') ?: [],
            'customFields' => $this->service()->customFields(),
            'upcoming'     => $upcoming,
            'past'         => $past,
            'providers'    => Calendar::providersInScope(),
            'statuses'     => AppointmentStatus::options(),
            'sendChannels' => Calendar::sendChannels($context->listingId()),
            'stats'        => $this->service()->stats($customer['id']),
            'impact'       => $context->can('customers.delete') ? $this->service()->deletionImpact($customer['id']) : null,
            'timezone'     => $context->timezone(),
            'optedOut'     => ! empty($customer['email']) && (new OptOuts($context->listingId()))->isOptedOut('email', (string) $customer['email']),
        ]);
    }

    /** The customer asked to get booking emails again (they stopped them from a link). */
    public function emailsAgain(string $hash)
    {
        $context  = service('businessContext');
        $customer = $this->visible($hash);
        if ($this->request->getPost('confirm') === 'yes' && ! empty($customer['email'])) {
            (new OptOuts($context->listingId()))->optIn('email', (string) $customer['email']);
            (new \App\Services\Bookings\BookingAudit())->record($context->listingId(), $context->accountId(), 'customer.emails_again', 'customer', $customer['id']);
        }

        return redirect()->to(base_url('business/customers/' . $hash))->with('success', 'Booking emails will reach them again.');
    }

    public function save(string $hash)
    {
        $customer = $this->visible($hash);
        $input    = $this->input();
        $result   = $this->service()->save($customer['id'], $input, service('businessContext')->accountId());
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/customers/' . $hash . '#edit'))->with('errors', $result['errors'])->with('customer_old', $input)
                ->with('error', 'Please check the highlighted fields.');
        }

        return redirect()->to(base_url('business/customers/' . $hash))->with('success', 'Saved.');
    }

    public function delete(string $hash)
    {
        $customer = $this->visible($hash);
        $error    = $this->service()->delete($customer['id'], service('businessContext')->accountId());
        if ($error !== null) {
            return redirect()->to(base_url('business/customers/' . $hash . '#delete'))->with('error', $error);
        }

        return redirect()->to(base_url('business/customers'))->with('success', 'Customer deleted.');
    }

    /**
     * Everything held about the customer as one JSON file, for a POPIA
     * access request (admins; Phase 10). In the audit log.
     */
    public function export(string $hash)
    {
        $customer = $this->visible($hash);
        $context  = service('businessContext');
        $data     = (new \App\Services\Bookings\Privacy\CustomerExport($context->listingId()))->build((int) $customer['id']);
        (new \App\Services\Bookings\BookingAudit())->record($context->listingId(), $context->accountId(), 'customer.exported', 'customer', (int) $customer['id']);

        return $this->response
            ->setHeader('Cache-Control', 'no-store, private')
            ->setHeader('Content-Disposition', 'attachment; filename="customer-' . (int) $customer['id'] . '.json"')
            ->setJSON($data);
    }

    /** JSON for the appointment form's customer picker: ?q= (at most 10). */
    public function lookup()
    {
        $q    = mb_substr(trim((string) $this->request->getGet('q')), 0, 100);
        $rows = $q === '' ? [] : $this->service()->list($q, service('businessContext')->providerScope(), 1, 10)['rows'];

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['data' => array_map(static fn (array $c): array => [
            'id'            => $c['id'],
            'name'          => $c['name'],
            'email'         => $c['email'],
            'phone'         => $c['phone'],
            'custom_fields' => $c['custom_fields'],
        ], $rows)]);
    }

    /**
     * JSON for the top bar's Find (business/_topbar_tools.php, find.js):
     * ?q= matches customers (at most 5) and, for a member who books,
     * appointments (at most 5), both in the member's scope. Each row carries
     * the page it opens.
     */
    public function find()
    {
        $context = service('businessContext');
        $q       = mb_substr(trim((string) $this->request->getGet('q')), 0, 100);
        $out     = ['customers' => [], 'appointments' => []];
        if (mb_strlen($q) >= 2) {
            $rows             = $this->service()->list($q, $context->providerScope(), 1, 5)['rows'];
            $out['customers'] = array_map(static fn (array $c): array => [
                'name'   => (string) $c['name'],
                'detail' => implode(' · ', array_filter([(string) ($c['email'] ?? ''), (string) ($c['phone'] ?? '')])),
                'url'    => base_url('business/customers/' . $c['hash']),
            ], $rows);
            if ($context->can('appointments.manage')) {
                $service             = new \App\Services\Bookings\AppointmentService($context->listing(), $context->providerScope(), (int) $context->member()['id'], $context->accountId());
                $out['appointments'] = array_map(static fn (array $a): array => [
                    'name'   => (string) $a['title'],
                    'when'   => (new \DateTimeImmutable((string) $a['start']))->format('D j M Y, H:i'),
                    'detail' => implode(' · ', array_filter([(string) $a['service_name'], (string) $a['provider_name'], (string) $a['reference']])),
                    'status' => (string) $a['status'],
                    'url'    => base_url('business/calendar') . '?' . http_build_query(['view' => 'day', 'date' => substr((string) $a['start'], 0, 10), 'open' => $a['hash']]),
                ], $service->findMatching($q, 5));
            }
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['data' => $out]);
    }

    private function service(): CustomerService
    {
        return new CustomerService(service('businessContext')->listingId());
    }

    /** @return array<string,mixed> */
    private function visible(string $hash): array
    {
        $customer = $this->service()->findByHash($hash);
        if ($customer === null || ! $this->service()->visible($customer['id'], service('businessContext')->providerScope())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $customer;
    }

    /** @return array<string,string> */
    private function input(): array
    {
        $keys = ['first_name', 'last_name', 'email', 'phone', 'address', 'notes'];
        for ($n = 1; $n <= \Config\Bookings::CUSTOM_FIELDS; $n++) {
            $keys[] = 'field_' . $n;
        }
        $out = [];
        foreach ($keys as $k) {
            $value   = $this->request->getPost($k);
            $out[$k] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }
}
