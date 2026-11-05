<?php

namespace App\Controllers;

use App\Models\DirectoryVerificationModel;
use App\Services\DirectorySettings;
use App\Services\VerificationService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Our public sale prices as JSON, for webscheduler.co.za (the brand site,
 * its own repo), which bakes them in at build time and refreshes them in the
 * browser, so a price changed on /admin/settings shows there without a
 * release.
 *
 * co.za only: the admin, and so every price, lives there, and one feed for
 * both sites keeps the brand site to one fetch. .app answers 404.
 *
 * Only what any visitor can already read on our own pages: never the USD/ZAR
 * rate, and no rand figure for Plus (the .app copy rule). A plan that is not
 * on sale is null rather than a stale number.
 */
class Pricing extends BaseController
{
    /** The one origin allowed to read this from a browser. */
    public const BRAND_ORIGIN = 'https://webscheduler.co.za';

    /** The brand site's dev server (its site:dev), allowed outside production only. */
    public const DEV_ORIGIN = 'http://localhost:8096';

    public function json()
    {
        if (site()->isApp()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $settings     = new DirectorySettings();
        $verification = new VerificationService();
        $config       = config('Directory');
        $partners     = config('Partners');

        $badgeOn = $verification->isEnabled();
        $plusOn  = $verification->plusOffered();

        $body = [
            'za' => [
                'verified' => $badgeOn ? $this->plan(
                    $settings->badgePrice(),
                    $verification->amountFor(DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::CYCLE_YEARLY),
                    'ZAR'
                ) : null,
                'profileSetup' => $this->once($settings->profileSetupRand(), 'ZAR'),
                // What a partner earns per referred Verified business on the
                // monthly price, as /partners works it out (partners.php).
                'partner' => $badgeOn && $partners->enabled ? $this->partner((float) $settings->badgePrice(), $partners) : null,
            ],
            'intl' => [
                'plus' => $plusOn ? $this->plan(
                    (string) $settings->plusPriceUsd(),
                    $verification->yearlyOffered(DirectoryVerificationModel::PLAN_INTERNATIONAL) ? $settings->plusYearlyPriceUsd() : null,
                    'USD'
                ) : null,
                'profileSetup' => $this->once($settings->profileSetupUsd(), 'USD'),
            ],
        ];

        return $this->response
            ->setHeader('Access-Control-Allow-Origin', $this->allowedOrigin())
            ->setHeader('Vary', 'Origin')
            // Five minutes: a price change shows on the brand site soon
            // enough, and the build plus every visitor do not hit PHP each time.
            // Removed first: CI4's no-cache default would otherwise be merged
            // in and its no-store would win.
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'public, max-age=300')
            ->setHeader('X-Robots-Tag', 'noindex')
            ->setJSON($body);
    }

    /**
     * The brand site, or its dev server when that is who is asking and this is
     * not production. One origin per response, so it is chosen, never a list.
     */
    private function allowedOrigin(): string
    {
        $origin = $this->request->getHeaderLine('Origin');

        return ENVIRONMENT !== 'production' && $origin === self::DEV_ORIGIN ? self::DEV_ORIGIN : self::BRAND_ORIGIN;
    }

    /**
     * @return array{monthly:string,yearly:?string,display:string,displayYearly:?string}
     */
    private function plan(string $monthly, ?string $yearly, string $currency): array
    {
        return [
            'monthly'       => $monthly,
            'yearly'        => $yearly,
            'display'       => $this->display($monthly, $currency),
            'displayYearly' => $yearly === null ? null : $this->display($yearly, $currency),
        ];
    }

    /**
     * Commission on one referred business, twelve months of it, and twenty
     * businesses a month: the three figures the brand site's partner page
     * quotes.
     *
     * @return array<string,string>
     */
    private function partner(float $monthly, \Config\Partners $partners): array
    {
        $perMonth = round($monthly * $partners->commissionRate / 100, 2);
        $money    = static fn (float $v): string => 'R' . number_format($v, 2, '.', ' ');

        return [
            'percent'  => rtrim(rtrim(number_format($partners->commissionRate, 2, '.', ''), '0'), '.'),
            'perMonth' => $money($perMonth),
            'firstYear' => $money($perMonth * $partners->commissionMonths),
            'twenty'   => $money($perMonth * 20),
        ];
    }

    /** @return array{amount:string,display:string} */
    private function once(string $amount, string $currency): array
    {
        return ['amount' => $amount, 'display' => $this->display($amount, $currency)];
    }

    /**
     * "R30", "R29.99", "US$8.99": how the brand site writes a price. Whole
     * amounts drop the cents; dollars say US$ so a visitor never reads them
     * as some other dollar.
     */
    private function display(string $amount, string $currency): string
    {
        $value = (float) $amount;
        $text  = floor($value) === $value ? number_format($value, 0, '.', ' ') : number_format($value, 2, '.', ' ');

        return ($currency === 'USD' ? 'US$' : 'R') . $text;
    }
}
