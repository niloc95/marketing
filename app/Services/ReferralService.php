<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryInviteSuppressionModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryReferralModel;
use Config\Directory as DirectoryConfig;

/**
 * "Recommend a business": a visitor names a business that should be listed.
 *
 * The one rule everything here serves: **nothing emails the business on a
 * visitor's say-so.** A referral lands in /admin/referrals and waits. Only an
 * admin's Invite click sends mail to the business, once per referral, with a
 * "don't contact me again" link that blocks every later referral of that
 * address. A form that mailed the business directly would let anyone make our
 * domain email any address — spam, a POPIA s69 problem, and SES reputation.
 *
 * Nothing here can block a signup either. The signup hooks (attachListing,
 * markListed) run after the listing is saved and swallow their own failures.
 */
class ReferralService
{
    /** How long an invite's pre-filled signup link and stop link stay valid. */
    public const INVITE_TTL_DAYS = 60;

    /** POPIA retention: contact details are wiped this long after submission. */
    public const RETAIN_MONTHS = 12;

    /** ...or this long after an admin dismisses the referral. */
    public const DISMISSED_RETAIN_DAYS = 30;

    public const MAX_NOTE = 1000;

    private DirectoryReferralModel $referrals;
    private DirectoryConfig $site;

    public function __construct()
    {
        $this->referrals = new DirectoryReferralModel();
        $this->site      = config('Directory');
    }

    // ---------------------------------------------------------------- submit

