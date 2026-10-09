<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryPartnerClickModel;
use App\Models\DirectoryPartnerCommissionModel;
use App\Models\DirectoryPartnerModel;
use App\Models\DirectoryPartnerPayoutModel;
use App\Models\DirectoryVerificationModel;
use Config\Directory as DirectoryConfig;
use Config\Partners as PartnersConfig;

/**
 * The Partner Program: affiliates who promote WebScheduler Local with a
 * tracked link and earn a share of what the businesses they bring in pay.
 *
 * The flow, end to end:
 *
 *   apply → admin approves → /p/{code} sets a cookie → a signup inside the
 *   window is credited to that partner → each cleared Verified or
 *   International payment in the first 12 months earns a commission →
 *   it is held 30 days → an admin pays the available total by EFT.
 *
 * Two rules everything here serves:
 *
 * 1. **Nothing here can cost a business its profile or its payment.** The
 *    hooks in Listing::store() and VerificationService::recordPayment() run
 *    after their own work is committed and swallow their own failures. A
 *    commission missed that way is rebuilt by sweep()'s reconcile.
 * 2. **No payment pays commission twice.** The commission table's unique key
 *    on pf_payment_id makes the insert the claim, exactly as
 *    DirectoryVerificationEventModel::claim() does for the payment itself.
 */
class PartnerService
{
    public const SESSION_KEY = 'partner_id';

    public const COOKIE = 'ws_partner';

    public const MAX_PLAN = 1000;

    /**
     * Same reply to an application or a sign-in request whatever happened, so
     * neither form tells a stranger which addresses are partners.
     */
    public const APPLIED_MESSAGE = 'Thanks for applying. We review every application and will email you once we have decided.';

    public const LOGIN_MESSAGE = 'If that email belongs to a partner, we have sent it a sign in link. The link lasts one hour.';

    /** Codes that would read as ours, or collide with a route word. */
    private const RESERVED_CODES = ['admin', 'webscheduler', 'local', 'partners', 'partner', 'support', 'help', 'test'];

    /**
     * User agents that fetch a link without a person behind it. WhatsApp and
     * the social networks fetch every shared link for its preview, which is
     * exactly where partners will share theirs; counting those would inflate
     * every partner's clicks by the size of their audience.
     */
    private const NOT_A_CLICK = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|slack|discord|linkedin|twitter|skype|curl|wget|python|headless/i';

    private DirectoryPartnerModel $partners;
    private DirectoryPartnerCommissionModel $commissions;
    private PartnersConfig $config;
    private DirectoryConfig $site;

    public function __construct(?PartnersConfig $config = null)
    {
        $this->partners    = new DirectoryPartnerModel();
        $this->commissions = new DirectoryPartnerCommissionModel();
        $this->config      = $config ?? config('Partners');
        $this->site        = config('Directory');
    }

    public function config(): PartnersConfig
    {
        return $this->config;
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled;
    }

    /** The percent a partner earns: their own rate if one is set, else the default. */
    public function rateFor(array $partner): float
    {
        $own = $partner['commission_rate'] ?? null;

        return $own !== null && $own !== '' ? (float) $own : $this->config->commissionRate;
    }

    public function linkFor(array $partner): string
    {
        return base_url('p/' . $partner['code']);
    }

    // ------------------------------------------------------------- applying

