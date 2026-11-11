<?php

namespace App\Services;

use App\Libraries\ProfessionalRegistration;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryListingTeamModel;

/**
 * The people listed under a business — reads for the profile page, writes for
 * the owner dashboard and the admin listing form.
 *
 * The team lives inside the listing form, so there is one submit button and no
 * per-row endpoint: syncFromForm() reconciles the whole set at once, the way
 * DirectoryTagModel::syncListingTags() does for tags.
 *
 * Headshot files and team rows are two kinds of state, and every bug this can
 * have is the two disagreeing. The rule throughout is: write the rows inside the
 * caller's transaction, and unlink files only once it has committed — which is
 * why syncFromForm() hands orphaned paths back rather than deleting them itself.
 * Orphaning a file is recoverable; a row pointing at a file that is gone is not.
 *
 * Every method that touches a member takes the listing id as well and resolves
 * through DirectoryListingTeamModel::findForListing(). The check lives here
 * rather than in the controllers because three write paths call in and only one
 * of them has a session that scopes anything.
 */
class TeamMemberService
{
    /**
     * Past this it stops being a team panel and starts being a staff directory
     * nobody scrolls. Generous enough for the practices this is aimed at.
     */
    public const MAX_MEMBERS = 12;

    /** Areas of law/focus per person, and the length of each. */
    private const MAX_SPECIALIZATIONS = 12;
    private const MAX_SPECIALIZATION_LENGTH = 60;

    /** Long enough for a professional summary, short enough to stay a panel. */
    private const MAX_BIO_LENGTH = 1200;

    /** Public: HandlesListingUploads writes the files, this class owns where they go. */
    public const UPLOAD_DIR = 'assets/listings/team';

    /**
     * Headshots render at a few hundred pixels. The 1600 the gallery uses would
     * be bytes nobody sees, twelve times per profile.
     */
    public const PHOTO_SIZE = 600;

    private DirectoryListingTeamModel $members;
    private DirectoryListingPhotoModel $files;

    public function __construct()
    {
        $this->members = new DirectoryListingTeamModel();
        $this->files   = new DirectoryListingPhotoModel();
    }

    // ------------------------------------------------------------------ reads

    /** @return array<int,array<string,mixed>> */
    public function forListing(int $listingId): array
    {
        return $this->members->forListing($listingId);
    }

    /**
     * May this listing have a team at all?
     *
     * Team members are part of the paid Verified Business badge, and the gate is
     * the same render-time date comparison the badge itself uses — no new
     * column, nothing extra to keep in sync, and it goes false on the day the
     * subscription stopped being paid for rather than whenever a sweep runs.
     *
     * What this deliberately does not do is delete anything. A badge that lapses
     * hides the panel and freezes the editor; the rows stay, and reactivating
     * brings the team straight back. Destroying a subscriber's content because a
     * card failed would be the wrong failure mode.
     *
     * @param array<string,mixed> $listing
     */
    public function canManage(array $listing): bool
    {
        // The badge on co.za, or Plus on .app (listing_has_premium()).
        return listing_has_premium($listing);
    }

    /**
     * Split a stored specializations column back into chips for rendering.
     *
     * @return array<int,string>
     */
    public static function splitSpecializations(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        // "Aesthetic Medicine, Dermatology, and Body Contouring": the Oxford
        // comma leaves "and Body Contouring" as a topic of its own, so a
        // leading "and" or "&" comes off each part. Semicolons split too.
        return array_values(array_filter(
            array_map(
                static fn (string $s): string => trim(preg_replace('/^(?:and|&)\s+/iu', '', trim($s)) ?? $s),
                preg_split('/[,;]/', $stored) ?: []
            ),
            static fn (string $s) => $s !== ''
        ));
    }

    // ----------------------------------------------------------------- writes

