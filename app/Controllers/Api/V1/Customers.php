<?php

namespace App\Controllers\Api\V1;

use App\Services\Bookings\Api\Resources;
use App\Services\Bookings\CustomerService;

/**
 * The business's customers (scopes customers.read and customers.write),
 * limited, like /bookings/customers, to the customers of the member's own
 * people when the key's member is not an admin. Deleting stays in
 * /bookings (admins only, with its warnings).
 */
class Customers extends ApiController
{
    private const FIELDS = ['first_name', 'last_name', 'email', 'phone', 'address', 'notes'];

    public function index()
    {
        [$page, $perPage] = $this->paging();
        $result = (new CustomerService($this->listingId()))->list((string) ($this->request->getGet('q') ?? ''), $this->scope(), $page, $perPage);

        return $this->ok(
            array_map([Resources::class, 'customer'], $result['rows']),
            ['page' => $result['page'], 'per_page' => $perPage, 'pages' => $result['pages'], 'total' => $result['total']],
        );
    }

    public function show(int $id)
    {
        $customers = new CustomerService($this->listingId());
        $customer  = $customers->find($id);
        if ($customer === null || ! $customers->visible($id, $this->scope())) {
            return $this->notFound();
        }

        return $this->ok(Resources::customer($customer));
    }

    public function create()
    {
        $customers = new CustomerService($this->listingId());
        $body      = array_intersect_key($this->body(), array_flip(self::FIELDS));
        $result    = $customers->save(null, $body, service('businessContext')->accountId());
        if ($result['errors'] !== []) {
            return $this->invalid($result['errors']);
        }

        return $this->ok(Resources::customer($customers->find((int) $result['id'])), [], 201);
    }

    /** Only the fields sent change. */
    public function update(int $id)
    {
        $customers = new CustomerService($this->listingId());
        $customer  = $customers->find($id);
        if ($customer === null || ! $customers->visible($id, $this->scope())) {
            return $this->notFound();
        }
        $body   = array_intersect_key($this->body(), array_flip(self::FIELDS));
        $input  = $body + array_map(static fn ($v) => (string) ($v ?? ''), array_intersect_key($customer, array_flip(self::FIELDS)));
        $result = $customers->save($id, $input, service('businessContext')->accountId());
        if ($result['errors'] !== []) {
            return $this->invalid($result['errors']);
        }

        return $this->ok(Resources::customer($customers->find($id)));
    }
}