    /**
     * Store an application from the public form and tell the admin.
     *
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
     */
    public function apply(array $in): array
    {
        $d = [
            'name'       => $this->clean($in['name'] ?? '', 120),
            'email'      => strtolower($this->clean($in['email'] ?? '', 190)),
            'phone'      => $this->clean($in['phone'] ?? '', 40),
            'company'    => $this->clean($in['company'] ?? '', 200),
            'website'    => $this->clean($in['website'] ?? '', 255),
            'promo_plan' => $this->cleanText($in['promo_plan'] ?? '', self::MAX_PLAN),
        ];

        $errors = [];
        if ($d['name'] === '') {
            $errors['name'] = 'Please tell us your name.';
        }
        if (! filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $d['email'] === '' ? 'We need your email to reply.' : 'That email address does not look right.';
        }
        if ($d['website'] !== '') {
            $url = (new DirectoryListingMutationService())->normaliseUrl($d['website']);
            if ($url === null || safe_external_url($url) === '') {
                $errors['website'] = 'That website address does not look right.';
            } else {
                $d['website'] = $url;
            }
        }
        if (mb_strlen($d['promo_plan']) < 20) {
            $errors['promo_plan'] = 'Tell us a little about who you work with and how you would share your link.';
        }
        if (empty($in['agree_terms'])) {
            $errors['agree_terms'] = 'Please accept the Partner Program terms.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $d];
        }

        // A second application from the same address changes nothing and is
        // answered the same way, so the form cannot be used to find partners.
        if ($this->partners->findByEmail($d['email']) !== null) {
            return ['ok' => true, 'errors' => [], 'data' => $d];
        }

        $row = $d + [
            'code'   => $this->uniqueCode($d['company'] !== '' ? $d['company'] : $d['name']),
            'status' => DirectoryPartnerModel::STATUS_APPLIED,
        ];
        foreach (['phone', 'company', 'website'] as $nullable) {
            if ($row[$nullable] === '') {
                $row[$nullable] = null;
            }
        }

        $this->partners->insert($row);
        $this->notifyAdmin($row);

        return ['ok' => true, 'errors' => [], 'data' => $d];
    }

    // ------------------------------------------------------------- deciding

    /** @return array{ok:bool,message:string} */
    public function approve(int $id, string $by): array
    {
        $p = $this->partners->find($id);
        if (! is_array($p) || ! in_array($p['status'], [DirectoryPartnerModel::STATUS_APPLIED, DirectoryPartnerModel::STATUS_SUSPENDED], true)) {
            return ['ok' => false, 'message' => 'Only an application or a suspended partner can be approved.'];
        }

        $wasSuspended = $p['status'] === DirectoryPartnerModel::STATUS_SUSPENDED;
        $this->partners->update($id, [
            'status'     => DirectoryPartnerModel::STATUS_APPROVED,
            'decided_at' => date('Y-m-d H:i:s'),
            'decided_by' => $by,
        ]);

        $p = $this->partners->find($id);
        $this->notice((string) $p['email'], $wasSuspended ? 'Your partner link is active again' : 'Welcome to the ' . $this->site->siteName() . ' Partner Program', [
            'heading'    => $wasSuspended ? 'Your partner link is active again' : 'You are approved',
            'paragraphs' => [
                'Hi ' . strtok((string) $p['name'], ' ') . ', your partner link is ready:',
                $this->linkFor($p),
                'Share it with businesses that should be on ' . $this->site->siteName() . '. When a business you send us gets Verified, you earn '
                    . $this->percent($this->rateFor($p)) . ' of every payment they make in their first ' . $this->config->commissionMonths . ' months.',
                'Sign in to your partner dashboard to see your clicks, signups and earnings, and to add the bank account we pay into.',
            ],
            'button'   => ['Open my dashboard', $this->loginLink($p)],
            'footnote' => 'The sign in button works once and lasts one hour. You can always ask for a new one at ' . base_url('partners/login') . '.',
        ]);

        return ['ok' => true, 'message' => $p['name'] . ' is approved and has been emailed their link.'];
    }

    /** @return array{ok:bool,message:string} */
    public function reject(int $id, string $by, string $note = ''): array
    {
        $p = $this->partners->find($id);
        if (! is_array($p) || $p['status'] !== DirectoryPartnerModel::STATUS_APPLIED) {
            return ['ok' => false, 'message' => 'Only an application can be declined.'];
        }

        $this->partners->update($id, [
            'status'     => DirectoryPartnerModel::STATUS_REJECTED,
            'decided_at' => date('Y-m-d H:i:s'),
            'decided_by' => $by,
            'admin_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
        ]);

        $this->notice((string) $p['email'], 'Your Partner Program application', [
            'heading'    => 'Thanks for applying',
            'paragraphs' => [
                'Hi ' . strtok((string) $p['name'], ' ') . ', thank you for your interest in the ' . $this->site->siteName() . ' Partner Program.',
                "We are not able to accept your application at the moment. You are welcome to reply to this email if you would like to tell us more.",
            ],
        ]);

        return ['ok' => true, 'message' => 'Application declined. ' . $p['name'] . ' has been told.'];
    }