    /**
     * Reconcile a listing's whole team against one form submission.
     *
     * Replaces the per-row save()/delete() pair this service started with. Team
     * members now live *inside* the listing form, which means there is exactly
     * one submit button and no per-row endpoint to post to — so the unit of work
     * is the whole set, the way DirectoryTagModel::syncListingTags() already
     * handles tags.
     *
     * Rules, all of which the location service repeats:
     *
     * - A row with a blank name is skipped entirely. That is what makes the
     *   trailing blank slot harmless, and it means "clear the name" can never be
     *   a silent partial write.
     * - A row carrying an id is resolved through findForListing(), so **an id
     *   belonging to another listing is treated as absent and inserted as a new
     *   row — never used to update theirs.** This is the same protection the
     *   per-row endpoints had; the ids simply arrive in a form body now instead
     *   of a URL, and nothing else stands between one owner and another's rows.
     * - A stored row whose id is not in the submission is deleted, **unless a
     *   booking provider is that person** (T31): then it is only hidden from
     *   the profile, and Bookings keeps them until they are archived there.
     * - sort_order is the submitted position, so reordering is just moving the
     *   fields.
     * - "Show on my profile" is read only when the row carries its marker
     *   (`show_present`), so a form without the switch leaves it as it is.
     *   At most MAX_MEMBERS people are shown; hidden ones do not count.
     *
     * Deleted and replaced headshots are NOT unlinked here. Their paths come
     * back in `orphans` and the caller unlinks them after the transaction
     * commits — orphaning a file is recoverable, a row pointing at a file that
     * is gone is not.
     *
     * Each person's professional registration (number, ORCID, ResearchGate)
     * goes through ProfessionalRegistration::personColumns() against the
     * listing's $regulator. Only $admin may set the "checked against the
     * register" mark; an owner's change of number clears it.
     *
     * @param mixed                    $rows      the raw `team` array off the request
     * @param array<string,mixed>|null $regulator the listing category's, or null
     *
     * @return array{errors:array<string,string>,orphans:array<int,string>,ids:array<int|string,int>}
     *         `ids` maps each saved row's form index to its card id
     */
    public function syncFromForm(int $listingId, mixed $rows, ?array $regulator = null, bool $admin = false): array
    {
        $rows = is_array($rows) ? $rows : [];

        $submitted = [];
        $errors    = [];
        $orphans   = [];

        $position = 0;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            // Resolved before the blank check so that clearing a stored row's
            // name deletes it, rather than leaving an unreachable row behind.
            $existing = null;
            $id       = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $existing = $this->members->findForListing($id, $listingId);
            }

            // A blank name or a ticked Remove box both mean "this row is gone".
            // Neither is an error: a blank trailing slot is the normal way the
            // form offers somewhere to type.
            if (trim((string) ($row['name'] ?? '')) === '' || ! empty($row['_remove'])) {
                continue;
            }

            $data = $this->normaliseRow($row, $existing, $regulator, $admin, sprintf('team.%d.', $index), $errors);
            if ($data === null) {
                continue;
            }

