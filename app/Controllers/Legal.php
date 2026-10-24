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
        'privacy' => '2026-10-22',
        'terms'   => '2026-10-21',
        'cookies' => '2026-10-22',
    ];

    /**
     * local.webscheduler.app's own Terms and Privacy (Views/legal/app/), dated
     * separately. They are drafts for legal review: the app site must not go
     * live on them until that review has happened. The cookie policy is the
     * same on both sites and keeps one date.
     */
    public const APP_LAST_UPDATED = [
        'privacy' => '2026-10-24',
        'terms'   => '2026-10-24',
    ];

    /**
     * The terms date a signup on this site accepts, stored as terms_version
     * (a date, VARCHAR(10)). Which site's terms it was is the profile's
     * country (Config\Sites), so the date alone is enough.
     */
    public static function termsVersion(): string
    {
        return service('site')->isApp() ? self::APP_LAST_UPDATED['terms'] : self::LAST_UPDATED['terms'];
    }

    public function privacy()
    {
        if (service('site')->isApp()) {
            return view('legal/app/privacy', ['lastUpdated' => self::APP_LAST_UPDATED['privacy']]);
        }

        return view('legal/privacy', ['lastUpdated' => self::LAST_UPDATED['privacy']]);
    }

    public function terms()
    {
        if (service('site')->isApp()) {
            return view('legal/app/terms', ['lastUpdated' => self::APP_LAST_UPDATED['terms']]);
        }

        return view('legal/terms', ['lastUpdated' => self::LAST_UPDATED['terms']]);
    }

    public function cookies()
    {
        return view('legal/cookies', [
            'lastUpdated' => self::LAST_UPDATED['cookies'],
            // The list of third parties below must stay true: a YouTube hero
            // background puts Google's player on the home page.
            'youtubeHero' => ((new \App\Services\HeroImageService())->background()['type'] ?? '') === 'youtube',
        ]);
    }
}
