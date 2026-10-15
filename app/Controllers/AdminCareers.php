<?php

namespace App\Controllers;

use App\Controllers\Concerns\LocksDownDocumentCsp;
use App\Models\CareerApplicationModel;
use App\Models\CareerRoleModel;
use App\Services\CareersService;
use App\Services\DirectoryService;

/**
 * /admin/careers: our own roles and the applications for them. Behind the
 * admin filter with the rest of /admin (see Routes.php). A controller of its
 * own rather than more of Admin, which is long enough already.
 */
class AdminCareers extends BaseController
{
    use LocksDownDocumentCsp;

    public function index()
    {
        $roles = (new CareerRoleModel())->orderBy("FIELD(status, 'open', 'draft', 'closed')", '', false)
            ->orderBy('sort_order', 'ASC')->orderBy('title', 'ASC')->findAll();

        $perRole = [];
        foreach ((new CareerApplicationModel())->select('role_id, COUNT(*) AS n')->where('purged_at', null)
            ->groupBy('role_id')->findAll() as $r) {
            $perRole[(int) $r['role_id']] = (int) $r['n'];
        }

        return view('admin/careers', [
            'roles'   => $roles,
            'perRole' => $perRole,
            'svc'     => new CareersService(),
        ]);
    }

    public function create()
    {
        return $this->form(null);
    }

    public function store()
    {
        $result = (new CareersService())->saveRole(null, (array) $this->request->getPost());
        if (! $result['ok']) {
            return redirect()->to(base_url('admin/careers/new'))
                ->with('errors', $result['errors'])->with('old', $this->request->getPost())
                ->with('error', $result['message']);
        }

        return redirect()->to(base_url('admin/careers/' . $result['id']))->with('success', $result['message']);
    }

    public function edit(int $id)
    {
        return $this->form($id);
    }

    public function update(int $id)
    {
        $result = (new CareersService())->saveRole($id, (array) $this->request->getPost());
        if (! $result['ok']) {
            return redirect()->to(base_url('admin/careers/' . $id))
                ->with('errors', $result['errors'])->with('old', $this->request->getPost())
                ->with('error', $result['message']);
        }

        return redirect()->to(base_url('admin/careers/' . $id))->with('success', $result['message']);
    }

    public function status(int $id)
    {
        $result = (new CareersService())->setRoleStatus($id, (string) $this->request->getPost('status'));

        return redirect()->to(base_url('admin/careers'))->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function applications()
    {
        $model  = new CareerApplicationModel();
        $status = (string) ($this->request->getGet('status') ?? '');
        $role   = $this->request->getGet('role');
        $roleId = $role === null || $role === '' ? null : (int) $role;
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $min    = (int) $this->request->getGet('min_score');
        $min    = $min >= 1 && $min <= \App\Libraries\Screening::COUNT ? $min : null;
        $result = $model->queue($status, $roleId, $page, 25, $min);

        return view('admin/career_applications', [
            'status'   => $status,
            'roleId'   => $roleId,
            'minScore' => $min,
            'rows'     => $result['rows'],
            'pager'    => $result['pager'],
            'counts'   => $model->counts(),
            'roles'    => (new CareerRoleModel())->select('id, title, status')->orderBy('title', 'ASC')->findAll(),
            'svc'      => new CareersService(),
        ]);
    }

    public function updateApplication(int $id)
    {
        $result = (new CareersService())->updateApplication(
            $id,
            (string) $this->request->getPost('status'),
            (string) $this->request->getPost('admin_notes'),
            ! empty($this->request->getPost('notify'))
        );

        // Back to the list the admin was on, which is always an admin URL.
        $previous = (string) previous_url();
        $back     = str_starts_with($previous, base_url('admin/careers/applications'))
            ? $previous
            : base_url('admin/careers/applications');

        return redirect()->to(strtok($back, '#') . '#application-' . $id)
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Stream one CV, as Admin::verificationDocument() streams an ID: looked up
     * by id, sent as a download with nosniff and the locked down CSP, because
     * these are bytes a member of the public chose.
     */
    public function cv(int $id)
    {
        $model = new CareerApplicationModel();
        $app   = $model->find($id);
        $full  = is_array($app) ? $model->resolvePath((string) ($app['cv_path'] ?? '')) : null;
        if ($full === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        log_message('info', sprintf('Admin opened careers CV %d from %s', $id, $this->request->getIPAddress()));

        $contents = (string) file_get_contents($full);
        $ext      = pathinfo($full, PATHINFO_EXTENSION);
        $base     = preg_replace('/[^A-Za-z0-9 ._-]/', '', pathinfo((string) ($app['cv_name'] ?? ''), PATHINFO_FILENAME)) ?: 'cv-' . $id;

        $this->lockDownCspForDocument();

        // A PDF or old image CV opens in the browser tab; a Word CV downloads,
        // so Word opens it (in Protected View) rather than anything here.
        $disposition = in_array(strtolower($ext), ['doc', 'docx'], true) ? 'attachment' : 'inline';

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', (string) $app['cv_mime'])
            ->setHeader('Content-Length', (string) strlen($contents))
            ->setHeader('Content-Disposition', $disposition . '; filename="' . trim(mb_substr($base, 0, 60)) . '.' . $ext . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setBody($contents);
    }

    private function form(?int $id)
    {
        $role = null;
        if ($id !== null) {
            $role = (new CareerRoleModel())->find($id);
            if (! is_array($role)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
        }

        $old = session()->getFlashdata('old');

        return view('admin/career_role_edit', [
            'role'      => $role,
            'values'    => is_array($old) ? $old : ($role ?? ['team' => 'sales', 'sort_order' => 0]),
            'errors'    => session()->getFlashdata('errors') ?? [],
            'svc'       => new CareersService(),
            'provinces' => (new DirectoryService())->provinces(),
            'jobs'      => config('JobBoard'),
        ]);
    }
}
