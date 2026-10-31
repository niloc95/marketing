<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Google Search Console and GA4 Data API, read nightly by GoogleDataService
 * (php spark directory:stats). Both APIs are free; nothing here is billed.
 *
 * All empty by default, which switches the pull off: locally, in tests, and
 * on production until the owner has set up the service account. Set in .env:
 *
 *   google.credentialsPath   = /home/deploy/keys/google-stats.json
 *       The service account's JSON key, kept outside the repo and the web
 *       root, readable by the deploy user only. The account needs the Search
 *       Console API and the Google Analytics Data API enabled, and is added
 *       as a user on each Search Console property and as Viewer on each GA4
 *       property below.
 *   google.searchConsoleZa   = sc-domain:webscheduler.co.za
 *   google.searchConsoleApp  = sc-domain:webscheduler.app
 *       The Search Console property: "sc-domain:…" for a domain property, or
 *       the URL prefix ("https://local.webscheduler.co.za/"). Only pages on
 *       the site's own host are read, so a domain property that also covers
 *       the marketing site is fine.
 *   google.analyticsPropertyZa  = 123456789
 *   google.analyticsPropertyApp = 987654321
 *       The numeric GA4 property id (Admin, Property details), not the G-…
 *       measurement id.
 */
class Google extends BaseConfig
{
    public string $credentialsPath = '';

    public string $searchConsoleZa  = '';
    public string $searchConsoleApp = '';

    public string $analyticsPropertyZa  = '';
    public string $analyticsPropertyApp = '';

    /** Search Console data settles about three days after the day it covers. */
    public int $searchConsoleLagDays = 3;

    /** GA4 reports for a day are final the day after. */
    public int $analyticsLagDays = 1;

    /** Each night, look this far back for days that were never pulled (an outage, a missed cron). */
    public int $catchUpDays = 10;

    /** The Search Console property for a site key, '' when not set. */
    public function searchConsoleFor(string $site): string
    {
        return trim($site === Sites::APP ? $this->searchConsoleApp : $this->searchConsoleZa);
    }

    /** The GA4 property id for a site key, '' when not set. */
    public function analyticsPropertyFor(string $site): string
    {
        return trim($site === Sites::APP ? $this->analyticsPropertyApp : $this->analyticsPropertyZa);
    }
}
