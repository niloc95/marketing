<?php

namespace App\Controllers;

use App\Models\DirectoryPartnerModel;
use App\Services\PartnerService;
use App\Services\VerificationService;

/**
 * The Partner Program's public side: the pitch and application form, the
 * /p/{code} link, and the partner's own dashboard.
 *
 * The dashboard signs in like /manage does: an emailed single use link traded
 * for a session, no password. The rules are all in PartnerService.
 */
class Partners extends BaseController
{
    /** Nobody reads the page and fills the form this fast. */
    private const MIN_FORM_SECONDS = 3;

    public function index()
    {
        $svc = new PartnerService();
        if (! $svc->isEnabled()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        session()->set('partner_form_rendered_at', time());

        $verification = new VerificationService();

        return view('directory/partners', [
            'old'      => session()->getFlashdata('old') ?? [],
            'errors'   => session()->getFlashdata('errors') ?? [],
            'config'   => $svc->config(),
            'svc'      => $svc,
            'price'    => $verification->isEnabled() ? (float) $verification->monthlyAmount() : null,
        ]);
    }

    public function apply()
    {
        $svc = new PartnerService();
        if (! $svc->isEnabled()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Honeypot and timing floor, as on /recommend. Reported as success so
        // a bot learns nothing.
        $renderedAt = (int) session('partner_form_rendered_at');
        if (trim((string) $this->request->getPost('company_website_hp')) !== ''
            || $renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            return redirect()->to(base_url('partners#apply'))->with('success', PartnerService::APPLIED_MESSAGE);
        }

        $email     = strtolower(trim((string) $this->request->getPost('email')));
        $throttler = service('throttler');
        if ($throttler->check('partner-ip-' . md5((string) $this->request->getIPAddress()), 3, HOUR) === false
            || ($email !== '' && $throttler->check('partner-to-' . md5($email), 2, HOUR) === false)) {
            return redirect()->to(base_url('partners#apply'))->with('old', $this->request->getPost())
                ->with('error', 'Too many applications. Please wait a little while and try again.');
        }

        $result = $svc->apply((array) $this->request->getPost());
        if (! $result['ok']) {
            return redirect()->to(base_url('partners#apply'))
                ->with('errors', $result['errors'])
                ->with('old', $this->request->getPost())
                ->with('error', 'Please correct the highlighted fields.');
        }

        session()->remove('partner_form_rendered_at');

        return redirect()->to(base_url('partners#apply'))->with('success', PartnerService::APPLIED_MESSAGE);
    }

    /**
     * A partner's link. Counts the click, sets the cookie and lands on the
     * home page. An unknown or suspended code still lands there, just without
     * the cookie, so a dead link never shows a visitor an error.
     */
    public function track(string $code)
    {
        $svc      = new PartnerService();
        $partner  = $svc->track($code, (string) $this->request->getUserAgent());
        $redirect = redirect()->to(base_url('/'));

        if ($partner !== null) {
            $redirect->setCookie(PartnerService::COOKIE, (string) $partner['code'], $svc->cookieSeconds());
        }

        return $redirect;
    }

    // ------------------------------------------------------------- signing in

    public function login()
    {
        if ($this->partner() !== null) {
            return redirect()->to(base_url('partners/dashboard'));
        }

        return view('directory/partners_login');
    }

    public function requestLogin()
    {
        $email     = trim((string) $this->request->getPost('email'));
        $throttler = service('throttler');

        if ($throttler->check('partner-login-ip-' . md5((string) $this->request->getIPAddress()), 5, MINUTE * 10) === false
            || ($email !== '' && $throttler->check('partner-login-to-' . md5(strtolower($email)), 3, MINUTE * 10) === false)) {
            return redirect()->to(base_url('partners/login'))
                ->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }

        if ($email !== '') {
            (new PartnerService())->requestLogin($email);
        }

        return redirect()->to(base_url('partners/login'))->with('info', PartnerService::LOGIN_MESSAGE);
    }

    public function redeem(string $token)
    {
        $partner = (new PartnerService())->redeemLogin($token);
        if ($partner === null) {
            return redirect()->to(base_url('partners/login'))
                ->with('error', 'That link is invalid or has expired. Request a new one below.');
        }

        // The link was a bearer credential; the session id it arrived with dies here.
        session()->regenerate(true);
        session()->set(PartnerService::SESSION_KEY, (int) $partner['id']);

        return redirect()->to(base_url('partners/dashboard'));
    }

    public function signout()
    {
        session()->remove(PartnerService::SESSION_KEY);

        return redirect()->to(base_url('partners/login'))->with('info', 'You are signed out.');
    }

    // -------------------------------------------------------------- dashboard

    public function dashboard()
    {
        $partner = $this->partner();
        if ($partner === null) {
            return redirect()->to(base_url('partners/login'));
        }

        $svc = new PartnerService();

        return view('directory/partners_dashboard', [
            'partner' => $partner,
            'd'       => $svc->dashboard($partner),
            'svc'     => $svc,
            'errors'  => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function saveBank()
    {
        $partner = $this->partner();
        if ($partner === null) {
            return redirect()->to(base_url('partners/login'));
        }

        $result = (new PartnerService())->saveBankDetails((int) $partner['id'], (array) $this->request->getPost());

        return redirect()->to(base_url('partners/dashboard#bank'))
            ->with($result['ok'] ? 'success' : 'error', $result['message'])
            ->with('errors', $result['errors']);
    }

    /** @return array<string,mixed>|null */
    private function partner(): ?array
    {
        $id = (int) session(PartnerService::SESSION_KEY);
        if ($id === 0) {
            return null;
        }

        $p = (new DirectoryPartnerModel())->find($id);
        if (! is_array($p) || ! in_array($p['status'], [DirectoryPartnerModel::STATUS_APPROVED, DirectoryPartnerModel::STATUS_SUSPENDED], true)) {
            session()->remove(PartnerService::SESSION_KEY);

            return null;
        }

        return $p;
    }
}
