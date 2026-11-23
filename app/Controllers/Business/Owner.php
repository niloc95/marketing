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
            // The welcome pack card, while Verified or Plus is live.
            'welcomePack'         => (new \App\Services\WelcomePackService())->available($listing),
            'welcomePackPlan'     => listing_is_verified_business($listing) ? 'Verified' : 'Plus',
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

    /**
     * The user manual (business/manual.php): how every part of the owner
     * area works, worded for this profile's site, plan and kind, and
     * printable (Print or save as PDF). Only facts and numbers from the
     * code: the limits below come from the services that enforce them.
     */
    public function manual()
    {
        $listing  = service('businessContext')->listing();
        $isApp    = site()->forRow($listing) === \Config\Sites::APP;
        $setup    = new \App\Services\Bookings\BookingSetupService();
        $bookable = $setup->isOn($listing) && $setup->whyNot($listing) === null;

        return view('business/manual', [
            'listing'     => $listing,
            'isApp'       => $isApp,
            'planName'    => $isApp ? 'Plus' : 'Verified',
            'premium'     => listing_has_premium($listing),
            'bookable'    => $bookable,
            'bookingsOn'  => $bookable || bookings_in_premium(site()->forRow($listing)),
            'reviewsOff'  => \App\Services\ReviewService::offForGroup($listing),
            'noun'        => listing_noun($listing),
            'audience'    => listing_audience($listing),
            'profileUrl'  => listing_url($listing),
            'welcomePack' => (new \App\Services\WelcomePackService())->available($listing),
            'company'     => config('Company'),
            // A register to quote (HPCSA, SAVC…): South African regulated categories only.
            'regulator'   => (static function (array $l): ?array {
                $ref = listing_category_ref($l);

                return \App\Libraries\ProfessionalRegistration::regulatorFor($ref['slug'], $ref['group'], \App\Libraries\ProfessionalRegistration::countryOf($l));
            })($listing),
            'limits'      => [
                'gallery'    => \App\Controllers\Business\Profile::GALLERY_MAX, // the uploads trait's cap
                'team'       => \App\Services\TeamMemberService::MAX_MEMBERS,
                'branches'   => \App\Services\PracticeLocationService::MAX_LOCATIONS,
                'cards'      => \App\Services\ListingSectionService::MAX_EXTRA,
                'cardChars'  => \App\Services\ListingSectionService::MAX_PLAIN_LENGTH,
                'brands'     => \App\Services\ListingBrandService::MAX_BRANDS,
                'reply'      => config('Reviews')->replyMax,
                'jobDays'    => config('JobBoard')->defaultDays,
                'jobMax'     => config('JobBoard')->maxDays,
                'msgMin'     => \App\Services\ListingMessageService::MIN_MESSAGE,
                'msgMax'     => \App\Services\ListingMessageService::MAX_MESSAGE,
                'msgFiles'   => \App\Services\ListingMessageService::MAX_FILES,
                'msgFileMb'  => (int) (\App\Services\ListingMessageService::MAX_FILE_BYTES / 1048576),
                'msgTotalMb' => (int) (\App\Services\ListingMessageService::MAX_TOTAL_BYTES / 1048576),
                'msgLinks'   => \App\Services\ListingMessageService::MAX_LINKS,
                'msgPerDay'  => \App\Services\ListingMessageService::LIMIT_LISTING,
                'msgSender'  => \App\Services\ListingMessageService::LIMIT_SENDER,
            ],
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
