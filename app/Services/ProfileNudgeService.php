<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Models\DirectoryListingModel;
use Throwable;

/**
 * The one-off "finish your profile" email.
 *
 * Publishing is never gated on the quality score — a near-empty new profile is
 * usually one that is about to be filled in. This is the other half of that
 * bargain: a few days after going live, an owner still under
 * Config\Directory::$qualityTarget gets one email naming the few things that
 * would lift it most, with a button straight into their manage page.
 *
 * Once per listing, ever. quality_nudge_sent_at is the "once", and it is
 * stamped only after Mailer reports success, so a mail outage leaves the row
 * due and the next nightly run tries again.
 *
 * Only to owners with marketing_opt_in set. Those columns now hold a
 * service-email preference — a report on the owner's own profile — and this is
 * the same kind of mail (MarketingConsentService has the history). An owner
 * who has unsubscribed is never nudged, and the footer carries the same
 * unsubscribe link.
 *
 * Run by directory:quality:nudge, after the nightly quality recalculation.
 */
class ProfileNudgeService
{
    /**
     * How far back the window reaches. Without a lower bound the first run
     * would mail every thin profile in the back catalogue at once; with it,
     * only profiles that went live in the last fortnight or so are eligible,
     * and the older ones were never promised this email anyway.
     */
    public const WINDOW_DAYS = 14;

    /** How many outstanding steps the email lists. More reads as a chore. */
    public const STEPS = 4;

    private DirectoryListingModel $listings;
    private ListingQualityService $quality;

    public function __construct()
    {
        $this->listings = new DirectoryListingModel();
        $this->quality  = new ListingQualityService();
    }

    /**
     * Listings due a nudge: published between $days and $days + WINDOW_DAYS
     * ago, scored and still under $target, never nudged, opted in to owner
     * email, and with an address to send to.
     *
     * Unscored rows are skipped rather than let through: the email quotes the
     * score, and the 03:15 recalculation will have scored them by tomorrow.
     *
     * @return list<array<string,mixed>>
     */
    public function due(int $days, int $target, int $limit = 200): array
    {
        $newest = date('Y-m-d H:i:s', strtotime('-' . max(0, $days) . ' days'));
        $oldest = date('Y-m-d H:i:s', strtotime('-' . (max(0, $days) + self::WINDOW_DAYS) . ' days'));

        return $this->listings
            ->where('status', 'published')
            ->where('published_at <=', $newest)
            ->where('published_at >=', $oldest)
            ->where('quality_nudge_sent_at', null)
            ->where('quality_scored_at IS NOT NULL')
            ->where('quality_score <', $target)
            ->where('marketing_opt_in', 1)
            ->where('email IS NOT NULL')
            ->where('email !=', '')
            ->orderBy('published_at', 'ASC')
            ->limit(max(1, $limit))
            ->findAll();
    }

    /**
     * Re-score one listing and, if it is still under $target, email the owner.
     *
     * Returns what happened, for the command to report: 'sent', 'improved'
     * (it crossed the target since last night — stamped so it is not
     * reconsidered, since there is nothing to nudge about), or 'failed'
     * (left unstamped, so it is retried).
     */
    public function nudge(array $listing, int $target): string
    {
        $id = (int) $listing['id'];

        // Fresh numbers, not last night's: an owner who spent this morning on
        // their profile must not be told it is thin.
        $this->quality->recalculate($id);
        $listing = $this->listings->find($id);
        if (! is_array($listing)) {
            return 'failed';
        }

        $strength = $this->quality->strength($listing, null, self::STEPS);
        if ((int) $strength['score'] >= $target) {
            $this->markNudged($id);

            return 'improved';
        }

        $config  = config('Directory');
        $body    = view('emails/profile-strength', [
            'site'       => $config->siteName(),
            'name'       => (string) ($listing['display_name'] ?? ''),
            'score'      => (int) $strength['score'],
            'max'        => (int) $strength['max'],
            'target'     => $target,
            'steps'      => $strength['next'],
            'profileUrl' => base_url('directory/' . $listing['slug']),
            'hasWebsite' => trim((string) ($listing['website'] ?? '')) !== '',
            'manageLink' => $this->manageLink($id),
            'unsubscribe' => (new MarketingConsentService())->unsubscribeUrl($id),
        ]);

        $sent = (new Mailer())->send(
            (string) $listing['email'],
            'Your profile is live. A few minutes will help more people find it',
            $body
        );

        if (! $sent) {
            return 'failed';
        }

        $this->markNudged($id);

        return 'sent';
    }

    private function markNudged(int $id): void
    {
        $this->listings->update($id, ['quality_nudge_sent_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * A live magic link, as VerificationService::ownerLink() gives the badge
     * approval mail: the email asks the owner to do something, and sending them
     * to a sign-in page to request a second email first is the detour that
     * loses them. Minting replaces any older manage link, which is acceptable
     * for an email sent once. A failure falls back to the sign-in page.
     */
    private function manageLink(int $id): string
    {
        try {
            $token = (new DirectoryListingMutationService())
                ->mintManageToken($id, config('Directory')->approvalLinkTtl);

            return base_url('manage/' . $token);
        } catch (Throwable $e) {
            log_message('error', 'Could not mint a nudge link for listing {id}: {msg}', [
                'id'  => $id,
                'msg' => trim((string) preg_replace('/\s+/', ' ', $e->getMessage())),
            ]);

            return base_url('manage');
        }
    }
}
