<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesListingUploads;
use App\Models\DirectoryListingPhotoModel;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\TeamService;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use App\Services\ListingBrandService;
use App\Services\ListingFacetService;
use App\Services\ListingMenuService;
use App\Services\ListingQualityService;
use App\Services\PracticeLocationService;
use App\Services\ServiceMenuService;
use App\Services\TeamMemberService;

/**
 * The business profile in the business area, one page per part of the form
 * (/business/profile/{section}, Config\ProfileSections): Details, Where and
 * hours, Services, Photos and video, Team, Branches. Was the one long page at
 * /manage/edit (owner, 10 Nov 2026).
 *
 * Every page posts only its own fields; DirectoryListingMutationService::
 * updateOwnSection() keeps the rest as stored. Team and Branches are the
 * Verified badge's (co.za) or Plus's (.app): locked for a Free profile, and the
 * services refuse them again on save. While online bookings are usable, Team
 * and (once the catalogue owns them) Services are the bookings pages.
 *
 * Routes pass businessauth:owner and businesscan:profile.edit.
 */
class Profile extends BaseController
{
    use HandlesListingUploads;

    public function section(string $section)
    {
        $listing = service('businessContext')->listing();
        $moved   = $this->movedToBookings($section, $listing);
        if ($moved !== null) {
            return redirect()->to(base_url($moved));
        }
        $locked = $this->locked($section, $listing);
        if ($locked !== null) {
            return $locked;
        }

        // Not kept for later views: $section would draw the next form as one page.
        return view('business/profile', $this->formData($section, $listing), ['saveData' => false]);
    }

    public function save(string $section)
    {
        $listing = service('businessContext')->listing();
        if (! config('ProfileSections')->has($section) || $this->movedToBookings($section, $listing) !== null) {
            return $this->response->setStatusCode(404);
        }
        if ($this->locked($section, $listing) !== null) {
            return $this->response->setStatusCode(403)->setBody(view('business/forbidden', [], ['saveData' => false]));
        }

        $post    = $this->request->getPost();
        $here    = base_url('business/profile/' . $section);
        $uploads = $section === 'photos';

        $logo = $uploads ? $this->resolveLogo() : ['path' => '', 'error' => null];
        if ($uploads) {
            $post['logo_path']  = $logo['path'];
            $post['_has_photo'] = $this->hasPhoto($logo['path'], $listing);
        }

        // Headshots are processed before the save, like the logo, because their
        // paths are part of the data being saved. Anything written here that the
        // save then rejects comes back as an orphan below.
        $headshots = ['team' => null, 'errors' => []];
        if ($section === 'team' && array_key_exists('team', $post)) {
            $headshots    = $this->resolveTeamPhotos($post['team']);
            $post['team'] = $headshots['team'];
        }

        $result = (new DirectoryListingMutationService())->updateOwnSection((int) $listing['id'], $section, $post);

        if (! $result['ok']) {
            $this->discardLogo($logo['path']);
            $this->discardTeamPhotos($headshots['team']);

            return $this->withUploadErrors(
                redirect()->to($here)
                    ->with('errors', $uploads ? $this->withFilesNote($result['errors']) : $result['errors'])
                    ->with('old', form_typed_fields($post))
                    ->with('error', $result['message']),
                array_filter(array_merge([$logo['error']], $headshots['errors']))
            );
        }

        $extra = [];
        if ($uploads) {
            $gallery = $this->resolveGalleryPhotos((int) $listing['id']);
            if ($gallery['photos'] !== []) {
                (new DirectoryListingPhotoModel())->appendPhotos((int) $listing['id'], $gallery['photos']);
            }
            $extra = array_merge($gallery['errors'], $this->applyMenuUpload((int) $listing['id']), $this->resolveBrands((int) $listing['id']));
        }

        // After the gallery: photos are appended outside the save's
        // transaction and score too.
        (new ListingQualityService())->recalculate((int) $listing['id']);

        return $this->withUploadErrors(
            redirect()->to($here)->with('success', 'Saved. ' . $result['message']),
            array_filter(array_merge([$logo['error']], $extra, $headshots['errors']))
        );
    }

