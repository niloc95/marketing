<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\TeamService;
use CodeIgniter\Exceptions\PageNotFoundException;

/** Services and categories (Bookings' /services). Rules in CatalogueService. */
class Services extends BaseController
{
    public function index()
    {
        $context = service('businessContext');
        $catalog = new CatalogueService($context->listingId());

        return view('business/services', [
            'services'   => $catalog->services(true),
            'categories' => $catalog->categories(),
            'providers'  => array_column((new TeamService($context->listingId()))->providers(), 'name', 'id'),
        ]);
    }

    public function new()
    {
        return $this->form(null);
    }

    public function edit(int $id)
    {
        return $this->form($id);
    }

    public function save(?int $id = null)
    {
        $context = service('businessContext');
        $input   = $this->request->getPost(['name', 'description', 'duration_min', 'price', 'deposit_pct', 'buffer_before', 'buffer_after', 'category_id', 'delivery_modes', 'provider_ids']);
        $result  = (new CatalogueService($context->listingId()))->saveService($id, $input, $context->accountId());

        if ($result['errors'] !== []) {
            return redirect()->to(base_url($id === null ? 'bookings/services/new' : 'bookings/services/' . $id))
                ->with('errors', $result['errors'])->with('old', $input);
        }

        return redirect()->to(base_url('bookings/services'))->with('success', 'Service saved.');
    }

    public function archive(int $id)
    {
        $context = service('businessContext');
        $restore = $this->request->getPost('restore') === '1';
        if (! (new CatalogueService($context->listingId()))->archiveService($id, $context->accountId(), ! $restore)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return redirect()->to(base_url('bookings/services'))->with('success', $restore ? 'Service is back on offer.' : 'Service archived. Customers can no longer choose it.');
    }

    public function saveCategory(?int $id = null)
    {
        $context = service('businessContext');
        $result  = (new CatalogueService($context->listingId()))->saveCategory(
            $id,
            (string) $this->request->getPost('name'),
            (string) $this->request->getPost('color'),
            $context->accountId(),
        );

        return redirect()->to(base_url('bookings/services#categories'))
            ->with($result['errors'] === [] ? 'success' : 'error', $result['errors'] === [] ? 'Category saved.' : implode(' ', $result['errors']));
    }

    public function deleteCategory(int $id)
    {
        $context = service('businessContext');
        (new CatalogueService($context->listingId()))->deleteCategory($id, $context->accountId());

        return redirect()->to(base_url('bookings/services#categories'))->with('success', 'Category deleted. Its services stay.');
    }

    private function form(?int $id)
    {
        $context = service('businessContext');
        $catalog = new CatalogueService($context->listingId());
        $service = $id === null ? null : $catalog->service($id);
        if ($id !== null && $service === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('business/service_form', [
            'service'    => $service,
            'categories' => $catalog->categories(),
            'providers'  => (new TeamService($context->listingId()))->providers(),
            'errors'     => flash('errors') ?: [],
            'old'        => flash('old') ?: null,
        ]);
    }
}
