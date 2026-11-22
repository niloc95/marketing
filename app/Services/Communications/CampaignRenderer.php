<?php

namespace App\Services\Communications;

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
    public const FIELDS = ['{name}', '{noun}', '{audience}', '{appearances}', '{site}', '{source}'];

    /** Days of Google results {appearances} counts. */
    public const APPEARANCE_DAYS = 30;

    /**
     * @param array<string,mixed> $campaign a campaigns row (kind, subject, body)
     * @param array<string,mixed> $listing  a fresh listings row
     * @param bool                $live     a real send: mint the sign in link
     * @return array{subject:string,html:string,unsubscribe:string}
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

        [$label, $target] = $kind['button'];
        $unsubscribe      = (new MarketingConsentService())->unsubscribeUrl($id);
        $site             = config('Directory')->siteName();

        $html = view('emails/campaign', [
            'site'        => $site,
            'name'        => (string) ($listing['display_name'] ?? ''),
            'noun'        => listing_noun($listing),
            'paragraphs'  => $paragraphs,
            'button'      => [strtr($label, $values), $this->buttonUrl($target, $listing, $live)],
            'unsubscribe' => $unsubscribe,
            'signIn'      => $target === 'signin',
        ], ['saveData' => false]);

        return [
            'subject'     => self::oneLine(strtr((string) $campaign['subject'], $values)),
            'html'        => $html,
            'unsubscribe' => $unsubscribe,
        ];
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
            '{name}'        => trim((string) ($listing['display_name'] ?? '')),
            '{noun}'        => listing_noun($listing),
            '{audience}'    => listing_audience($listing),
            '{appearances}' => number_format($appearances, 0, '.', ' '),
            '{site}'        => config('Directory')->siteName(),
            '{source}'      => $config->sources[$source] ?? $config->defaultSource,
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
