<?php

namespace App\Services\Communications;

use App\Libraries\TokenHash;
use App\Services\DirectoryListingMutationService;
use App\Services\GoogleDataService;
use App\Services\MarketingConsentService;
use Config\Sites;

/**
 * One profile's copy of a campaign email: the template's button and footer
 * (Config\Campaigns) around the admin's subject and paragraphs, with the
 * merge fields filled from the profile.
 *
 * The body is plain text, one paragraph per blank line, escaped by the view.
 * No HTML from the form ever reaches an inbox, so a typo cannot break the
 * layout and nothing pasted can carry a script or a tracking pixel.
 *
 * Links go to the profile's own site (listing_site_url()), so an .app
 * profile is never sent to co.za. The live sign in link is minted only for a
 * real send; a test or preview links to the sign in page, so previewing a
 * profile never replaces its owner's link.
 */
class CampaignRenderer
{
    /** The merge fields a subject or paragraph may use. */
    public const FIELDS = ['{greeting}', '{name}', '{noun}', '{audience}', '{appearances}', '{site}', '{source}', '{plan}'];

    /** Days of Google results {appearances} counts. */
    public const APPEARANCE_DAYS = 30;

    /**
     * A sequence step is rendered by passing the campaign with that step's
     * subject and body, and its `button` ([label, target]) from the template.
     *
     * @param array<string,mixed> $campaign a campaigns row (kind, subject, body, optional button)
     * @param array<string,mixed> $listing  a fresh listings row
     * @param bool                $live     a real send: mint the sign in or claim link
     * @return array{subject:string,html:string,unsubscribe:string,linkHash:?string,linkExpires:?string}
     */
    public function render(array $campaign, array $listing, bool $live): array
    {
        $config = config('Campaigns');
        $kind   = $config->kinds[(string) $campaign['kind']] ?? null;
        if ($kind === null) {
            throw new \InvalidArgumentException('Unknown campaign kind ' . $campaign['kind']);
        }

        $id          = (int) $listing['id'];
        $needsCount  = str_contains((string) $campaign['subject'] . (string) $campaign['body'], '{appearances}');
        $appearances = $needsCount ? $this->appearances($id) : 0;
        $values      = $this->values($listing, $appearances);

        $paragraphs = [];
        foreach (self::paragraphs((string) $campaign['body']) as $p) {
            // Never "appeared 0 times": the paragraph is left out instead.
            if ($appearances === 0 && str_contains($p, '{appearances}')) {
                continue;
            }
            $paragraphs[] = strtr($p, $values);
        }

        [$label, $target] = $campaign['button'] ?? $kind['button'] ?? ['Open my profile', 'business'];
        $unsubscribe      = (new MarketingConsentService())->unsubscribeUrl($id);
        $site             = config('Directory')->siteName();
        $link             = $this->link($target, $listing, $live);

        $html = view('emails/campaign', [
            'site'        => $site,
            'name'        => (string) ($listing['display_name'] ?? ''),
            'noun'        => listing_noun($listing),
            'paragraphs'  => $paragraphs,
            'button'      => [strtr($label, $values), $link['url']],
            'unsubscribe' => $unsubscribe,
            'signIn'      => $target === 'signin',
            'claim'       => $target === 'claim',
            'claimDays'   => $config->claimLinkDays,
        ], ['saveData' => false]);

        return [
            'subject'     => self::oneLine(strtr((string) $campaign['subject'], $values)),
            'html'        => $html,
            'unsubscribe' => $unsubscribe,
            'linkHash'    => $link['hash'],
            'linkExpires' => $link['expires'],
        ];
    }

    /** "Dear Dr A Smith," for a profile that is one person, else "Hello,". The name is never shortened. */
    public static function greeting(array $listing): string
    {
        $name = trim((string) ($listing['display_name'] ?? ''));

        return schema_is_person_profile($listing) && $name !== '' ? 'Dear ' . $name . ',' : 'Hello,';
    }

    /**
     * Blank line separated paragraphs, trimmed, empty ones dropped.
     *
     * @return list<string>
     */
    public static function paragraphs(string $body): array
    {
        $parts = preg_split('/\R\s*\R/', str_replace("\r\n", "\n", $body)) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $p): string => trim((string) preg_replace('/[ \t]*\R[ \t]*/', ' ', $p)),
            $parts
        ), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Braced words that are not merge fields, so the form can refuse a typo
     * ("{nmae}") before it reaches 1,000 inboxes.
     *
     * @return list<string>
     */
    public static function unknownFields(string $text): array
    {
        preg_match_all('/\{[^{}]*\}/', $text, $m);

        return array_values(array_unique(array_diff($m[0], self::FIELDS)));
    }

    /** @return array<string,string> */
    private function values(array $listing, int $appearances): array
    {
        $config = config('Campaigns');
        $source = (string) ($listing['signup_source'] ?? '');

        return [
            '{greeting}'    => self::greeting($listing),
            '{name}'        => trim((string) ($listing['display_name'] ?? '')),
            '{noun}'        => listing_noun($listing),
            '{audience}'    => listing_audience($listing),
            '{appearances}' => number_format($appearances, 0, '.', ' '),
            '{site}'        => config('Directory')->siteName(),
            '{source}'      => $config->sources[$source] ?? $config->defaultSource,
            '{plan}'        => service('site')->forRow($listing) === Sites::APP ? 'Plus' : 'Verified',
        ];
    }

    private function appearances(int $listingId): int
    {
        try {
            $to   = date('Y-m-d');
            $from = date('Y-m-d', strtotime('-' . self::APPEARANCE_DAYS . ' days'));

            return (new GoogleDataService())->listingSearch($listingId, $from, $to)['impressions'];
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The button's link. A live claim link is a fresh token whose hash the
     * caller stores on the message row (CommunicationsService); a preview or
     * test links to the public profile, so it never makes a working link.
     *
     * @return array{url:string,hash:?string,expires:?string}
     */
    private function link(string $target, array $listing, bool $live): array
    {
        if ($target === 'claim') {
            if (! $live) {
                return ['url' => listing_site_url($listing, 'directory/' . $listing['slug']), 'hash' => null, 'expires' => null];
            }
            $token = TokenHash::mint();

            return [
                'url'     => listing_site_url($listing, 'claim/' . $token),
                'hash'    => TokenHash::hash($token),
                'expires' => date('Y-m-d H:i:s', time() + config('Campaigns')->claimLinkDays * DAY),
            ];
        }

        return ['url' => $this->buttonUrl($target, $listing, $live), 'hash' => null, 'expires' => null];
    }

    private function buttonUrl(string $target, array $listing, bool $live): string
    {
        $id = (int) $listing['id'];

        return match ($target) {
            'signin'  => $live ? (new DirectoryListingMutationService())->signInLink($id) : listing_site_url($listing, 'business'),
            'upgrade' => listing_site_url($listing, service('site')->forRow($listing) === Sites::APP ? 'plus' : 'verified'),
            'profile' => listing_site_url($listing, 'directory/' . $listing['slug']),
            default   => listing_site_url($listing, 'business'),
        };
    }

    private static function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
