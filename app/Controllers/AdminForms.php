<?php

namespace App\Controllers;

use App\Services\FormHealthService;
use Config\Sites;

/**
 * /admin/forms: form health. What every public and owner form did, one site
 * at a time: submits pressed, arrived, saved, refused (and on which field),
 * throttled, expired pages, failed mail and lost submissions, with the
 * alert emails sent. Read only; FormHealthService does the counting.
 * Behind the admin filter with the rest of /admin (see Routes.php).
 */
class AdminForms extends BaseController
{
    /** The periods offered, in days. */
    private const PERIODS = [1, 7, 30, 90];

    public function index()
    {
        $site    = (string) $this->request->getGet('site') === Sites::APP ? Sites::APP : Sites::ZA;
        $days    = (int) $this->request->getGet('days');
        $days    = in_array($days, self::PERIODS, true) ? $days : 7;
        $forms   = config('Forms');
        $form    = (string) $this->request->getGet('form');
        $form    = isset($forms->forms[$form]) ? $form : null;
        $outcome = (string) $this->request->getGet('outcome');
        $outcome = in_array($outcome, array_merge(FormHealthService::SERVER_OUTCOMES, FormHealthService::BROWSER_OUTCOMES), true) ? $outcome : null;

        return view('admin/forms', [
            'site'    => $site,
            'days'    => $days,
            'periods' => self::PERIODS,
            'form'    => $form,
            'outcome' => $outcome,
            'config'  => $forms,
            'data'    => (new FormHealthService())->dashboard($site, $days, $form, $outcome),
        ]);
    }
}
