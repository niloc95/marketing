<?php

namespace App\Services;

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
        helper(['directory_ui', 'slug']);
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
        return listing_is_verified_business($listing);
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

        return array_values(array_filter(
            array_map('trim', explode(',', $stored)),
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
     * - A stored row whose id is not in the submission is deleted.
     * - sort_order is the submitted position, so reordering is just moving the
     *   fields.
     *
     * Deleted and replaced headshots are NOT unlinked here. Their paths come
     * back in `orphans` and the caller unlinks them after the transaction
     * commits — orphaning a file is recoverable, a row pointing at a file that
     * is gone is not.
     *
     * @param mixed $rows the raw `team` array off the request
     *
     * @return array{errors:array<string,string>,orphans:array<int,string>}
     */
    public function syncFromForm(int $listingId, mixed $rows): array
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

            $name = trim((string) ($row['name'] ?? ''));

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
            if ($name === '' || ! empty($row['_remove'])) {
                continue;
            }

            if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
                $errors[sprintf('team.%d.name', $index)] = 'Each name must be between 2 and 150 characters.';
                continue;
            }

            $prefix      = sprintf('team.%d.', $index);
            $role        = $this->limited($row['role'] ?? '', 120, $prefix . 'role', 'The position', $errors);
            $credentials = $this->limited($row['credentials'] ?? '', 255, $prefix . 'credentials', 'The qualifications', $errors);
            $bio         = $this->limited($row['bio'] ?? '', self::MAX_BIO_LENGTH, $prefix . 'bio', 'The bio', $errors);

            $specializations = $this->normaliseSpecializations(
                $row['specializations'] ?? '',
                $errors,
                $prefix . 'specializations'
            );

            $submitted[] = [
                'existing' => $existing,
                'data'     => [
                    'listing_id'      => $listingId,
                    'name'            => $name,
                    'role'            => $role ?: null,
                    'credentials'     => $credentials ?: null,
                    'specializations' => $specializations ?: null,
                    'bio'             => $bio ?: null,
                    'sort_order'      => $position++,
                ],
                // resolveTeamPhotos() put this here if a new file was uploaded
                // for this row. Empty means "keep whatever the row already has",
                // the same convention resolveLogo() uses for the listing.
                'photo'    => trim((string) ($row['photo_path'] ?? '')),
            ];
        }

        if (count($submitted) > self::MAX_MEMBERS) {
            $errors['team'] = sprintf(
                'A profile can list at most %d team members — you have %d.',
                self::MAX_MEMBERS,
                count($submitted)
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

            return ['errors' => $errors, 'orphans' => $orphans];
        }

        $keptIds = [];

        foreach ($submitted as $row) {
            $data     = $row['data'];
            $existing = $row['existing'];
            $id       = $existing === null ? null : (int) $existing['id'];

            $data['slug'] = $this->members->uniqueSlug(slugify($data['name']), $listingId, $id);

            if ($row['photo'] !== '') {
                $data['photo_path'] = $row['photo'];
                // The file this one replaces is unreferenced the moment the row
                // is written — but only once the transaction commits.
                if ($existing !== null && ! empty($existing['photo_path'])) {
                    $orphans[] = (string) $existing['photo_path'];
                }
            }

            if ($id === null) {
                $this->members->insert($data);
                $keptIds[] = (int) $this->members->getInsertID();
            } else {
                $this->members->update($id, $data);
                $keptIds[] = $id;
            }
        }

        // Anything stored that the submission did not carry has been removed by
        // the owner — either the Remove box or a cleared name.
        foreach ($this->members->forListing($listingId) as $stored) {
            if (in_array((int) $stored['id'], $keptIds, true)) {
                continue;
            }

            if (! empty($stored['photo_path'])) {
                $orphans[] = (string) $stored['photo_path'];
            }
            $this->members->delete((int) $stored['id']);
        }

        return ['errors' => $errors, 'orphans' => $orphans];
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
                    'Each area of focus must be %d characters or fewer — “%s” is longer.',
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
