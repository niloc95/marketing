<?php

namespace App\Controllers;

use App\Models\DirectoryReferralModel;
use App\Services\DirectoryService;
use App\Services\ReferralService;

/**
 * "Recommend a business" — a visitor names a business that should be listed.
 *
 * The form stores a referral for an admin to review and emails nobody but the
 * admin; see ReferralService for why the business is only ever contacted from
 * /admin/referrals. The spam defences are Contact::submit()'s, unchanged.
 *
 * The stop link from an invite is GET-asks, POST-acts, like unsubscribe/*:
 * mail scanners prefetch every link, and a prefetch must not opt anyone out.
 */
class Referral extends BaseController
{
    /** Nobody reads the page and fills the form this fast. */
    private const MIN_FORM_SECONDS = 3;

    private const SENT_MESSAGE = "Thanks — we'll take a look and, if they're a good fit, invite them to list for free.";

    public function index()
    {
        // Stamped here, checked in submit() — see the timing floor below.
        session()->set('recommend_form_rendered_at', time());

        $svc = new DirectoryService();

        return view('directory/recommend', [
            // A failed submission's own input wins over the search it came from.
            'old'           => session()->getFlashdata('old') ?? $this->searchPrefill($svc),
            'errors'        => session()->getFlashdata('errors') ?? [],
            'categories'    => $svc->categories(),
            'provinces'     => $svc->provinces(),
            'relationships' => DirectoryReferralModel::RELATIONSHIPS,
        ]);
    }

    /**
     * Carry the category and place over from an empty search, which is where
     * the "Recommend it" link lives. Only values that match our own lists.
     *
     * @return array<string,string>
     */
    private function searchPrefill(DirectoryService $svc): array
    {
        $old = [];

        $slug = (string) ($this->request->getGet('category') ?? '');
        foreach ($svc->categories() as $cat) {
            if ($slug !== '' && ($cat['slug'] ?? '') === $slug) {
                $old['category_id'] = (string) $cat['id'];
            }
        }

        $province = (string) ($this->request->getGet('province') ?? '');
        if (in_array($province, $svc->provinces(), true)) {
            $old['province'] = $province;
        }

        $city = (string) ($this->request->getGet('city') ?? '');
        if ($city !== '' && mb_strlen($city) <= 120) {
            $old['city'] = $city;
        }

        return $old;
    }

    public function submit()
    {
        // Honeypot, same field name as the other public forms. Reported as
        // success so a bot learns nothing.
        if (trim((string) $this->request->getPost('company_website_hp')) !== '') {
            return $this->fakeSuccess();
        }

        $renderedAt = (int) session('recommend_form_rendered_at');
        if ($renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            return $this->fakeSuccess();
        }

        // It only emails the admin, but it stores what strangers type, so the
        // IP is throttled as for /contact. The referrer's address is optional
        // here, so its budget only applies when one is given.
        $referrer  = strtolower(trim((string) $this->request->getPost('referrer_email')));
        $throttler = service('throttler');
        $ipKey     = 'recommend-ip-' . md5((string) $this->request->getIPAddress());
        $mailKey   = 'recommend-from-' . md5($referrer);

        if ($throttler->check($ipKey, 3, HOUR) === false
            || ($referrer !== '' && $throttler->check($mailKey, 2, HOUR) === false)) {
            return redirect()->to(base_url('recommend'))->with('old', $this->request->getPost())
                ->with('error', 'Too many recommendations. Please wait a little while and try again.');
        }

        $result = (new ReferralService())->submit(
            (array) $this->request->getPost(),
            (string) $this->request->getIPAddress()
        );

        if (! $result['ok']) {
            return redirect()->to(base_url('recommend'))
                ->with('errors', $result['errors'])
                ->with('old', $this->request->getPost())
                ->with('error', 'Please correct the highlighted fields.');
        }

        session()->remove('recommend_form_rendered_at');

        return redirect()->to(base_url('recommend'))->with('success', self::SENT_MESSAGE);
    }

    public function stopConfirm(string $token)
    {
        $referral = (new ReferralService())->findByToken($token);

        return $this->stopPage($referral, $token, false);
    }

    public function stop(string $token)
    {
        $svc      = new ReferralService();
        $referral = $svc->findByToken($token);
        if ($referral !== null) {
            $svc->suppress($token);
        }

        return $this->stopPage($referral, $token, true);
    }

    /** @param array<string,mixed>|null $referral */
    private function stopPage(?array $referral, string $token, bool $done)
    {
        $html = view('directory/recommend_stop', [
            'referral' => $referral,
            'token'    => $token,
            'done'     => $done,
        ]);

        return $referral === null
            ? $this->response->setStatusCode(404)->setBody($html)
            : $html;
    }

    private function fakeSuccess()
    {
        return redirect()->to(base_url('recommend'))->with('success', self::SENT_MESSAGE);
    }
}
