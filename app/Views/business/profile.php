<?php
/**
 * One page of the business profile (Business\Profile, Config\ProfileSections):
 * the shared form (directory/_form_fields) drawn for one section, with Save
 * and Preview. Was /manage/edit, one long page (owner, 10 Nov 2026).
 *
 * @var string              $section    details, where, services, photos, team, branches
 * @var string              $title
 * @var array<string,mixed> $listing
 * @var array<string,mixed> $old        what a rejected save typed
 * @var array<string,string> $errors
 */
// The shared partial reads "specializations" via $v(); seed it from the stored
// tag names so the field arrives prefilled. Must happen before $v is bound.
$listing['specializations'] = implode(', ', $tags);
// The video field shows the link as a clean page address, rebuilt from the
// parsed columns: there is no video_url column to read it from.
$listing['video_url'] = \App\Services\ListingVideoService::formValue($listing);

// Flashed input wins (so a failed save keeps what was typed), then the stored
// profile, then the default.
$v = function (string $f, string $default = '') use ($old, $listing) {
    if (array_key_exists($f, $old)) {
        return form_old_value($old[$f]);
    }
    if (array_key_exists($f, $listing) && $listing[$f] !== null) {
        return (string) $listing[$f];
    }

    return $default;
};
$err = static fn (string $f): string => $errors[$f] ?? '';

helper('directory_hours');
// The hours section asks on this profile's own clock and calendar.
hours_context($listing);
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : (hours_decode($listing['trading_hours'] ?? null) ?? []);
if (! is_array($old['hours'] ?? null)) {
    $vHours['holidays'] = hours_holiday_rule($listing['trading_hours'] ?? null);
}

$vTeam      = is_array($old['team'] ?? null) ? $old['team'] : $team;
$vLocations = is_array($old['locations'] ?? null) ? $old['locations'] : $locations;

// Keyed off the section marker, not the array: a rejected save with every box
// unticked sends no attributes[] at all, and that must not fall back to the
// stored set the owner was trying to clear.
$vServices   = array_key_exists('services_present', $old) ? (is_array($old['services'] ?? null) ? $old['services'] : []) : $services;
$vAttributes = array_key_exists('attributes_present', $old) ? (is_array($old['attributes'] ?? null) ? $old['attributes'] : []) : $attributes;
$vFacets     = array_key_exists('facets_present', $old)
    ? (new App\Services\ListingFacetService())->groupedFromInput(
        ((int) ($old['category_id'] ?? $listing['category_id'] ?? 0)) ?: null,
        $old['facets'] ?? null,
    )
    : $facets;
$vMarketing = array_key_exists('marketing_present', $old) ? ! empty($old['marketing_opt_in']) : ! empty($listing['marketing_opt_in']);
$live       = ($listing['status'] ?? '') === 'published';
$base       = 'business/profile/' . $section;
?>
<?= $this->extend('layouts/business') ?>

<?= $this->section('content') ?>
<div class="mx-auto w-full max-w-3xl">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-slate-900 dark:text-white"><?= esc($title) ?></h1>
        <?php if ($live): ?>
            <a class="btn btn-ghost btn-xs" href="<?= esc(listing_url($listing), 'attr') ?>" target="_blank" rel="noopener">View profile</a>
        <?php endif ?>
    </div>

    <?= view('directory/_error_summary', ['errors' => $errors ?? []]) ?>
    <?php // data-draft: directory.js keeps a browser side copy of unsaved edits per page. ?>
    <form method="post" action="<?= base_url($base) ?>" enctype="multipart/form-data" class="form-card"
          data-draft="business-<?= (int) $listing['id'] ?>-<?= esc($section, 'attr') ?>"
          data-draft-version="<?= esc((string) ($listing['updated_at'] ?? ''), 'attr') ?>">
        <?= csrf_field() ?>

        <?= view('directory/_form_fields', [
            'section'      => $section,
            'v'            => $v,
            'err'          => $err,
            'categories'   => $categories,
            'provinces'    => $provinces,
            'lockEmail'    => true,
            // An owner whose profile predates the address rule must still save.
            'addressRequired' => false,
            'privateDetailsRequired' => true,
            'countries'    => $countries,
            // The country decides the site and the subscription: never the owner's to set.
            'lockCountry'  => true,
            'vHours'       => $vHours,
            'existingLogo' => (string) ($listing['logo_path'] ?? ''),
            'gallerySlots' => $slots,
            'vTeam'        => $vTeam,
            'vLocations'   => $vLocations,
            'vServices'    => $vServices,
            'servicesFromBookings' => $servicesFromBookings ?? false,
            'bookingPeople' => $bookingPeople ?? null,
            'vAttributes'  => $vAttributes,
            'vFacets'      => $vFacets,
            'photos'       => $photos,
            'deleteBase'   => base_url('business/profile/photo-delete'),
            'brands'       => $brands ?? [],
            'brandDeleteBase' => base_url('business/profile/brand-delete'),
            'menuFiles'    => $menuFiles ?? [],
            'profileUrl'   => listing_url($listing),
            'showExtras'   => $showExtras,
            // Not kept for the next view: $section would draw the next form as one page.
        ], ['saveData' => false]) ?>

        <?php if ($section === 'details'): ?>
            <?php // The marker is what lets the save read an unticked box as "opt out". ?>
            <div class="field">
                <label>Email preferences</label>
                <input type="hidden" name="marketing_present" value="1">
                <label><input type="checkbox" name="marketing_opt_in" value="1" <?= $vMarketing ? 'checked' : '' ?>> We use your email to send you monthly analytics for your profile: how many views it got and where your leads came from.</label>
                <div class="hint">Optional. Emails about your profile itself (sign in links and any badge billing) still arrive either way.</div>
            </div>
        <?php endif ?>

        <div class="mt-6 flex flex-wrap gap-2">
            <button type="submit" class="btn btn-accent">Save</button>
            <?php // Preview posts this page to a new tab, which renders it and saves nothing. ?>
            <button type="submit" class="btn btn-ghost" formaction="<?= base_url($base . '/preview') ?>" formtarget="_blank" formnovalidate data-preview-submit>Preview</button>
        </div>
    </form>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= view('directory/_editor_assets') ?>
<?= $this->endSection() ?>
