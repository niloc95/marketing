<?php

namespace App\Services;

use App\Libraries\ListingText;
use App\Libraries\RichText;
use App\Models\DirectoryListingSectionModel;

/**
 * Extra description cards (owner, 19 Nov 2026).
 *
 * A profile's description is card 1 (the listing's own `description`, every
 * plan). Verified (co.za) and Plus (.app) add up to MAX_EXTRA more, each a
 * heading and a rich text body of up to MAX_PLAIN_LENGTH characters, shown as
 * their own panels under "About our practice". First asked for by Dr A. D.
 * Bhatta (profile 392), whose procedures, conditions and approach did not fit
 * one description.
 *
 * The only writer of xs_directory_listing_sections. Same shape as
 * PracticeLocationService: one gate (listing_has_premium(), checked on the
 * STORED row), a lapse hides the cards and never deletes them, a row id is
 * only used when it belongs to this profile, and the `sections_present`
 * marker tells "left alone" from "every card removed".
 *
 * Cards never score (ListingQualityService) and never rank: they are paid,
 * and paying must not move a profile up. Discover finds a profile by them,
 * gated like the team (DirectoryService::sectionExists()).
 */
class ListingSectionService
{
    /** Cards beyond the description: 6 in all with it (owner). */
    public const MAX_EXTRA = 5;

    /** Plain text characters per card, the same as the description's. */
    public const MAX_PLAIN_LENGTH = RichText::MAX_PLAIN_LENGTH;

    public const MAX_HEADING_LENGTH = 80;

    /** Posted with the form section so an empty list can mean "remove all". */
    public const MARKER = 'sections_present';

    private DirectoryListingSectionModel $sections;

    public function __construct()
    {
        $this->sections = new DirectoryListingSectionModel();
    }

    /** @return list<array<string,mixed>> every stored card, shown or not */
    public function forListing(int $listingId): array
    {
        return $this->sections->forListing($listingId);
    }

    /**
     * The cards the public profile shows: none unless the plan is live.
     *
     * @param array<string,mixed> $listing
     * @return list<array<string,mixed>>
     */
    public function forProfile(array $listing): array
    {
        if (! $this->canManage($listing) || empty($listing['id'])) {
            return [];
        }

        return array_slice($this->forListing((int) $listing['id']), 0, self::MAX_EXTRA);
    }

    /**
     * The cards a form submission would show, without saving: the owner's
     * preview of unsaved edits. Same cleaning as a save; rows a save would
     * refuse are left out rather than reported (the save reports them).
     *
     * @param array<string,mixed> $listing the stored row (the gate)
     * @return list<array<string,mixed>>
     */
    public function fromInput(array $listing, mixed $rows): array
    {
        if (! $this->canManage($listing) || ! is_array($rows)) {
            return [];
        }

        $cards = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! empty($row['_remove'])) {
                continue;
            }
            $heading = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($row['heading'] ?? ''))));
            $body    = RichText::sanitise((string) ($row['body'] ?? ''));
            if ($heading === '' || trim(RichText::toPlainText($body)) === '') {
                continue;
            }
            $cards[] = ['heading' => mb_substr($heading, 0, self::MAX_HEADING_LENGTH), 'body' => $body];
        }

        return array_slice($cards, 0, self::MAX_EXTRA);
    }

    /** @param array<string,mixed> $listing */
    public function canManage(array $listing): bool
    {
        return listing_has_premium($listing);
    }

    /**
     * Reconcile a profile's cards against one form submission.
     *
     * A row with neither heading nor text is a spare slot and is skipped;
     * `_remove` deletes. Nothing is written while any row has an error.
     *
     * @param mixed $rows the raw `sections` array off the request
     * @return array{errors:array<string,string>,orphans:array<int,string>}
     */
    public function syncFromForm(int $listingId, mixed $rows): array
    {
        $rows      = is_array($rows) ? $rows : [];
        $submitted = [];
        $errors    = [];
        $position  = 0;

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! empty($row['_remove'])) {
                continue;
            }

            $prefix  = sprintf('sections.%d.', $index);
            $heading = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($row['heading'] ?? ''))));
            $body    = RichText::sanitise((string) ($row['body'] ?? ''));
            $text    = trim(RichText::toPlainText($body));

            if ($heading === '' && $text === '') {
                continue;
            }

            if ($heading === '') {
                $errors[$prefix . 'heading'] = 'Give this card a heading, for example "Procedures".';
            } elseif (mb_strlen($heading) > self::MAX_HEADING_LENGTH) {
                $errors[$prefix . 'heading'] = sprintf('Keep the heading under %d characters.', self::MAX_HEADING_LENGTH);
            } else {
                $heading = ListingText::tidyName($heading);
            }

            if ($text === '') {
                $errors[$prefix . 'body'] = 'Write something under this heading, or remove the card.';
            } elseif (mb_strlen($text) > self::MAX_PLAIN_LENGTH) {
                $errors[$prefix . 'body'] = sprintf('Please keep each card under %s characters.', number_format(self::MAX_PLAIN_LENGTH));
            }

            $id       = (int) ($row['id'] ?? 0);
            $existing = $id > 0 ? $this->sections->findForListing($id, $listingId) : null;

            $submitted[] = [
                'existing' => $existing,
                'data'     => [
                    'listing_id' => $listingId,
                    'heading'    => $heading,
                    'body'       => ListingText::tidyDescriptionHtml($body),
                    'body_text'  => $text,
                    'sort_order' => $position++,
                ],
            ];
        }

        if (count($submitted) > self::MAX_EXTRA) {
            $errors['sections'] = sprintf(
                'A profile can have at most %d extra cards. You have %d.',
                self::MAX_EXTRA,
                count($submitted)
            );
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'orphans' => []];
        }

        $keptIds = [];
        foreach ($submitted as $row) {
            if ($row['existing'] === null) {
                $this->sections->insert($row['data']);
                $keptIds[] = (int) $this->sections->getInsertID();
                continue;
            }

            $id = (int) $row['existing']['id'];
            $this->sections->update($id, $row['data']);
            $keptIds[] = $id;
        }

        foreach ($this->sections->forListing($listingId) as $stored) {
            if (! in_array((int) $stored['id'], $keptIds, true)) {
                $this->sections->delete((int) $stored['id']);
            }
        }

        return ['errors' => [], 'orphans' => []];
    }
}
