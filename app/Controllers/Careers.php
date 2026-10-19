<?php

namespace App\Controllers;

use App\Services\CareersService;

/**
 * Working at WebScheduler Local: /careers, one page per open role, and the
 * application form both share. The rules are in CareersService.
 */
class Careers extends BaseController
{
    /** Nobody reads the page and fills the form this fast. */
    private const MIN_FORM_SECONDS = 3;

    private const SESSION_KEY = 'careers_form_rendered_at';

    public function index()
    {
        session()->set(self::SESSION_KEY, time());
        $svc = new CareersService();

        // The search form's three filters. Only scalar strings get through,
        // so a crafted array parameter cannot reach the filter.
        $filters = [];
        foreach (['q' => 100, 'team' => 20, 'location' => 160] as $key => $max) {
            $value = $this->request->getGet($key);
            $filters[$key] = is_string($value) ? mb_substr(trim($value), 0, $max) : '';
        }

        return view('directory/careers', [
            'all'     => $svc->openRoles(),
            'roles'   => $svc->filterOpen($filters),
            'filters' => $filters,
            'options' => $svc->filterOptions(),
            'svc'     => $svc,
            'old'    => session()->getFlashdata('old') ?? [],
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function show(string $slug)
    {
        $svc  = new CareersService();
        $role = $svc->findOpen($slug);
        if ($role === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        session()->set(self::SESSION_KEY, time());

        return view('careers/role', [
            'role'   => $role,
            'svc'    => $svc,
            'old'    => session()->getFlashdata('old') ?? [],
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function apply()
    {
        $svc  = new CareersService();
        $back = $this->returnUrl($svc);

        // Honeypot and timing floor, as on /partners. Reported as success so a
        // bot learns nothing. 'applied', not 'success': the form section shows
        // it where the #apply redirect lands, not the layout's top of page.
        $renderedAt = (int) session(self::SESSION_KEY);
        if (trim((string) $this->request->getPost('company_website_hp')) !== ''
            || $renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            return redirect()->to($back)->with('applied', CareersService::APPLIED_MESSAGE);
        }

        $email     = strtolower(trim((string) $this->request->getPost('email')));
        $throttler = service('throttler');
        if ($throttler->check('careers-ip-' . md5((string) $this->request->getIPAddress()), 3, HOUR) === false
            || ($email !== '' && $throttler->check('careers-to-' . md5($email), 2, DAY) === false)) {
            return redirect()->to($back)->with('old', $this->request->getPost())
                ->with('error', 'Too many applications. Please wait a little while and try again.');
        }

        $result = $svc->apply((array) $this->request->getPost(), $this->request->getFile('cv'));
        if (! empty($result['already'])) {
            session()->remove(self::SESSION_KEY);

            return redirect()->to($back)->with('already_applied', CareersService::ALREADY_APPLIED_MESSAGE);
        }
        if (! $result['ok']) {
            return redirect()->to($back)
                ->with('errors', $result['errors'])
                ->with('old', $this->request->getPost())
                ->with('error', 'Please correct the highlighted fields. Your CV needs attaching again.');
        }

        session()->remove(self::SESSION_KEY);

        return redirect()->to($back)->with('applied', CareersService::APPLIED_MESSAGE);
    }

    /**
     * Start (or resume) the screening questions for a role: the applicant
     * pressed "Start the questions". JSON for directory.js, with the fresh
     * CSRF token, because the token regenerates on every POST and the page
     * still has the application itself to send.
     */
    public function screeningStart()
    {
        $throttler = service('throttler');
        if ($throttler->check('careers-q-' . md5((string) $this->request->getIPAddress()), 20, HOUR) === false) {
            return $this->screeningJson(['ok' => false, 'message' => 'Too many tries. Please wait a little while and try again.'], 429);
        }

        $view = (new CareersService())->startScreening((int) $this->request->getPost('role_id'));
        if ($view === null) {
            return $this->screeningJson(['ok' => false, 'message' => 'This role has no questions to answer, or it has just closed.'], 404);
        }

        return $this->screeningJson(['ok' => true] + $view);
    }

    /** Lock the applicant's answers (Screening::answer()). */
    public function screeningAnswer()
    {
        $result = (new CareersService())->screening()->answer(
            (string) $this->request->getPost('screening_token'),
            $this->request->getPost('screening'),
            $this->request->getPost('leaves')
        );

        return $this->screeningJson($result, $result['ok'] ? 200 : 422);
    }

    private function screeningJson(array $data, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setJSON($data + [
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    /**
     * Back to the form the application came from: the role page while the
     * role is open, /careers otherwise. Built from the posted id, never from
     * the Referer, so the redirect cannot be pointed off site.
     */
    private function returnUrl(CareersService $svc): string
    {
        $roleId = (int) $this->request->getPost('role_id');
        if ($roleId > 0) {
            $role = (new \App\Models\CareerRoleModel())->find($roleId);
            if (is_array($role) && $svc->isOpen($role)) {
                return $svc->url($role) . '#apply';
            }
        }

        return base_url('careers') . '#apply';
    }
}