            $submitted[] = [
                'index'    => $index,
                'existing' => $existing,
                'data'     => ['listing_id' => $listingId, 'sort_order' => $position++] + $data,
                // resolveTeamPhotos() put this here if a new file was uploaded
                // for this row. Empty means "keep whatever the row already has",
                // the same convention resolveLogo() uses for the listing.
                'photo'    => trim((string) ($row['photo_path'] ?? '')),
            ];
        }

        $shown = count(array_filter($submitted, static fn (array $r): bool => (int) $r['data']['show_on_profile'] === 1));
        if ($shown > self::MAX_MEMBERS) {
            $errors['team'] = sprintf(
                'A profile can show at most %d team members. You have %d.',
                self::MAX_MEMBERS,
                $shown
            );
        }

        if ($errors !== []) {
            // Every uploaded file in this submission is now unreferenced. Hand
            // them back so the caller can bin them along with the ones a
            // successful save would have replaced.
            foreach ($submitted as $row) {
                if ($row['photo'] !== '') {
                    $orphans[] = $row['photo'];
                }
            }

            return ['errors' => $errors, 'orphans' => $orphans, 'ids' => []];
        }

        $keptIds = [];
        $ids     = [];

        foreach ($submitted as $row) {
            $id = $this->write($listingId, $row['existing'], $row['data'], $row['photo'], $orphans);
            $keptIds[]          = $id;
            $ids[$row['index']] = $id;
        }

        // Anything stored that the submission did not carry has been removed by
        // the owner — either the Remove box or a cleared name. A person Bookings
        // still has is hidden instead (T31).
        $inBookings = self::cardsInBookings($listingId);
        foreach ($this->members->forListing($listingId) as $stored) {
            if (in_array((int) $stored['id'], $keptIds, true)) {
                continue;
            }
            if (in_array((int) $stored['id'], $inBookings, true)) {
                $this->members->update((int) $stored['id'], ['show_on_profile' => 0]);

                continue;
            }

            if (! empty($stored['photo_path'])) {
                $orphans[] = (string) $stored['photo_path'];
            }
            $this->members->delete((int) $stored['id']);
        }

        return ['errors' => $errors, 'orphans' => $orphans, 'ids' => $ids];
    }

    /**
     * One person, from Bookings (T31): the same checks as a row of the
     * profile form, without touching anyone else. Bookings writes only
     * through here, so a person has one record wherever they were added.
     * `show_on_profile` is honoured when the row carries `show_present`;
     * a new person without it starts hidden.
     *
     * @param array<string,mixed>      $row       name, role, credentials, specializations, bio, photo_path, registration, show_on_profile
     * @param array<string,mixed>|null $regulator the listing category's, or null
     *
     * @return array{id:?int,errors:array<string,string>,orphans:array<int,string>}
     */
    public function saveOne(int $listingId, ?int $id, array $row, ?array $regulator = null, bool $admin = false): array
    {
        $existing = null;
        if ($id !== null) {
            $existing = $this->members->findForListing($id, $listingId);
            if ($existing === null) {
                return ['id' => null, 'errors' => ['name' => 'That person is not part of this business.'], 'orphans' => []];
            }
        }
        if ($existing === null && ! array_key_exists('show_present', $row)) {
            $row['show_present'] = '1';
            $row['show_on_profile'] = '';
        }

        $errors = [];
        $photo  = trim((string) ($row['photo_path'] ?? ''));
        $data   = trim((string) ($row['name'] ?? '')) === ''
            ? null
            : $this->normaliseRow($row, $existing, $regulator, $admin, '', $errors);
        if ($data === null && $errors === []) {
            $errors['name'] = 'Each name must be between 2 and 150 characters.';
        }
        if ($data !== null && (int) $data['show_on_profile'] === 1) {
            $shown = $this->members->where('listing_id', $listingId)->where('show_on_profile', 1);
            if ($id !== null) {
                $shown->where('id !=', $id);
            }
            if ($shown->countAllResults() >= self::MAX_MEMBERS) {
                $errors['show_on_profile'] = sprintf('A profile can show at most %d team members.', self::MAX_MEMBERS);
            }
        }
        if ($errors !== []) {
            return ['id' => null, 'errors' => $errors, 'orphans' => $photo !== '' ? [$photo] : []];
        }

        $data['listing_id'] = $listingId;
        if ($existing === null) {
            $last               = $this->members->selectMax('sort_order', 'n')->where('listing_id', $listingId)->first();
            $data['sort_order'] = (int) ($last['n'] ?? -1) + 1;
        }
        $orphans = [];
        $id      = $this->write($listingId, $existing, $data, $photo, $orphans);

        return ['id' => $id, 'errors' => [], 'orphans' => $orphans];
    }

    /**
     * Ids of this listing's cards that a booking provider (not archived) is.
     *
     * @return list<int>
     */
    public static function cardsInBookings(int $listingId): array
    {
        return array_map('intval', array_column(
            db_connect()->table('xs_booking_providers')->select('team_member_id')
                ->where('listing_id', $listingId)->where('archived_at', null)->where('team_member_id IS NOT NULL')
                ->get()->getResultArray(),
            'team_member_id'
        ));
    }

    /**
     * The checks every person goes through, from either form. Null when the
     * name is unusable (its error is in $errors).
     *
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed>|null $regulator
     * @param array<string,string>     $errors
     *
     * @return array<string,mixed>|null
     */
    private function normaliseRow(array $row, ?array $existing, ?array $regulator, bool $admin, string $prefix, array &$errors): ?array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors[$prefix . 'name'] = 'Each name must be between 2 and 150 characters.';

            return null;
        }

        $role        = $this->limited($row['role'] ?? '', 120, $prefix . 'role', 'The position', $errors);
        $credentials = $this->limited($row['credentials'] ?? '', 255, $prefix . 'credentials', 'The qualifications', $errors);
        $bio         = $this->limited($row['bio'] ?? '', self::MAX_BIO_LENGTH, $prefix . 'bio', 'The bio', $errors);

        $specializations = $this->normaliseSpecializations(
            $row['specializations'] ?? '',
            $errors,
            $prefix . 'specializations'
        );

        $registration = ProfessionalRegistration::personColumns(
            $row,
            $existing,
            $regulator,
            $admin && array_key_exists('reg_checked_present', $row) ? ! empty($row['reg_checked']) : null
        );
        foreach ($registration['errors'] as $field => $message) {
            $errors[$prefix . $field] = $message;
        }

        // Absent marker: keep what is stored; a new card is shown, as the
        // profile form always did.
        $shown = array_key_exists('show_present', $row)
            ? (int) ! empty($row['show_on_profile'])
            : (int) ($existing['show_on_profile'] ?? 1);

        return [
            'name'            => $name,
            'role'            => $role ?: null,
            'credentials'     => $credentials ?: null,
            'specializations' => $specializations ?: null,
            'bio'             => $bio ?: null,
            'show_on_profile' => $shown,
        ] + $registration['columns'];
    }

    /**
     * Insert or update one card, with its slug and photo.
     *
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed>      $data
     * @param array<int,string>        $orphans
     */
    private function write(int $listingId, ?array $existing, array $data, string $photo, array &$orphans): int
    {
        $id = $existing === null ? null : (int) $existing['id'];

        $data['slug'] = $this->members->uniqueSlug(slugify($data['name']), $listingId, $id);

        if ($photo !== '') {
            $data['photo_path'] = $photo;
            // The file this one replaces is unreferenced the moment the row
            // is written — but only once the transaction commits.
            if ($existing !== null && ! empty($existing['photo_path'])) {
                $orphans[] = (string) $existing['photo_path'];
            }
        }

        if ($id === null) {
            $this->members->insert($data);

            return (int) $this->members->getInsertID();
        }
        $this->members->update($id, $data);

        return $id;
    }

    /**
     * Unlink files that the sync left behind. Call only after the transaction
     * that wrote the rows has committed.
     *
     * @param array<int,string> $paths
     */
    public function discardFiles(array $paths): void
    {
        foreach ($paths as $path) {
            $this->discard($path);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Comma-separated areas of focus, cleaned up.
     *
     * Same input convention as the listing's own specializations field: what the
     * owner types is a sentence with commas in it, and what gets stored is a
     * canonical, de-duplicated version of the same string. Over-long or
     * over-numerous entries are an error rather than a silent truncation —
     * dropping half of what someone typed while reporting "saved" is the bug
     * the upload handling exists to avoid, and it is no better here.
     *
     * @param array<string,string> $errors
     */
    private function normaliseSpecializations(mixed $raw, array &$errors, string $errorKey = 'specializations'): string
    {
        $parts = array_filter(
            array_map('trim', explode(',', (string) $raw)),
            static fn (string $s) => $s !== ''
        );

        // Case-insensitive, so "Conveyancing" and "conveyancing" do not both
        // survive as separate chips. The first spelling typed wins.
        $seen   = [];
        $unique = [];
        foreach ($parts as $part) {
            $key = mb_strtolower($part);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[]   = $part;

            if (mb_strlen($part) > self::MAX_SPECIALIZATION_LENGTH) {
                $errors[$errorKey] = sprintf(
                    'Each area of focus must be %d characters or fewer. “%s” is longer.',
                    self::MAX_SPECIALIZATION_LENGTH,
                    mb_substr($part, 0, 30) . '…'
                );
            }
        }

        if (count($unique) > self::MAX_SPECIALIZATIONS) {
            $errors[$errorKey] = sprintf(
                'List at most %d areas of focus per person.',
                self::MAX_SPECIALIZATIONS
            );
        }

        return implode(', ', $unique);
    }


    /**
     * Trim a free-text field and flag it if it is too long.
     *
     * @param array<string,string> $errors
     */
    private function limited(mixed $raw, int $max, string $field, string $label, array &$errors): string
    {
        $value = trim((string) $raw);

        if (mb_strlen($value) > $max) {
            $errors[$field] = sprintf('%s must be %d characters or fewer.', $label, $max);
        }

        return $value;
    }


    /** Remove a file that ended up belonging to nothing. */
    private function discard(string $path): void
    {
        if ($path !== '') {
            $this->files->deleteFileAt($path);
        }
    }

}