    /**
     * Store a referral from the public form. Emails the admin, never the
     * business.
     *
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
     */
    public function submit(array $in, string $ip): array
    {
        $d = [
            'business_name'   => $this->clean($in['business_name'] ?? '', 200),
            'business_email'  => strtolower($this->clean($in['business_email'] ?? '', 190)),
            'business_phone'  => $this->clean($in['business_phone'] ?? '', 40),
            'website'         => $this->clean($in['website'] ?? '', 255),
            'category_id'     => null,
            'city'            => $this->clean($in['city'] ?? '', 120),
            'province'        => $this->clean($in['province'] ?? '', 40),
            'note'            => $this->cleanText($in['note'] ?? '', self::MAX_NOTE),
            'referrer_name'   => $this->clean($in['referrer_name'] ?? '', 120),
            'referrer_email'  => strtolower($this->clean($in['referrer_email'] ?? '', 190)),
            'notify_referrer' => empty($in['notify_referrer']) ? 0 : 1,
            'relationship'    => (string) ($in['relationship'] ?? ''),
        ];

        $errors = [];

        if ($d['business_name'] === '') {
            $errors['business_name'] = 'What is the business called?';
        }
        if (! filter_var($d['business_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['business_email'] = $d['business_email'] === ''
                ? 'We need their email to invite them.'
                : 'That email address does not look right.';
        }
        if ($d['business_phone'] === '') {
            $errors['business_phone'] = 'What is their phone number?';
        }
        // The one optional business field: plenty of small businesses have no
        // website, and demanding one would lose the recommendation.
        if ($d['website'] !== '') {
            $url = (new DirectoryListingMutationService())->normaliseUrl($d['website']);
            if ($url === null || safe_external_url($url) === '') {
                $errors['website'] = 'That website address does not look right.';
            } else {
                $d['website'] = $url;
            }
        }
        if ($d['city'] === '') {
            $errors['city'] = 'Which town or suburb are they in?';
        }
        if (! in_array($d['province'], (new DirectoryService())->provinces(), true)) {
            $d['province']      = '';
            $errors['province'] = 'Choose a province.';
        }
        if (mb_strlen($d['note']) < 10) {
            $errors['note'] = 'Tell us a little about why you recommend them.';
        }
        if (! array_key_exists($d['relationship'], DirectoryReferralModel::RELATIONSHIPS)) {
            $errors['relationship'] = 'How do you know them?';
        }
        if ($d['referrer_name'] === '') {
            $errors['referrer_name'] = 'Please tell us your name.';
        }
        if (! filter_var($d['referrer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['referrer_email'] = $d['referrer_email'] === ''
                ? 'Please give your email address.'
                : 'That email address does not look right.';
        }

        $categoryId = (int) ($in['category_id'] ?? 0);
        $cat        = $categoryId > 0 ? (new DirectoryCategoryModel())->where('is_active', 1)->find($categoryId) : null;
        if (is_array($cat)) {
            $d['category_id'] = $categoryId;
        } else {
            $errors['category_id'] = 'Choose what kind of business it is.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $d];
        }

        $row = $d + [
            'status'  => DirectoryReferralModel::STATUS_PENDING,
            'ip_hash' => hash('sha256', $ip),
        ];
        foreach (['business_email', 'business_phone', 'website', 'city', 'province', 'note', 'referrer_name', 'referrer_email'] as $nullable) {
            if ($row[$nullable] === '') {
                $row[$nullable] = null;
            }
        }

        $id = (int) $this->referrals->insert($row, true);
        $this->notifyAdmin($this->referrals->find($id) ?? $row);

        return ['ok' => true, 'errors' => [], 'data' => $d];
    }

    /**
     * The listing this referral probably already is, for the admin's warning.
     * Same email first, then the same name in the same town.
     *
     * @param array<string,mixed> $referral
     *
     * @return array<string,mixed>|null
     */
    public function likelyDuplicate(array $referral): ?array
    {
        $listings = new DirectoryListingModel();
        $email    = (string) ($referral['business_email'] ?? '');

        if ($email !== '') {
            $hit = $listings->findActiveByEmail($email);
            if ($hit !== null) {
                return $hit;
            }
        }

        $name = trim((string) ($referral['business_name'] ?? ''));
        $city = trim((string) ($referral['city'] ?? ''));
        if ($name === '' || $city === '') {
            return null;
        }

        $hit = $listings->where('display_name', $name)->where('city', $city)->first();

        return is_array($hit) ? $hit : null;
    }

    // ---------------------------------------------------------------- invite

    /**
     * Whether Invite should be offered for this row, and if not, why not.
     *
     * @param array<string,mixed> $referral
     */
    public function inviteBlocker(array $referral): ?string
    {
        if (($referral['status'] ?? '') !== DirectoryReferralModel::STATUS_PENDING) {
            return 'Only a pending referral can be invited.';
        }
        $email = (string) ($referral['business_email'] ?? '');
        if ($email === '') {
            return 'No email address to invite. Contact them another way.';
        }
        if ((new DirectoryInviteSuppressionModel())->isSuppressed($email)) {
            return 'This address asked not to be contacted.';
        }
        if ((new DirectoryListingModel())->findActiveByEmail($email) !== null) {
            return 'A business profile already uses this email.';
        }
        // One invite per business, not per referral: five people recommending
        // the same shop must not mean five emails to it.
        $earlier = (new DirectoryReferralModel())
            ->where('business_email', $email)
            ->where('invited_at IS NOT NULL')
            ->where('id !=', (int) ($referral['id'] ?? 0))
            ->countAllResults();
        if ($earlier > 0) {
            return 'This address has already been invited.';
        }

        return null;
    }

    /**
     * Send the business the one invitation this referral allows.
     *
     * @return array{ok:bool,message:string}
     */
    public function invite(int $id): array
    {
        $referral = $this->referrals->find($id);
        if (! is_array($referral)) {
            return ['ok' => false, 'message' => 'That referral no longer exists.'];
        }

        $blocker = $this->inviteBlocker($referral);
        if ($blocker !== null) {
            return ['ok' => false, 'message' => $blocker];
        }

        $token = TokenHash::mint();
        $sent  = $this->sendInvite($referral, $token);
        if (! $sent) {
            return ['ok' => false, 'message' => 'The invitation could not be sent. Check /admin/status and try again.'];
        }

        $this->referrals->update($id, [
            'status'       => DirectoryReferralModel::STATUS_INVITED,
            'invite_token' => TokenHash::hash($token),
            'invited_at'   => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'message' => 'Invitation sent to ' . $referral['business_email'] . '.'];
    }

    public function dismiss(int $id): bool
    {
        $referral = $this->referrals->find($id);
        if (! is_array($referral) || $referral['status'] === DirectoryReferralModel::STATUS_LISTED) {
            return false;
        }

        return $this->referrals->update($id, ['status' => DirectoryReferralModel::STATUS_DISMISSED]);
    }

    // ------------------------------------------------------------ the invite link

    /**
     * The invited referral a raw token belongs to, while its link is valid.
     *
     * @return array<string,mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || ! ctype_xdigit($token) || strlen($token) !== 64) {
            return null;
        }

        $row = $this->referrals
            ->where('invite_token', TokenHash::hash($token))
            ->where('invited_at >=', date('Y-m-d H:i:s', strtotime('-' . self::INVITE_TTL_DAYS . ' days')))
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * The signup form's $old for an invite link: what the referrer told us,
     * keyed as the form's own fields. Empty for an unknown or expired token,
     * and for a referral that has already become a listing.
     *
     * @return array<string,string>
     */
    public function prefillFor(string $token): array
    {
        $r = $this->findByToken($token);
        if ($r === null || $r['status'] !== DirectoryReferralModel::STATUS_INVITED) {
            return [];
        }

        return array_filter([
            'display_name' => (string) $r['business_name'],
            'email'        => (string) ($r['business_email'] ?? ''),
            'phone'        => (string) ($r['business_phone'] ?? ''),
            'website'      => (string) ($r['website'] ?? ''),
            'category_id'  => $r['category_id'] ? (string) $r['category_id'] : '',
            'city'         => (string) ($r['city'] ?? ''),
            'province'     => (string) ($r['province'] ?? ''),
        ], static fn ($v) => $v !== '');
    }

    /**
     * Tie a fresh signup to the invite it came from. Called after the listing
     * is saved; never throws, because it must never cost anyone their listing.
     */
    public function attachListing(string $token, int $listingId): void
    {
        try {
            $r = $this->findByToken($token);
            if ($r !== null && $r['status'] === DirectoryReferralModel::STATUS_INVITED && empty($r['listing_id'])) {
                $this->referrals->update((int) $r['id'], ['listing_id' => $listingId]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Referral attach failed: ' . $e->getMessage());
        }
    }

    /**
     * A listing just confirmed its email: close every referral it answers,
     * and tell the referrers who asked. Matches the invite it signed up from
     * and, failing that, any referral naming the same email — someone who
     * listed themselves without clicking the invite still counts.
     *
     * @param array<string,mixed> $listing
     */
    public function markListed(array $listing): void
    {
        try {
            $id    = (int) ($listing['id'] ?? 0);
            $email = strtolower(trim((string) ($listing['email'] ?? '')));
            if ($id === 0) {
                return;
            }

            $builder = $this->referrals
                ->whereIn('status', [DirectoryReferralModel::STATUS_PENDING, DirectoryReferralModel::STATUS_INVITED])
                ->groupStart()->where('listing_id', $id);
            if ($email !== '') {
                $builder->orWhere('business_email', $email);
            }
            $rows = $builder->groupEnd()->findAll();

            foreach ($rows as $r) {
                $this->referrals->update((int) $r['id'], [
                    'status'     => DirectoryReferralModel::STATUS_LISTED,
                    'listing_id' => $id,
                ]);

                if ((int) $r['notify_referrer'] === 1 && ! empty($r['referrer_email'])) {
                    $this->notice((string) $r['referrer_email'], $this->headerSafe((string) $listing['display_name']) . ' now has a profile', [
                        'heading'    => 'Thanks — they now have a profile',
                        'paragraphs' => [
                            'You recommended ' . $listing['display_name'] . ' to ' . $this->site->siteName() . '. Their profile is live now.',
                        ],
                        'button'   => ['See their profile', base_url('directory/' . $listing['slug'])],
                        'footnote' => 'This is the only email we send about your recommendation.',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Referral markListed failed: ' . $e->getMessage());
        }
    }

    /**
     * "Don't contact me again": block the address for good and close every
     * open referral for it. Returns false for an unknown token.
     */
    public function suppress(string $token): bool
    {
        $r = $this->findByToken($token);
        if ($r === null || empty($r['business_email'])) {
            return false;
        }

        $email       = (string) $r['business_email'];
        $suppression = new DirectoryInviteSuppressionModel();
        if (! $suppression->isSuppressed($email)) {
            $suppression->insert(['email_hash' => DirectoryInviteSuppressionModel::hashEmail($email)]);
        }

        $this->referrals
            ->whereIn('status', [DirectoryReferralModel::STATUS_PENDING, DirectoryReferralModel::STATUS_INVITED])
            ->where('business_email', $email)
            ->set(['status' => DirectoryReferralModel::STATUS_DISMISSED])
            ->update();

        return true;
    }

    // ------------------------------------------------------------- retention

    /**
     * Wipe contact details from referrals past retention. The row stays, so
     * the admin's counts stay honest, but nobody's details do.
     *
     * @return int rows wiped
     */
    public function prune(): int
    {
        $old       = date('Y-m-d H:i:s', strtotime('-' . self::RETAIN_MONTHS . ' months'));
        $dismissed = date('Y-m-d H:i:s', strtotime('-' . self::DISMISSED_RETAIN_DAYS . ' days'));

        $ids = $this->referrals->select('id')
            ->where('pruned_at', null)
            ->groupStart()
                ->where('created_at <', $old)
                ->orGroupStart()
                    ->where('status', DirectoryReferralModel::STATUS_DISMISSED)
                    ->where('updated_at <', $dismissed)
                ->groupEnd()
            ->groupEnd()
            ->findColumn('id') ?? [];

        if ($ids === []) {
            return 0;
        }

        $this->referrals->whereIn('id', $ids)->set([
            'business_email' => null,
            'business_phone' => null,
            'note'           => null,
            'referrer_name'  => null,
            'referrer_email' => null,
            'ip_hash'        => null,
            'invite_token'   => null,
            'pruned_at'      => date('Y-m-d H:i:s'),
        ])->update();

        return count($ids);
    }

    // ------------------------------------------------------------------ mail

    /** @param array<string,mixed> $r */
    private function sendInvite(array $r, string $token): bool
    {
        $site = $this->site->siteName();

        // Who recommended them, but only when that is useful and harmless: a
        // customer's first name, never an address. "Other" stays anonymous.
        $by = '';
        if ($r['relationship'] === 'customer' && ! empty($r['referrer_name'])) {
            $by = ' One of your customers, ' . strtok((string) $r['referrer_name'], ' ') . ', suggested your business to us.';
        } elseif ($r['relationship'] === 'owner_or_staff') {
            $by = ' Someone at your business asked us to get in touch.';
        }

        $to           = (string) $r['business_email'];
        $stop         = ["Don't contact me again", base_url('recommend/stop/' . $token)];
        $verification = new VerificationService();

        // With the badge on sale, the invite leads with it and lands on the
        // verified-only form. The free-listing line stays: the Terms promise a
        // South African listing is free, and an invite that implied otherwise
        // would be misleading. With the badge off, it is the plain free invite.
        if ($verification->isEnabled()) {
            $amount = $verification->monthlyAmount();

            // Every line here is something the code does, and says the same as
            // _plan_cards.php and _verification_pitch.php. "More ways to be
            // found", never "rank higher": the badge does not change ordering
            // anywhere. Each item leads with a bold keyword so a skim catches it.
            //
            // Jobs is a badge feature: JobBoardService::canUseJobsFeatures()
            // gates posting vacancies and replying to requests.
            return $this->notice($to, 'Get ' . $this->headerSafe((string) $r['business_name']) . ' verified on ' . $site, [
                'heading'    => 'You were recommended on ' . $site,
                'paragraphs' => [
                    'Hello ' . $r['business_name'] . ',',
                    $site . ' is a local business discovery and visibility platform for South Africa.' . $by,
                    'Get Verified and show customers your locations, your people and your opportunities, as well as your business.',
                ],
                'bulletsHeading' => 'With a Verified Business profile you get:',
                'bullets'        => [
                    '✓ Verified badge: a green badge on your profile and beside your name in every search result, so customers can see we have confirmed your business.',
                    '📍 Locations: add up to ' . PracticeLocationService::MAX_LOCATIONS . ' more branches, practices or consulting rooms, each with its own address, phone number, map pin and opening hours, and each presented to Google as a business location in its own right.',
                    '👥 Staff: list up to ' . TeamMemberService::MAX_MEMBERS . ' people by name, with photos, qualifications and areas of expertise. When someone searches ' . $site . ' for one of your people, or for a service only they offer, they find your business.',
                    '💼 Jobs: post your vacancies on our Jobs board, set up so eligible vacancies can appear in Google\'s job search, and reply to customers who need your kind of service.',
                    '📢 New work first: when someone in your province asks for your kind of service, verified businesses are the first we alert.',
                ],
                'highlight' => 'R' . $amount . ' a month, and nothing to pay until we have checked your company registration and the owner\'s ID.',
                'closing'   => [
                    'We have already filled in what we were told about your business. Just check your details and upload the two documents.',
                    'Your business profile stays free either way.',
                ],
                'button'       => ['Get your business verified', base_url('add-profile/verified?invite=' . $token)],
                'footnote'     => 'We will not email you about this again. Not interested?',
                'footnoteLink' => $stop,
            ]);
        }

        return $this->notice($to, 'Create a free business profile for ' . $this->headerSafe((string) $r['business_name']) . ' on ' . $site, [
            'heading'    => 'You were recommended on ' . $site,
            'paragraphs' => [
                'Hello ' . $r['business_name'] . ',',
                $site . ' is a local business discovery and visibility platform for South Africa.' . $by,
                'A business profile is free: no monthly fee, no subscription, no obligation. It takes a few minutes, and we have filled in what we were told, so you only need to check it and add the rest.',
            ],
            'button'       => ['Create your free business profile', base_url('add-profile?invite=' . $token)],
            'footnote'     => 'We will not email you about this again. Not interested?',
            'footnoteLink' => $stop,
        ]);
    }

    /** @param array<string,mixed> $r */
    private function notifyAdmin(array $r): void
    {
        $admin = $this->site->adminEmail();
        if ($admin === '') {
            return;
        }

        $this->notice($admin, 'Business recommended: ' . $this->headerSafe((string) $r['business_name']), [
            'heading'    => 'A business was recommended',
            'paragraphs' => array_values(array_filter([
                (string) $r['business_name'] . ' — ' . trim(($r['city'] ?? '') . ', ' . ($r['province'] ?? ''), ', '),
                (string) ($r['note'] ?? ''),
            ])),
            'button' => ['Review referrals', base_url('admin/referrals')],
        ]);
    }

    /**
     * @param array{heading:string,paragraphs:list<string>,button?:array{0:string,1:string},footnote?:string,footnoteLink?:array{0:string,1:string}} $content
     */
    private function notice(string $to, string $subject, array $content): bool
    {
        if ($to === '') {
            return false;
        }

        // The Jobs board's notice template, which differs only in its eyebrow.
        // saveData off and every optional key defaulted, as in JobBoardService:
        // CI4 keeps view data between renders, so an email with no stop link
        // would otherwise inherit the previous one's.
        $body = view('emails/job-notice', $content + [
            'site'         => $this->site->siteName(),
            'eyebrow'      => $this->site->siteName(),
            'button'       => null,
            'footnote'     => null,
            'footnoteLink' => null,
            'bullets'        => null,
            'bulletsHeading' => null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);

        return (new Mailer())->send($to, $subject, $body);
    }

    private function clean($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $v)), 0, $max);
    }

    private function cleanText($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max);
    }

    private function headerSafe(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', $s)), 0, 120);
    }
}
