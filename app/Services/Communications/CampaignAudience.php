<?php

namespace App\Services\Communications;

use CodeIgniter\Database\BaseBuilder;
use Config\Sites;

/**
 * Who a campaign goes to. Read only; CommunicationsService snapshots the
 * result into xs_campaign_recipients when a campaign starts.
 *
 * Segments (Config\Campaigns::$segments):
 * - unclaimed_imports: imported (signup_source import-*) and nobody has an
 *   active membership of it yet, so nobody has ever signed in.
 * - free: not paying, and not an unclaimed import (an owner signup, or an
 *   import someone has claimed).
 * - premium: Verified (verified_until) or Plus (hosting_paid_until on a
 *   profile outside South Africa), the same dates listing_has_premium() reads.
 *
 * Every segment: published, not deleted, not a demo, not a decoy (canary),
 * with an email, and not opted out. Opted out is either the communications
 * opt out or a withdrawn report preference: the owner chose one opt out for
 * all (21 Nov 2026). An imported profile that never opted in is not opted out.
 *
 * One email per address: a shared address gets one email (the oldest
 * profile), and when any profile on an address has opted out, none of them
 * is mailed.
 */
class CampaignAudience
{
    public const FILTERS = ['site', 'group', 'province', 'source'];

    /**
     * A clean audience from form input or stored JSON: a known segment and
     * only the filters we know, trimmed. Null segment when it is unknown.
     *
     * @param array<string,mixed> $input
     * @return array{segment:?string,site:string,group:string,province:string,source:string}
     */
    public static function normalise(array $input): array
    {
        $segment = (string) ($input['segment'] ?? '');
        $site    = (string) ($input['site'] ?? '');

        return [
            'segment'  => array_key_exists($segment, config('Campaigns')->segments) ? $segment : null,
            'site'     => in_array($site, [Sites::ZA, Sites::APP], true) ? $site : '',
            'group'    => mb_substr(trim((string) ($input['group'] ?? '')), 0, 80),
            'province' => mb_substr(trim((string) ($input['province'] ?? '')), 0, 80),
            'source'   => mb_substr(trim((string) ($input['source'] ?? '')), 0, 40),
        ];
    }

    /**
     * The profiles a campaign would go to now, one per address, oldest first.
     * $campaignId leaves out profiles already in that campaign's log.
     *
     * @param array<string,mixed> $audience
     * @return list<array<string,mixed>> listing rows, plus group_name
     */
    public function rows(array $audience, ?int $campaignId = null): array
    {
        $audience = self::normalise($audience);
        if ($audience['segment'] === null) {
            return [];
        }

        // Before the main builder: a second table() on the shared connection
        // resets its alias list, and `l.` would then be prefixed to `xs_l.`.
        $blocked = $this->optedOutEmails();

        $builder = $this->base()
            ->select('l.*, c.group_name')
            ->orderBy('l.id', 'ASC');
        $this->applySegment($builder, $audience['segment']);
        $this->applyFilters($builder, $audience);

        if ($campaignId !== null) {
            $builder->where('NOT EXISTS (SELECT 1 FROM ' . db_connect()->prefixTable('campaign_recipients')
                . ' r WHERE r.listing_id = l.id AND r.campaign_id = ' . (int) $campaignId . ')', null, false);
        }

        $seen    = [];
        $out     = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $email = self::normaliseEmail((string) $row['email']);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || isset($blocked[$email]) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[]        = $row;
        }

