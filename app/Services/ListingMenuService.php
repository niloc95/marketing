<?php

namespace App\Services;

use App\Libraries\ListingImageProcessor;
use App\Libraries\VerificationDocumentProcessor;
use App\Models\DirectoryListingMenuFileModel;
use App\Models\DirectoryListingPhotoModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * A food listing's uploaded menu — one PDF, or up to four photographed pages.
 *
 * Not to be confused with ServiceMenuService, which is the typed-in list of
 * services and prices that a restaurant's profile already heads "Menu". This is
 * the file the restaurant already has: the designed PDF, or a phone photo of
 * the laminated card by the till. Both are shown in the same panel.
 *
 * The rules, all enforced here so the three controllers that save a listing
 * cannot disagree:
 *
 *  - **Only food listings.** Restaurants & Food, plus the few food businesses
 *    that live in other groups (caterer, home baker, cake artist). Anyone else
 *    uploading is told no, loudly, and nothing is stored.
 *  - **One PDF, or photos — never both.** A menu is one or the other. Mixing
 *    them would leave the profile unsure which to show first.
 *  - **An upload replaces the whole menu.** A new menu is never page five of
 *    last season's. The old files are deleted only once the new ones are safely
 *    stored, so a failed upload leaves the current menu exactly as it was.
 *  - **Free for everyone**, like photos and services — nothing here reads the
 *    Verified Business badge, which keeps ListingQualityService's rule intact.
 *
 * PDFs are stored byte-for-byte by VerificationDocumentProcessor under
 * writable/, outside the docroot, and served by Directory::menu() with the same
 * sandboxed policy the admin document viewer uses. A PDF is attacker-supplied
 * bytes on our origin; Apache serving it straight from public/ would give up
 * the nosniff and sandbox headers that make that safe. Photos go through
 * ListingImageProcessor like any gallery photo and are served from public/.
 */
class ListingMenuService
{
    public const MAX_PAGES = 4;

    /** The group whose every category has a menu. */
    public const GROUP = 'Restaurants & Food';

    /** Food businesses filed under other groups. Keys must be seeded slugs. */
    public const EXTRA_SLUGS = ['caterer', 'home-baker', 'cake-artist'];

    /** Where page photos go, relative to FCPATH. */
    public const IMAGE_DIR = 'assets/listings/menu';

    private DirectoryListingMenuFileModel $files;

    public function __construct()
    {
        $this->files = new DirectoryListingMenuFileModel();
    }

    public static function offersMenu(?string $group, ?string $slug): bool
    {
        return $group === self::GROUP || in_array((string) $slug, self::EXTRA_SLUGS, true);
    }

    /** Outside the docroot, for the reason the class docblock gives. */
    public static function storageRoot(): string
    {
        return rtrim(WRITEPATH, '/\\') . '/menus';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forListing(int $listingId): array
    {
        return $this->files->forListing($listingId);
    }

    /**
     * Store an upload as the listing's new menu, replacing the old one.
     *
     * @param list<UploadedFile> $uploads files with UPLOAD_ERR_NO_FILE already removed
     *
     * @return list<string> user-facing sentences; empty when everything was stored
     */
    public function replace(int $listingId, array $uploads): array
    {
        if ($uploads === []) {
            return [];
        }

        $isPdf = static fn (UploadedFile $f): bool => strtolower((string) $f->getClientExtension()) === 'pdf';
        $pdfs  = array_values(array_filter($uploads, $isPdf));

        if ($pdfs !== [] && count($uploads) > 1) {
            return ['Your menu was not changed. Upload either one PDF or up to '
                . self::MAX_PAGES . ' photos of the pages, not both.'];
        }

        $errors = [];
        $rows   = [];

        if ($pdfs !== []) {
            $stored = $this->storePdf($listingId, $pdfs[0]);
            if ($stored['error'] !== '') {
                $errors[] = $stored['error'];
            } else {
                $rows[] = $stored['row'];
            }
        } else {
            $processor = new ListingImageProcessor();
            foreach ($uploads as $i => $file) {
                if ($i >= self::MAX_PAGES) {
                    $errors[] = sprintf(
                        '“%s” was not added — a menu can have at most %d pages.',
                        $file->getClientName(),
                        self::MAX_PAGES
                    );
                    continue;
                }

                $result = $processor->process($file, rtrim(FCPATH, '/') . '/' . self::IMAGE_DIR, 'menu');
                if (! $result['ok']) {
                    $errors[] = $result['error'];
                    continue;
                }

                $rows[] = [
                    'kind'          => DirectoryListingMenuFileModel::KIND_IMAGE,
                    'path'          => $result['path'],
                    'original_name' => mb_substr($file->getClientName(), 0, 255),
                    'bytes'         => null,
                    'width'         => $result['width'],
                    'height'        => $result['height'],
                ];
            }
        }

        // Nothing usable arrived: keep the menu that is there. A rejected upload
        // costing a restaurant the menu it already had would be the worst of both.
        if ($rows === []) {
            return $errors;
        }

        $old = $this->files->forListing($listingId);

        $sort = 0;
        foreach ($rows as $row) {
            $this->files->insert($row + ['listing_id' => $listingId, 'sort_order' => $sort++]);
        }
        $this->deleteRows($old);

        return $errors;
    }

    /** Remove the listing's menu, rows and files. */
    public function remove(int $listingId): void
    {
        $this->deleteRows($this->files->forListing($listingId));
    }

    /**
     * Absolute path to a PDF row's file, or null if it is missing or resolves
     * outside the storage root — a tampered path must never reach readfile().
     *
     * @param array<string,mixed> $row
     */
    public function pdfPath(array $row): ?string
    {
        if (($row['kind'] ?? '') !== DirectoryListingMenuFileModel::KIND_PDF) {
            return null;
        }

        $root = realpath(self::storageRoot());
        if ($root === false) {
            return null;
        }
        $root = rtrim($root, '/');
        $full = realpath($root . '/' . ltrim((string) $row['path'], '/'));

        return ($full !== false && str_starts_with($full, $root . '/') && is_file($full)) ? $full : null;
    }

    /**
     * @return array{row:array<string,mixed>,error:string}
     */
    private function storePdf(int $listingId, UploadedFile $file): array
    {
        // The stored name carries that processor's "verif_" prefix. It never
        // reaches a visitor — Directory::menu() names the download after the
        // listing — so it is not worth a parameter on the processor.
        $destDir = self::storageRoot() . '/' . $listingId;
        $result  = (new VerificationDocumentProcessor())->process($file, $destDir);

        if (! $result['ok']) {
            return ['row' => [], 'error' => $result['error']];
        }

        return ['row' => [
            'kind'          => DirectoryListingMenuFileModel::KIND_PDF,
            'path'          => $listingId . '/' . $result['name'],
            'original_name' => mb_substr($file->getClientName(), 0, 255),
            'bytes'         => $result['bytes'],
            'width'         => null,
            'height'        => null,
        ], 'error' => ''];
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function deleteRows(array $rows): void
    {
        $public = new DirectoryListingPhotoModel();

        foreach ($rows as $row) {
            if (($row['kind'] ?? '') === DirectoryListingMenuFileModel::KIND_PDF) {
                $full = $this->pdfPath($row);
                if ($full !== null) {
                    @unlink($full);
                }
            } else {
                // deleteFileAt() carries the FCPATH containment check.
                $public->deleteFileAt((string) $row['path']);
            }
            $this->files->delete((int) $row['id']);
        }
    }
}