    /**
     * Stop a partner's link from tracking and earning. Commissions already
     * earned stay, and can still be paid or voided by hand.
     *
     * @return array{ok:bool,message:string}
     */
    public function suspend(int $id, string $by, string $note = ''): array
    {
        $p = $this->partners->find($id);
        if (! is_array($p) || $p['status'] !== DirectoryPartnerModel::STATUS_APPROVED) {
            return ['ok' => false, 'message' => 'Only an approved partner can be suspended.'];
        }

        $this->partners->update($id, [
            'status'     => DirectoryPartnerModel::STATUS_SUSPENDED,
            'decided_at' => date('Y-m-d H:i:s'),
            'decided_by' => $by,
            'admin_note' => $note !== '' ? mb_substr($note, 0, 500) : $p['admin_note'],
        ]);

        return ['ok' => true, 'message' => $p['name'] . ' is suspended. Their link no longer tracks or earns.'];
    }

    /**
     * Set or clear a partner's own rate. Only future commissions use it.
     *
     * @return array{ok:bool,message:string}
     */
    public function setRate(int $id, string $raw): array
    {
        $p = $this->partners->find($id);
        if (! is_array($p)) {
            return ['ok' => false, 'message' => 'That partner no longer exists.'];
        }

        $raw = trim(str_replace('%', '', $raw));
        if ($raw === '') {
            $this->partners->update($id, ['commission_rate' => null]);

            return ['ok' => true, 'message' => 'Rate reset to the default ' . $this->percent($this->config->commissionRate) . '.'];
        }

        if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
            return ['ok' => false, 'message' => 'A rate is a percent from 0 to 100.'];
        }

        $this->partners->update($id, ['commission_rate' => round((float) $raw, 2)]);

