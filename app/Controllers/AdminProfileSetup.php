<?php

namespace App\Controllers;

use App\Controllers\Concerns\LocksDownDocumentCsp;
use App\Models\ProfileSetupOrderModel;
use App\Services\ProfileSetupService;

/**
 * /admin/profile-setup: paid profile setup orders, worked from here. Behind
 * the admin filter with the rest of /admin (see Routes.php).
 */
class AdminProfileSetup extends BaseController
{
    use LocksDownDocumentCsp;

    public function index()
    {
        $status = (string) $this->request->getGet('status');
        if ($status !== '' && ! isset(ProfileSetupOrderModel::STATUSES[$status])) {
            $status = '';
        }
        // Paid orders are the work, so they are the default view.
        if ($this->request->getGet('status') === null) {
            $status = ProfileSetupOrderModel::STATUS_PAID;
        }

        $model = new ProfileSetupOrderModel();
        $counts = [];
        foreach ($model->select('status, COUNT(*) AS n')->groupBy('status')->findAll() as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }

        $query = new ProfileSetupOrderModel();
        if ($status !== '') {
            $query->where('status', $status);
        }

        return view('admin/profile_setup', [
            'status' => $status,
            'counts' => $counts,
            'rows'   => $query->orderBy('created_at', 'DESC')->findAll(200),
        ]);
    }

    public function update(int $id)
    {
        $result = (new ProfileSetupService())->update(
            $id,
            (string) $this->request->getPost('status'),
            (string) $this->request->getPost('admin_notes')
        );

        return redirect()->to(base_url('admin/profile-setup') . '?status=' . rawurlencode((string) $this->request->getPost('return_status')) . '#order-' . $id)
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
