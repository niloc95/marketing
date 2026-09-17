<?php

namespace App\Controllers;

/**
 * Static legal pages.
 *
 * Thin by design — these are documents, not features. The copy lives in the views so
 * it can be edited without touching PHP logic, and each view builds its own canonical
 * and last-updated date from the constants below.
 */
class Legal extends BaseController
{
    /**
     * When each policy text was last substantively changed.
     *
     * Hardcoded rather than derived from filemtime(): a whitespace edit or a fresh
     * deployment checkout would silently claim the policy changed, and a visitor
     * comparing this against their own records deserves the real date.
     *
     * Kept per document rather than as one shared date, so that revising one policy
     * does not date-stamp the other two as changed when their text is untouched.
     *
     * Public because signup records the terms date as the version accepted
     * (MarketingConsentService::signupColumns()), so the two cannot disagree.
     */
    public const LAST_UPDATED = [
        'privacy' => '2026-09-17',
        'terms'   => '2026-09-17',
        'cookies' => '2026-08-04',
    ];

    public function privacy()
    {
        return view('legal/privacy', ['lastUpdated' => self::LAST_UPDATED['privacy']]);
    }

    public function terms()
    {
        return view('legal/terms', ['lastUpdated' => self::LAST_UPDATED['terms']]);
    }

    public function cookies()
    {
        return view('legal/cookies', ['lastUpdated' => self::LAST_UPDATED['cookies']]);
    }
}