        return ['ok' => true, 'message' => 'Rate set to ' . $this->percent((float) $raw) . ' for future payments.'];
    }

    // ------------------------------------------------------------ signing in

    /** Email a dashboard sign in link. Silent about whether the address is a partner. */
    public function requestLogin(string $email): void
    {
        $p = $this->partners->findByEmail($email);
        if ($p === null || ! in_array($p['status'], [DirectoryPartnerModel::STATUS_APPROVED, DirectoryPartnerModel::STATUS_SUSPENDED], true)) {
            return;
        }

        $this->notice((string) $p['email'], 'Your partner dashboard link', [
            'heading'    => 'Sign in to your partner dashboard',
            'paragraphs' => ['Use the button below to sign in. It works once and lasts one hour.'],
            'button'     => ['Open my dashboard', $this->loginLink($p)],
            'footnote'   => "If you did not ask for this, you can ignore this email.",
        ]);
    }

    /**
     * Trade a sign in token for its partner, once. Null for an unknown,
     * expired or used token.
     *
     * @return array<string,mixed>|null
     */
    public function redeemLogin(string $token): ?array
    {
        $token = trim($token);
        if (strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return null;
        }

        $p = $this->partners
            ->where('login_token', TokenHash::hash($token))
            ->where('login_expires >=', date('Y-m-d H:i:s'))
            ->whereIn('status', [DirectoryPartnerModel::STATUS_APPROVED, DirectoryPartnerModel::STATUS_SUSPENDED])
            ->first();
        if (! is_array($p)) {
            return null;
        }

        $this->partners->update((int) $p['id'], ['login_token' => null, 'login_expires' => null]);

        return $p;
    }

    private function loginLink(array $partner): string
    {
        $token = TokenHash::mint();
        $this->partners->update((int) $partner['id'], [
            'login_token'   => TokenHash::hash($token),
            'login_expires' => date('Y-m-d H:i:s', time() + 60 * $this->config->loginLinkMinutes),
        ]);

        return base_url('partners/login/' . $token);
    }

    // ------------------------------------------------------- the partner link

    /**
     * A visit through a partner link. Counts the click unless a link preview
     * or a bot made it, and returns the partner so the caller can set the
     * cookie. Null for an unknown code or a partner who is not approved.
     *
     * @return array<string,mixed>|null
     */
    public function track(string $code, string $userAgent): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $p = $this->partners->findApprovedByCode($code);
        if ($p === null) {
            return null;
        }

        if ($userAgent !== '' && ! preg_match(self::NOT_A_CLICK, $userAgent)) {
            try {
                (new DirectoryPartnerClickModel())->bump((int) $p['id']);
            } catch (\Throwable $e) {
                log_message('error', 'Partner click not counted: ' . $e->getMessage());
            }
        }

        return $p;
    }

    public function cookieSeconds(): int
    {
        return 86400 * $this->config->cookieDays;
    }

    /**
     * Credit a fresh signup to the partner whose link set the cookie. Called
     * after the profile is saved; never throws, because it must never cost
     * anyone their profile.
     *
     * Only credits when every one of these holds:
     *  - the partner is approved;
     *  - the profile was created inside the attribution window (an existing
     *    profile's id comes back from submitPublic() for a known email);
     *  - the profile has no partner yet;
     *  - the owner is not the partner (same email).
     */
    public function attribute(int $listingId, string $code): bool
    {
        try {
            if ($code === '' || ! $this->isEnabled()) {
                return false;
            }

            $p = $this->partners->findApprovedByCode($code);
            if ($p === null) {
                return false;
            }

            $listing = (new DirectoryListingModel())->withDeleted()->find($listingId);
            if (! is_array($listing) || ! empty($listing['partner_id'])) {
                return false;
            }

            $created = strtotime((string) ($listing['created_at'] ?? ''));
            if ($created === false || $created < time() - 60 * $this->config->attributionWindowMinutes) {
                return false;
            }

            if (strtolower(trim((string) $listing['email'])) === strtolower((string) $p['email'])) {
                return false;
            }

            return $this->setListingPartner($listingId, (int) $p['id']);
        } catch (\Throwable $e) {
            log_message('error', 'Partner attribution failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Admin: credit a profile to a partner by its slug, or move it. Payments
     * made before this moment never earn (see recordCommission()).
     *
     * @return array{ok:bool,message:string}
     */
    public function creditListing(int $partnerId, string $slug): array
    {
        $p = $this->partners->find($partnerId);
        if (! is_array($p)) {
            return ['ok' => false, 'message' => 'That partner no longer exists.'];
        }

        $listing = (new DirectoryListingModel())->where('slug', trim($slug))->first();
        if (! is_array($listing)) {
            return ['ok' => false, 'message' => 'No profile has that address. Use the part after /directory/.'];
        }
        if (strtolower((string) $listing['email']) === strtolower((string) $p['email'])) {
            return ['ok' => false, 'message' => 'That profile belongs to the partner themselves.'];
        }

        $this->setListingPartner((int) $listing['id'], $partnerId);

        return ['ok' => true, 'message' => $listing['display_name'] . ' is now credited to ' . $p['name'] . '. Only payments from now on earn commission.'];
    }

    /** Admin: remove a profile's partner. Commission already earned stays. */
    public function uncreditListing(int $listingId): bool
    {
        return db_connect()->table('xs_directory_listings')->where('id', $listingId)->update([
            'partner_id'            => null,
            'partner_attributed_at' => null,
        ]);
    }

    /**
     * Written with the query builder, like the review totals: the columns are
     * deliberately not in the listing model's allowedFields, so no form path
     * can ever set them.
     */
    private function setListingPartner(int $listingId, int $partnerId): bool
    {
        return db_connect()->table('xs_directory_listings')->where('id', $listingId)->update([
            'partner_id'            => $partnerId,
            'partner_attributed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------ commission

    /**
     * Commission on one cleared payment, if it earns any. Called after
     * recordPayment() commits, and by reconcile(). Never throws.
     *
     * A payment earns when:
     *  - its profile has a partner, who is approved now;
     *  - it was made at or after the profile was credited to that partner;
     *  - it falls inside commissionMonths of the subscription's first payment.
     *
     * @return bool whether a commission row was written
     */
    public function recordCommission(int $verificationId, string $pfPaymentId, ?float $amountGross, ?string $paidAt = null): bool
    {
        try {
            if ($pfPaymentId === '' || $amountGross === null || $amountGross <= 0) {
                return false;
            }

            $verification = (new DirectoryVerificationModel())->find($verificationId);
            if (! is_array($verification)) {
                return false;
            }

            $listing = (new DirectoryListingModel())->withDeleted()->find((int) $verification['listing_id']);
            if (! is_array($listing) || empty($listing['partner_id'])) {
                return false;
            }

            $p = $this->partners->find((int) $listing['partner_id']);
            if (! is_array($p) || $p['status'] !== DirectoryPartnerModel::STATUS_APPROVED) {
                return false;
            }

            $paidAt   ??= date('Y-m-d H:i:s');
            $paidTs     = strtotime($paidAt);
            $creditedTs = strtotime((string) ($listing['partner_attributed_at'] ?? ''));
            if ($paidTs === false || $creditedTs === false || $paidTs < $creditedTs) {
                return false;
            }

            // "12 months" means 12 monthly payments. The window stops 3 days
            // short of the anniversary: payments are 28 to 31 days apart, so
            // that never drops the 12th, and the 13th (due ON the anniversary)
            // can never sneak in by arriving later in the day than the first.
            $firstPaid = strtotime((string) ($verification['activated_at'] ?? '')) ?: $paidTs;
            $windowEnd = strtotime('+' . $this->config->commissionMonths . ' months -3 days', $firstPaid);
            if ($paidTs >= $windowEnd) {
                return false;
            }

            $rate = $this->rateFor($p);

            // The insert is the claim: the unique key on pf_payment_id turns a
            // second attempt into a refused insert rather than a second payout.
            return (bool) $this->commissions->insert([
                'partner_id'      => (int) $p['id'],
                'listing_id'      => (int) $listing['id'],
                'verification_id' => $verificationId,
                'pf_payment_id'   => $pfPaymentId,
                'payment_amount'  => round($amountGross, 2),
                'rate'            => $rate,
                'amount'          => round($amountGross * $rate / 100, 2),
                'state'           => DirectoryPartnerCommissionModel::STATE_PENDING,
                'available_at'    => date('Y-m-d H:i:s', $paidTs + 86400 * $this->config->holdDays),
            ], false);
        } catch (\Throwable $e) {
            $duplicate = str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062');
            log_message($duplicate ? 'info' : 'error', 'Partner commission not recorded: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Admin: void a commission that has not been paid (a refund, a chargeback,
     * or a referral that broke the terms).
     */
    public function void(int $commissionId, string $reason): bool
    {
        $c = $this->commissions->find($commissionId);
        if (! is_array($c) || ! in_array($c['state'], [DirectoryPartnerCommissionModel::STATE_PENDING, DirectoryPartnerCommissionModel::STATE_AVAILABLE], true)) {
            return false;
        }

        return $this->commissions->update($commissionId, [
            'state'       => DirectoryPartnerCommissionModel::STATE_VOID,
            'void_reason' => mb_substr(trim($reason) !== '' ? trim($reason) : 'Voided by admin', 0, 255),
        ]);
    }

    // ----------------------------------------------------------------- sweep

    /**
     * The daily job: release held commissions, rebuild any that a failed hook
     * missed in the last week, and delete declined applications past their
     * retention.
     *
     * @return array{released:int,reconciled:int,deleted:int}
     */
    public function sweep(): array
    {
        $now = date('Y-m-d H:i:s');
        $ids = $this->commissions
            ->where('state', DirectoryPartnerCommissionModel::STATE_PENDING)
            ->where('available_at <=', $now)
            ->findColumn('id') ?? [];

        if ($ids !== []) {
            $this->commissions->whereIn('id', $ids)
                ->set(['state' => DirectoryPartnerCommissionModel::STATE_AVAILABLE, 'updated_at' => $now])
                ->update();
        }
        $released = count($ids);

        // The privacy policy promises a declined application is deleted a
        // year after the decision. A declined applicant never earned, so no
        // commission row holds the delete back.
        $declined = $this->partners
            ->where('status', DirectoryPartnerModel::STATUS_REJECTED)
            ->where('decided_at <', date('Y-m-d H:i:s', strtotime('-12 months')))
            ->findColumn('id') ?? [];
        if ($declined !== []) {
            $this->partners->whereIn('id', $declined)->delete();
        }

        return ['released' => $released, 'reconciled' => $this->reconcile(), 'deleted' => count($declined)];
    }

    /**
     * Rebuild commission for cleared payments from the last week that have
     * none. A week, not all time: this exists to catch a hook that failed, and
     * an unbounded backfill would also pay for months a partner was suspended
     * the day they are reinstated.
     */
    public function reconcile(int $days = 7): int
    {
        $db     = db_connect();
        $events = $db->table('xs_directory_verification_events AS e')
            ->select('e.verification_id, e.pf_payment_id, e.amount_gross, e.created_at')
            ->join('xs_directory_verifications AS v', 'v.id = e.verification_id')
            ->join('xs_directory_listings AS l', 'l.id = v.listing_id')
            ->join('xs_directory_partner_commissions AS c', 'c.pf_payment_id = e.pf_payment_id', 'left')
            ->where('e.payment_status', 'COMPLETE')
            ->where('e.created_at >=', date('Y-m-d H:i:s', strtotime('-' . $days . ' days')))
            ->where('l.partner_id IS NOT NULL')
            ->where('c.id', null)
            ->get()->getResultArray();

        $made = 0;
        foreach ($events as $e) {
            if ($this->recordCommission(
                (int) $e['verification_id'],
                (string) $e['pf_payment_id'],
                $e['amount_gross'] === null ? null : (float) $e['amount_gross'],
                (string) $e['created_at']
            )) {
                $made++;
            }
        }

        return $made;
    }

    // ---------------------------------------------------------------- payouts

    /**
     * Partners owed at least the minimum, with what they are owed. Suspended
     * partners are included: commission they earned is still theirs.
     *
     * @return list<array<string,mixed>>
     */
    public function payable(): array
    {
        $rows = $this->commissions
            ->select('partner_id, SUM(amount) AS total, COUNT(*) AS n')
            ->where('state', DirectoryPartnerCommissionModel::STATE_AVAILABLE)
            ->groupBy('partner_id')
            ->having('SUM(amount) >=', $this->config->minimumPayout)
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            $p = $this->partners->find((int) $r['partner_id']);
            if (! is_array($p)) {
                continue;
            }
            $out[] = $p + [
                'owed'        => round((float) $r['total'], 2),
                'owed_count'  => (int) $r['n'],
                'bank'        => $this->bankDetails($p),
            ];
        }

        return $out;
    }

    /**
     * Record an EFT the admin has already made: one payout row, and every
     * available commission marked paid against it. All or nothing.
     *
     * @return array{ok:bool,message:string}
     */
    public function markPaid(int $partnerId, string $eftReference, string $by): array
    {
        $eftReference = $this->clean($eftReference, 120);
        if ($eftReference === '') {
            return ['ok' => false, 'message' => 'Enter the EFT reference so the payment can be traced.'];
        }

        $p = $this->partners->find($partnerId);
        if (! is_array($p)) {
            return ['ok' => false, 'message' => 'That partner no longer exists.'];
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $rows = $this->commissions
                ->select('id, amount')
                ->where('partner_id', $partnerId)
                ->where('state', DirectoryPartnerCommissionModel::STATE_AVAILABLE)
                ->findAll();
            $ids   = array_map('intval', array_column($rows, 'id'));
            $total = round(array_sum(array_map('floatval', array_column($rows, 'amount'))), 2);

            if ($ids === [] || $total < $this->config->minimumPayout) {
                $db->transRollback();

                return ['ok' => false, 'message' => 'Nothing to pay: this partner is owed less than the minimum.'];
            }

            $payoutId = (int) (new DirectoryPartnerPayoutModel())->insert([
                'partner_id'    => $partnerId,
                'total'         => $total,
                'eft_reference' => $eftReference,
                'paid_at'       => date('Y-m-d H:i:s'),
                'paid_by'       => $by,
            ], true);

            $this->commissions->whereIn('id', $ids)->set([
                'state'      => DirectoryPartnerCommissionModel::STATE_PAID,
                'payout_id'  => $payoutId,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->update();

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Partner payout not recorded: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'The payout could not be recorded. Nothing was changed.'];
        }

        $this->notice((string) $p['email'], 'We have paid your partner commission', [
            'heading'    => 'Your commission is on its way',
            'paragraphs' => [
                'Hi ' . strtok((string) $p['name'], ' ') . ', we have paid ' . $this->rand($total) . ' into your bank account for '
                    . count($ids) . ' ' . (count($ids) === 1 ? 'payment' : 'payments') . ' from businesses you referred.',
                'EFT reference: ' . $eftReference,
                'It can take up to two working days to reflect, depending on your bank.',
            ],
            'button' => ['See my statement', base_url('partners/login')],
        ]);

        return ['ok' => true, 'message' => 'Payout of ' . $this->rand($total) . ' recorded and ' . $p['name'] . ' has been emailed.'];
    }

    // ------------------------------------------------------------ bank details

    /**
     * Save the partner's bank account, encrypted. Refuses rather than storing
     * plain text when no encryption key is configured.
     *
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>,message:string}
     */
    public function saveBankDetails(int $partnerId, array $in): array
    {
        $d = [
            'holder'         => $this->clean($in['holder'] ?? '', 120),
            'bank'           => $this->clean($in['bank'] ?? '', 60),
            'branch_code'    => preg_replace('/\D+/', '', (string) ($in['branch_code'] ?? '')),
            'account_number' => preg_replace('/\D+/', '', (string) ($in['account_number'] ?? '')),
            'account_type'   => in_array($in['account_type'] ?? '', ['cheque', 'savings', 'business'], true) ? $in['account_type'] : 'cheque',
        ];

        $errors = [];
        if ($d['holder'] === '') {
            $errors['holder'] = 'Whose name is the account in?';
        }
        if ($d['bank'] === '') {
            $errors['bank'] = 'Which bank?';
        }
        if (strlen($d['branch_code']) < 4 || strlen($d['branch_code']) > 10) {
            $errors['branch_code'] = 'A branch code is 4 to 10 digits.';
        }
        if (strlen($d['account_number']) < 6 || strlen($d['account_number']) > 16) {
            $errors['account_number'] = 'An account number is 6 to 16 digits.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'];
        }

        try {
            $sealed = base64_encode(service('encrypter')->encrypt(json_encode($d)));
        } catch (\Throwable $e) {
            log_message('error', 'Partner bank details not saved, encryption unavailable: ' . $e->getMessage());

            return ['ok' => false, 'errors' => [], 'message' => 'We cannot save bank details right now. Please email them to us instead.'];
        }

        $this->partners->update($partnerId, ['bank_details' => $sealed]);

        return ['ok' => true, 'errors' => [], 'message' => 'Bank details saved.'];
    }

    /**
     * The decrypted account, or null when none is stored or it cannot be read.
     *
     * @return array{holder:string,bank:string,branch_code:string,account_number:string,account_type:string}|null
     */
    public function bankDetails(array $partner): ?array
    {
        $sealed = (string) ($partner['bank_details'] ?? '');
        if ($sealed === '') {
            return null;
        }

        try {
            $plain = service('encrypter')->decrypt((string) base64_decode($sealed, true));
            $data  = json_decode($plain, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            log_message('error', 'Partner bank details could not be read: ' . $e->getMessage());

            return null;
        }
    }

    /** "FNB, account ending 4821", for showing the partner what we hold. */
    public function bankSummary(array $partner): string
    {
        $b = $this->bankDetails($partner);

        return $b === null ? '' : $b['bank'] . ', account ending ' . substr($b['account_number'], -4);
    }

    // -------------------------------------------------------------- dashboard

    /**
     * Everything the partner dashboard shows.
     *
     * @return array<string,mixed>
     */
    public function dashboard(array $partner): array
    {
        $id     = (int) $partner['id'];
        $clicks = new DirectoryPartnerClickModel();
        $db     = db_connect();

        $signups = $db->table('xs_directory_listings')
            ->where('partner_id', $id)
            ->where('deleted_at', null)
            ->countAllResults();

        $paying = $db->table('xs_directory_partner_commissions')
            ->select('COUNT(DISTINCT listing_id) AS n')
            ->where('partner_id', $id)
            ->where('state !=', DirectoryPartnerCommissionModel::STATE_VOID)
            ->get()->getRowArray();

        return [
            'link'        => $this->linkFor($partner),
            'rate'        => $this->rateFor($partner),
            'clicks'      => $clicks->total($id),
            'clicks30'    => $clicks->total($id, date('Y-m-d', strtotime('-29 days'))),
            'signups'     => $signups,
            'paying'      => (int) ($paying['n'] ?? 0),
            'totals'      => $this->commissions->totals($id),
            'commissions' => $this->commissions->forPartner($id),
            'payouts'     => (new DirectoryPartnerPayoutModel())->forPartner($id),
            'bank'        => $this->bankSummary($partner),
        ];
    }

    // ---------------------------------------------------------------- helpers

    public function rand(float $amount): string
    {
        return 'R' . number_format($amount, 2, '.', ' ');
    }

    public function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
    }

    /** A readable, unique link code from a name: "Thandi's Marketing" → "thandis-marketing". */
    private function uniqueCode(string $from): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $from) ?: $from));
        $base = trim(substr(trim($base, '-'), 0, 24), '-');
        if (strlen($base) < 3 || in_array($base, self::RESERVED_CODES, true)) {
            $base = 'partner';
        }

        $code = $base;
        while ($this->partners->where('code', $code)->countAllResults() > 0 || in_array($code, self::RESERVED_CODES, true)) {
            $code = $base . '-' . random_int(100, 9999);
        }

        return $code;
    }

    private function notifyAdmin(array $p): void
    {
        $admin = $this->site->adminEmail();
        if ($admin === '') {
            return;
        }

        $this->notice($admin, 'Partner application: ' . $this->clean((string) $p['name'], 120), [
            'heading'    => 'Someone applied to the Partner Program',
            'paragraphs' => array_values(array_filter([
                trim($p['name'] . ($p['company'] ? ', ' . $p['company'] : '')),
                (string) $p['email'],
                (string) ($p['website'] ?? ''),
                (string) $p['promo_plan'],
            ])),
            'button' => ['Review applications', base_url('admin/partners')],
        ]);
    }

    /**
     * @param array{heading:string,paragraphs:list<string>,button?:array{0:string,1:string},footnote?:string} $content
     */
    private function notice(string $to, string $subject, array $content): bool
    {
        if ($to === '') {
            return false;
        }

        // The shared notice template. saveData off and every optional key
        // defaulted, as in ReferralService: CI4 keeps view data between
        // renders, so an email with no button would inherit the last one's.
        $body = view('emails/job-notice', $content + [
            'site'           => $this->site->siteName(),
            'eyebrow'        => 'Partner Program',
            'button'         => null,
            'footnote'       => null,
            'footnoteLink'   => null,
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
}
