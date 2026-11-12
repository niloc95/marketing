<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Controllers\Manage;
use App\Models\DirectoryReviewModel;
use App\Models\DirectoryVerificationModel;
use App\Models\JobPostModel;
use App\Services\JobBoardService;
use App\Services\ListingQualityService;
use App\Services\ListingStatsService;
use App\Services\VerificationService;

/**
 * The parts of the old /manage/edit dashboard that are not the profile form,
 * each a page of the business area (owner, 10 Nov 2026): the Verified badge
 * or Plus (with the International Listing plan), Jobs, Reviews and Stats.
 * The panels are the same partials; their forms still post to the routes
 * they always did (manage/verification/*, which payment returns rely on).
 *
 * Routes pass businessauth:owner plus the page's permission.
 */
class Owner extends BaseController
{
    public function badge()
    {
        $listing      = service('businessContext')->listing();
        $id           = (int) $listing['id'];
        $verification = new VerificationService();
        $badgeRow     = $verification->forListing($id);
        $hostingRow   = $verification->forListing($id, DirectoryVerificationModel::PLAN_INTERNATIONAL);

        return view('business/badge', [
            'profileForm'         => true,
            'listing'             => $listing,
            'verificationOffered' => $verification->isEnabled(),
            'verificationPayable' => $verification->canTakePayment(),
            'verificationAmount'  => $verification->monthlyAmount(),
            'verificationYearly'  => $verification->amountFor(DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::CYCLE_YEARLY),
            'verification'        => $badgeRow,
            'verificationPending' => Manage::paymentPending($badgeRow),
            // The International Listing plan, only where it applies.
            'hostingRequired' => $verification->requiresSubscription($listing),
            // Plus on .app: offered to a business abroad, and shown to one that
            // still holds it even if it is no longer on sale.
            'plusShown'     => $verification->plusMode()
                && ($verification->plusAvailableFor($listing) || listing_subscription_active($listing)),
            'plusUsd'       => $verification->plusPriceUsd(),
            'plusUsdYearly' => $verification->yearlyOffered(DirectoryVerificationModel::PLAN_INTERNATIONAL) ? $verification->plusYearlyPriceUsd() : null,
            'hostingAmount' => $verification->internationalAmount(),
            'hosting'       => $hostingRow,
            'hostingPending' => Manage::paymentPending($hostingRow),
        ]);
    }

    public function jobs()
    {
        $listing = service('businessContext')->listing();

        return view('business/jobs', [
            'profileForm' => true,
            'listing' => $listing,
            'posts'   => (new JobPostModel())->forListing((int) $listing['id']),
            'svc'     => new JobBoardService(),
        ]);
    }

    public function reviews()
    {
        $listing = service('businessContext')->listing();
        $model   = new DirectoryReviewModel();
        $pager   = pager_result((int) $this->request->getGet('page'), 50, $model->countForOwner((int) $listing['id']));

        return view('business/reviews', [
            'profileForm' => true,
            'listing' => $listing,
            'reviews' => $model->forOwner((int) $listing['id'], 50, ($pager['page'] - 1) * 50),
            'pager'   => $pager,
        ]);
    }

    public function stats()
    {
        $listing  = service('businessContext')->listing();
        $strength = (new ListingQualityService())->strength($listing);
        $za       = site()->forRow($listing) === \Config\Sites::ZA;

        return view('business/stats', [
            'listing' => $listing,
            // Basic for everyone, full with the badge or Plus. Nothing to show
            // until the profile is public.
            'stats' => $listing['status'] === 'published'
                ? (new ListingStatsService())->forOwner($listing, $strength['items'], listing_has_premium($listing))
                : null,
            'upgradeName' => $za ? 'Verified' : 'Plus',
        ]);
    }
}