    /**
     * The profile as this page holds it right now, saved or not, in a new
     * tab. Nothing is written: the same rules as the old Manage::preview(),
     * with the other pages' fields as stored. Exempt from CSRF (see
     * Config\Filters) and refused from another site.
     */
    public function preview(string $section)
    {
        $listing = service('businessContext')->listing();
        if (! config('ProfileSections')->has($section)) {
            return $this->response->setStatusCode(404);
        }
        if (! $this->sameSiteRequest()) {
            return $this->response->setStatusCode(403)->setBody('Open the preview from your profile page.');
        }

        $mutation = new DirectoryListingMutationService();
        $post     = array_intersect_key($this->request->getPost(), array_flip(config('ProfileSections')->fields($section)))
            + $mutation->storedFormInput($listing);
        $post['logo_path']  = '';
        $post['_has_photo'] = $this->hasPhoto('', $listing);

        $prepared = $mutation->prepareOwnEdit($listing, $post);
        $sections = config('ProfileSections');
        $errors   = array_filter($prepared['errors'], static fn (string $k): bool => $sections->ownsError($section, $k), ARRAY_FILTER_USE_KEY);
        $row      = array_merge($listing, $prepared['data']);
        $category = $row['category_id'] !== null ? (int) $row['category_id'] : null;

        $menu      = new ServiceMenuService();
        $overrides = [];
        if (array_key_exists(ServiceMenuService::SERVICES_MARKER, $post)) {
            $overrides['services'] = $menu->servicesFromInput($post['services'] ?? null);
        }
        if (array_key_exists(ServiceMenuService::ATTRIBUTES_MARKER, $post)) {
            $overrides['attributes'] = $menu->attributeLabels($menu->allowedKeys($category, $post['attributes'] ?? null));
        }
        if (array_key_exists(ListingFacetService::FACETS_MARKER, $post)) {
            $overrides['facets'] = (new ListingFacetService())->displayFromInput($category, $post['facets'] ?? null);
        }
        if ($section === 'details' && array_key_exists('specializations', $post)) {
            $tags = $mutation->tagNames($post['specializations']);
            sort($tags, SORT_NATURAL | SORT_FLAG_CASE);
            $overrides['tags'] = $tags;
        }

        helper('directory_hours');
        $profile = (new DirectoryService())->profileFor($row, $overrides);
        hours_context($profile);

        $this->privatePage();

        return view('directory/show', [
            'l'       => $profile,
            'related' => [],
            'preview' => ['mode' => 'draft', 'errors' => $errors],
        ]);
    }

    /** Remove one gallery photo of this business. The id is the visitor's, so it is checked. */
    public function deletePhoto(int $photoId)
    {
        $ajax    = $this->request->isAJAX();
        $listing = service('businessContext')->listing();
        $model   = new DirectoryListingPhotoModel();
        $photo   = $model->find($photoId);
        $back    = base_url('business/profile/photos');

        if (! is_array($photo) || (int) $photo['listing_id'] !== (int) $listing['id']) {
            return $ajax
                ? $this->photoDeleteJson(false, 'That photo could not be found.', 404)
                : redirect()->to($back)->with('error', 'That photo could not be found.');
        }

        $model->deleteWithFile($photo);
        // The only path that lowers a score without touching the profile row.
        (new ListingQualityService())->recalculate((int) $listing['id']);

        return $ajax
            ? $this->photoDeleteJson(true, 'Photo removed.', 200, (int) $listing['id'])
            : redirect()->to($back)->with('success', 'Photo removed.');
    }

    /** Remove one brand logo of this business. */
    public function deleteBrand(int $brandId)
    {
        $ajax    = $this->request->isAJAX();
        $listing = service('businessContext')->listing();
        $back    = base_url('business/profile/photos');

        if ((new ListingBrandService())->delete($brandId, (int) $listing['id']) === null) {
            return $ajax
                ? $this->brandDeleteJson(false, 'That brand could not be found.', 404)
                : redirect()->to($back)->with('error', 'That brand could not be found.');
        }

        return $ajax
            ? $this->brandDeleteJson(true, 'Brand removed.', 200, (int) $listing['id'])
            : redirect()->to($back)->with('success', 'Brand removed.');
    }

