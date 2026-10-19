<?php

namespace App\Services;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryListingBrandModel;
use App\Models\DirectoryListingPhotoModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * The brands a business sells, stocks or services: the logo strip under the
 * profile description. Free for every profile, like the photo gallery.
 *
 * The only writer of directory_listing_brands. Adding is additive, like
 * DirectoryListingPhotoModel::appendPhotos(), and runs after the listing is
 * saved: a logo that fails to process must never cost anyone their listing,
 * so every problem comes back as a sentence for the caller to flash.
 *
 * Each brand needs both a name and a logo. The name is the logo's alt text and
 * tooltip, and what search matches, so a logo without one is not accepted.
 */
class ListingBrandService
{
    /** Enough for a dealer or a store that stocks many brands. */
    public const MAX_BRANDS = 20;

    public const NAME_MAX = 80;

    /** Logos render 40px high; 400 covers a wide wordmark on a 3x screen. */
    public const LOGO_SIZE = 400;

    /** Public: inside DirectoryListingPhotoModel::UPLOAD_ROOTS, so deletes work. */
    public const UPLOAD_DIR = 'assets/listings/brands';

    /**
     * Letters, digits and the punctuation real brand names use (Levi's, H&M,
     * Ben & Jerry's, 3M, Dr. Martens, Coca-Cola, Mercedes-Benz).
     */
    private const NAME_ALLOWED = "/^[\\p{L}\\p{M}\\p{Nd} &'\\x{2019}.,\\-+!]+$/u";

    private DirectoryListingBrandModel $brands;

    public function __construct()
    {
        $this->brands = new DirectoryListingBrandModel();
    }

    /** @return array<int,array<string,mixed>> */
    public function forListing(int $listingId): array
    {
        return $this->brands->forListing($listingId);
    }

    public function slots(?int $listingId): int
    {
        return $listingId === null
            ? self::MAX_BRANDS
            : max(0, self::MAX_BRANDS - $this->brands->countForListing($listingId));
    }

    /** Why $name cannot be a brand name, or null when it can. */
    public static function nameProblem(string $name): ?string
    {
        if ($name === '') {
            return 'Please give each brand logo its name.';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return sprintf('Brand names can be at most %d characters.', self::NAME_MAX);
        }
        if (preg_match('#https?://|www\.|\.(co\.za|com|net|org)\b|@#i', $name) === 1) {
            return sprintf('“%s” was not added. Please give the brand name only, without a website or email address.', $name);
        }
        if (preg_match(self::NAME_ALLOWED, $name) !== 1) {
            return sprintf('“%s” was not added. Please use only letters, numbers and ordinary punctuation in brand names.', $name);
        }

        return null;
    }

    /**
     * Add the brands posted as brand_name[i] + brand_logo[i].
     *
     * A row with neither is the normal case (an unused "Add a brand" row) and is
     * skipped in silence. A row with only one half is refused with a message.
     *
     * @param mixed                     $names raw brand_name array off the request
     * @param array<int|string,mixed>   $files brand_logo files, keyed like $names
     *
     * @return list<string> problems to flash; the brands that passed are stored
     */
    public function addFromForm(int $listingId, mixed $names, array $files): array
    {
        $names  = is_array($names) ? $names : [];
        $errors = [];
        $rows   = [];

        $indexes = array_unique(array_merge(array_keys($names), array_keys($files)));
        sort($indexes);

        $existing = $this->brands->forListing($listingId);
        $taken    = array_map(static fn (array $b): string => mb_strtolower((string) $b['name']), $existing);
        $slots    = max(0, self::MAX_BRANDS - count($existing));

        $processor = new ListingImageProcessor();

        foreach ($indexes as $index) {
            $name = is_string($names[$index] ?? null)
                ? trim((string) preg_replace('/\s+/u', ' ', $names[$index]))
                : '';
            $file = $files[$index] ?? null;
            $hasFile = $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE;

            if ($name === '' && ! $hasFile) {
                continue;
            }
            if (! $hasFile) {
                $errors[] = sprintf('“%s” was not added. Please choose a logo for it.', $name);
                continue;
            }
            if (($problem = self::nameProblem($name)) !== null) {
                $errors[] = $problem;
                continue;
            }
            if (in_array(mb_strtolower($name), $taken, true)) {
                $errors[] = sprintf('“%s” is already on your profile.', $name);
                continue;
            }
            if (count($rows) >= $slots) {
                $errors[] = sprintf(
                    '“%s” was not added. A profile can show at most %d brands.',
                    $name,
                    self::MAX_BRANDS
                );
                continue;
            }

            $result = $processor->process(
                $file,
                rtrim(FCPATH, '/') . '/' . self::UPLOAD_DIR,
                'brand',
                self::LOGO_SIZE
            );
            if (! $result['ok']) {
                $errors[] = $result['error'];
                continue;
            }

            $taken[] = mb_strtolower($name);
            $rows[]  = [
                'listing_id' => $listingId,
                'name'       => $name,
                'logo_path'  => $result['path'],
                'width'      => $result['width'],
                'height'     => $result['height'],
                'sort_order' => count($existing) + count($rows),
            ];
        }

        if ($rows !== []) {
            $this->brands->insertBatch($rows);
        }

        return $errors;
    }

    /**
     * Delete one brand and its logo file.
     *
     * @param int|null $listingId the owner's listing; null for admin, who may
     *                            delete any brand
     *
     * @return int|null the listing the brand belonged to, or null when there was
     *                  no such brand (or it belongs to someone else)
     */
    public function delete(int $brandId, ?int $listingId): ?int
    {
        $brand = $this->brands->find($brandId);
        if (! is_array($brand) || ($listingId !== null && (int) $brand['listing_id'] !== $listingId)) {
            return null;
        }

        (new DirectoryListingPhotoModel())->deleteFileAt((string) $brand['logo_path']);
        $this->brands->delete($brandId);

        return (int) $brand['listing_id'];
    }
}