        return $out;
    }

    /** @param array<string,mixed> $audience */
    public function count(array $audience): int
    {
        return count($this->rows($audience));
    }

    /**
     * Why a queued profile must not be mailed now, or null when it still may.
     * Checked at send time: an owner can opt out, claim the profile or change
     * the address between the snapshot and the send.
     *
     * @param array<string,mixed> $listing  fresh row
     * @param array<string,mixed> $audience the campaign's
     */
    public function blockedReason(array $listing, string $queuedEmail, array $audience): ?string
    {
        if (($listing['deleted_at'] ?? null) !== null || ($listing['status'] ?? '') !== 'published') {
            return 'unpublished';
        }
        $email = self::normaliseEmail((string) ($listing['email'] ?? ''));
        if ($email === '') {
            return 'no_email';
        }
        if ($email !== $queuedEmail) {
            return 'email_changed';
        }
        if (isset($this->optedOutEmails()[$email])) {
            return 'opted_out';
        }

        $segment = self::normalise($audience)['segment'];
        $builder = $this->base()->select('l.id')->where('l.id', (int) $listing['id']);
        $this->applySegment($builder, (string) $segment);
        if ($builder->get()->getRowArray() === null) {
            return $segment === 'unclaimed_imports' ? 'claimed' : 'left_audience';
        }

        return null;
    }

    /** @return list<string> category groups, for the form */
    public function groups(): array
    {
        return array_column(db_connect()->table('directory_categories')
            ->distinct()->select('group_name')->where('group_name IS NOT NULL')->where('group_name !=', '')
            ->orderBy('group_name')->get()->getResultArray(), 'group_name');
    }

    /** @return list<string> provinces and regions in use, for the form */
    public function provinces(): array
    {
        return array_column(db_connect()->table('directory_listings')
            ->distinct()->select('province')->where('deleted_at', null)->where('status', 'published')
            ->where('province IS NOT NULL')->where('province !=', '')
            ->orderBy('province')->get()->getResultArray(), 'province');
    }

    /** @return list<string> signup sources in use, for the form */
    public function sources(): array
    {
        return array_column(db_connect()->table('directory_listings')
            ->distinct()->select('signup_source')->where('deleted_at', null)
            ->where('signup_source IS NOT NULL')->where('signup_source !=', '')->where('signup_source !=', 'canary')
            ->orderBy('signup_source')->get()->getResultArray(), 'signup_source');
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    // ------------------------------------------------------------ internals

    private function base(): BaseBuilder
    {
        return db_connect()->table('directory_listings l')
            ->join('directory_categories c', 'c.id = l.category_id', 'left')
            ->where('l.deleted_at', null)
            ->where('l.status', 'published')
            ->where('l.is_demo', 0)
            ->where('l.email IS NOT NULL')
            ->where('l.email !=', '')
            ->where("(l.signup_source IS NULL OR l.signup_source <> 'canary')", null, false)
            ->where('l.comms_opt_out_at', null)
            ->where('NOT (l.marketing_opt_in = 0 AND l.marketing_withdrawn_at IS NOT NULL)', null, false);
    }

    private function applySegment(BaseBuilder $builder, string $segment): void
    {
        $db       = db_connect();
        $today    = $db->escape(date('Y-m-d'));
        $members  = $db->prefixTable('business_members');
        $claimed  = "EXISTS (SELECT 1 FROM {$members} m WHERE m.listing_id = l.id AND m.status = 'active')";
        $imported = "l.signup_source LIKE 'import-%'";
        // COALESCE: a NULL date must read as "not paid", or NOT (...) is NULL and drops every free profile.
        $premium  = "(COALESCE(l.verified_until, '1000-01-01') >= {$today} OR (COALESCE(l.country_code, 'ZA') <> 'ZA' AND COALESCE(l.hosting_paid_until, '1000-01-01') >= {$today}))";

        match ($segment) {
            'unclaimed_imports' => $builder->where("{$imported} AND NOT {$claimed}", null, false),
            'free'              => $builder->where("NOT {$premium}", null, false)
                ->where("(l.signup_source IS NULL OR NOT ({$imported}) OR {$claimed})", null, false),
            'premium'           => $builder->where($premium, null, false),
            default             => $builder->where('1 = 0', null, false),
        };
    }

    /** @param array{site:string,group:string,province:string,source:string} $audience */
    private function applyFilters(BaseBuilder $builder, array $audience): void
    {
        if ($audience['site'] === Sites::APP) {
            $builder->where("(l.country_code IS NOT NULL AND l.country_code <> 'ZA')", null, false);
        } elseif ($audience['site'] === Sites::ZA) {
            $builder->where("(l.country_code IS NULL OR l.country_code = 'ZA')", null, false);
        }
        if ($audience['group'] !== '') {
            $builder->where('c.group_name', $audience['group']);
        }
        if ($audience['province'] !== '') {
            $builder->where('l.province', $audience['province']);
        }
        if ($audience['source'] !== '') {
            $builder->where('l.signup_source', $audience['source']);
        }
    }

    /**
     * Addresses that must not be mailed: any profile on them opted out.
     *
     * @return array<string,true>
     */
    private function optedOutEmails(): array
    {
        $rows = db_connect()->table('directory_listings')
            ->select('LOWER(TRIM(email)) AS e', false)
            ->where('email IS NOT NULL')
            ->groupStart()
                ->where('comms_opt_out_at IS NOT NULL')
                ->orGroupStart()->where('marketing_opt_in', 0)->where('marketing_withdrawn_at IS NOT NULL')->groupEnd()
            ->groupEnd()
            ->get()->getResultArray();

        return array_fill_keys(array_column($rows, 'e'), true);
    }
}
