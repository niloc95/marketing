<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\TeamService;
use App\Services\Bookings\TimeOffService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Time off (Bookings' blocked times, which had no screen). An admin can close
 * the whole business or block anyone; a provider only themselves.
 */
class TimeOff extends BaseController
{
    public function index()
    {
        $context = service('businessContext');
        $scope   = $context->providerScope();
        $team    = new TeamService($context->listingId());

        return view('business/time_off', [
            'rows'       => $this->service()->upcoming($scope),
            'providers'  => array_values(array_filter($team->providers(), static fn (array $p): bool => $scope === null || in_array((int) $p['id'], $scope, true))),
            'wholeBusiness' => $scope === null,
            'timezone'   => $context->timezone(),
            'errors'     => flash('errors') ?: [],
        ]);
    }

    public function add()
    {
        $context    = service('businessContext');
        $providerId = (int) $this->request->getPost('provider_id');
        $scope      = $context->providerScope();
        if ($scope !== null && ! in_array($providerId, $scope, true)) {
            return redirect()->to(base_url('business/time-off'))->with('error', 'You can add time off for yourself only.');
        }
        $result = $this->service()->add(
            $providerId > 0 ? $providerId : null,
            (string) $this->request->getPost('start'),
            (string) $this->request->getPost('end'),
            (string) $this->request->getPost('reason'),
            $context->accountId(),
        );
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/time-off'))->with('error', implode(' ', $result['errors']));
        }

        return redirect()->to(base_url('business/time-off'))->with('success', 'Time off added.');
    }

    public function delete(int $id)
    {
        $context = service('businessContext');
        $row     = $this->service()->find($id);
        $scope   = $context->providerScope();
        if ($row === null || ($scope !== null && ! in_array((int) $row['provider_id'], $scope, true))) {
            throw PageNotFoundException::forPageNotFound();
        }
        $this->service()->delete($id, $context->accountId());

        return redirect()->to(base_url('business/time-off'))->with('success', 'Time off removed.');
    }

    private function service(): TimeOffService
    {
        $context = service('businessContext');

        return new TimeOffService($context->listingId(), $context->timezone());
    }
}