    /**
     * Where a page lives in Bookings while bookings are usable: the Team page
     * (every card, with hours and sign in), and Services once the catalogue
     * writes the profile's list (T28). Null to stay here.
     *
     * @param array<string,mixed> $listing
     */
    private function movedToBookings(string $section, array $listing): ?string
    {
        $setup    = new BookingSetupService();
        $bookable = $setup->isOn($listing) && $setup->whyNot($listing) === null;

        return match (true) {
            $bookable && $section === 'team'                                            => 'business/team',
            $bookable && $section === 'services' && CatalogueService::ownsProfileServices($listing) => 'business/services',
            default                                                                     => null,
        };
    }

    /**
     * The locked page for a Verified or Plus part on a Free profile, or null.
     *
     * @param array<string,mixed> $listing
     */
    private function locked(string $section, array $listing)
    {
        $config = config('ProfileSections');
        if (! $config->has($section)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        if (empty($config->sections[$section]['premium']) || (new TeamMemberService())->canManage($listing)) {
            return null;
        }
        $reason = site()->forRow($listing) === \Config\Sites::ZA
            ? 'This part of your profile comes with the Verified Business badge. Anything you added before is kept.'
            : 'This part of your profile comes with Plus. Anything you added before is kept.';

        return $this->response->setStatusCode(403)->setBody(view('business/locked', [
            'feature' => $section,
            'reason'  => $reason,
        ], ['saveData' => false]));
    }

    /**
     * What business/profile renders: the stored profile with whatever a
     * rejected save typed, as the old /manage/edit page built it.
     *
     * @param array<string,mixed> $listing
     *
     * @return array<string,mixed>
     */
    private function formData(string $section, array $listing): array
    {
        $id   = (int) $listing['id'];
        $svc  = new DirectoryService();
        $menu = new ServiceMenuService();

        return [
            // Tells layouts/business to load the form's script (directory.js).
            'profileForm' => true,
            'section'    => $section,
            'title'      => config('ProfileSections')->sections[$section]['title'],
            'listing'    => $listing,
            'old'        => session()->getFlashdata('old') ?? [],
            'errors'     => session()->getFlashdata('errors') ?? [],
            'categories' => $svc->categories(),
            'provinces'  => $svc->provinces(),
            'countries'  => config('Countries')->grouped(),
            'tags'       => $svc->tagsForListing($id),
            'services'   => $menu->servicesFor($id),
            'servicesFromBookings' => CatalogueService::ownsProfileServices($listing),
            'bookingPeople'        => TeamService::profileFormPeople($listing),
            'attributes' => $menu->attributeKeysFor($id),
            'facets'     => (new ListingFacetService())->storedFor($id),
            'photos'     => (new DirectoryListingPhotoModel())->forListing($id),
            'brands'     => (new ListingBrandService())->forListing($id),
            'menuFiles'  => (new ListingMenuService())->forListing($id),
            'slots'      => $this->gallerySlots($id),
            // Rows are read whatever the badge says: a lapsed subscriber keeps
            // their team and branches, they just stop rendering.
            'team'       => (new TeamMemberService())->forListing($id),
            'locations'  => (new PracticeLocationService())->forListing($id),
            'showExtras' => (new TeamMemberService())->canManage($listing),
        ];
    }

    /**
     * Whether the browser says this request came from our own pages. Browsers
     * without Sec-Fetch-Site still send Origin on a POST; a client sending
     * neither is not a browser a forged form could drive.
     */
    private function sameSiteRequest(): bool
    {
        $fetchSite = $this->request->getHeaderLine('Sec-Fetch-Site');
        if ($fetchSite !== '') {
            return in_array($fetchSite, ['same-origin', 'none'], true);
        }
        $origin = $this->request->getHeaderLine('Origin');
        if ($origin === '') {
            return true;
        }

        return strcasecmp((string) parse_url($origin, PHP_URL_HOST), $this->request->getUri()->getHost()) === 0;
    }
}
