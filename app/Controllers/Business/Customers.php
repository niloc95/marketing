<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
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

        return view('business/customers', [
            'q'      => $q,
            'result' => $this->service()->list($q, $context->providerScope(), $page),
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

        return view('business/customer', [
            'customer'     => $customer,
            'old'          => flash('customer_old') ?: $customer,
            'errors'       => flash('errors') ?: [],
            'customFields' => $this->service()->customFields(),
            'history'      => $this->service()->history($customer['id'], $context->providerScope()),
            'stats'        => $this->service()->stats($customer['id']),
            'impact'       => $context->can('customers.delete') ? $this->service()->deletionImpact($customer['id']) : null,
            'timezone'     => $context->timezone(),
        ]);
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
