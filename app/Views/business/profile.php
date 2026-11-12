<?php
/**
 * The business profile (Business\Profile, Config\ProfileSections): every
 * section drawn on this one page as a pane with its own form, the tabs (and
 * the sidebar's items) switching between them in place, with no page load
 * (directory.js, "profile panes"; owner, 12 Nov 2026). Each form posts to its
 * own /business/profile/{section} and saves only that section. Without the
 * script the tabs are links to the same page with another section open.
 * Was /manage/edit, one long page (owner, 10 Nov 2026).
 *
 * @var string              $section    the open section: details, where, services, photos, team, branches
 * @var list<array{key:string,href:string,label:string,active:bool,locked:bool,inline:bool}> $tabs
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
// Sections drawn here; Team and Services may be the bookings pages and the
// Verified or Plus ones locked, which stay links.
$panes = array_values(array_filter($tabs, static fn (array $t): bool => $t['inline']));
?>
<?= $this->extend('layouts/business') ?>

<?= $this->section('content') ?>
<div class="shell-page" data-panes>
    <?= view('layouts/_profile_tabs', ['tabs' => $tabs, 'label' => 'Profile sections'], ['saveData' => false]) ?>

    <?php // Form on the left, Save and strength in a sticky column on the right
          // (desktop); on a phone the column follows the form and Save sits in
          // the bar at the foot of the screen. ?>
    <div class="profile-editor">
    <div class="profile-editor-main">
    <?php foreach ($panes as $pane): ?>
        <?php
        $key    = $pane['key'];
        $open   = $key === $section;
        $action = 'business/profile/' . $key;
        ?>
        <div data-pane="<?= esc($key, 'attr') ?>" data-pane-title="<?= esc($pane['label'], 'attr') ?>" data-pane-href="<?= esc($pane['href'], 'attr') ?>"
             data-pane-save="Save <?= esc(strtolower($pane['label']), 'attr') ?>" data-pane-preview="<?= esc(base_url($action . '/preview'), 'attr') ?>"<?= $open ? '' : ' hidden' ?>>
            <?php // A failed save comes back to its own section, with its messages. ?>
            <?php if ($open): ?>
                <?= view('directory/_error_summary', ['errors' => $errors ?? []]) ?>
            <?php endif ?>
            <?php // data-draft: directory.js keeps a browser side copy of unsaved edits per section. ?>
            <form method="post" action="<?= base_url($action) ?>" enctype="multipart/form-data" id="profile-form-<?= esc($key, 'attr') ?>" class="panel profile-editor-form"
                  data-draft="business-<?= (int) $listing['id'] ?>-<?= esc($key, 'attr') ?>"
                  data-draft-version="<?= esc((string) ($listing['updated_at'] ?? ''), 'attr') ?>">
                <?= csrf_field() ?>

                <?= view('directory/_form_fields', [
                    'section'      => $key,
                    'v'            => $v,
                    'err'          => $open ? $err : static fn (string $f): string => '',
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

                <?php if ($key === 'details'): ?>
                    <?php // The marker is what lets the save read an unticked box as "opt out". ?>
                    <div class="field">
                        <label>Email preferences</label>
                        <input type="hidden" name="marketing_present" value="1">
                        <label><input type="checkbox" name="marketing_opt_in" value="1" <?= $vMarketing ? 'checked' : '' ?>> We use your email to send you monthly analytics for your profile: how many views it got and where your leads came from.</label>
                        <div class="hint">Optional. Emails about your profile itself (sign in links and any badge billing) still arrive either way.</div>
                    </div>
                <?php endif ?>

                <?php // Phone and tablet: the save bar. Desktop: the same two buttons in the side column. ?>
                <div class="form-savebar">
                    <?php // Preview posts this section to a new tab, which renders it and saves nothing. ?>
                    <button type="submit" class="btn btn-ghost" formaction="<?= base_url($action . '/preview') ?>" formtarget="_blank" formnovalidate data-preview-submit>Preview</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    <?php endforeach ?>
    </div>

    <aside class="profile-editor-side" aria-label="Save and profile strength">
        <div class="panel hidden xl:block">
            <?php // directory.js points these at the open section's form as the tabs switch. ?>
            <div class="profile-editor-actions">
                <button type="submit" form="profile-form-<?= esc($section, 'attr') ?>" class="btn btn-primary" data-pane-save-button>Save <?= esc(strtolower($title)) ?></button>
                <button type="submit" form="profile-form-<?= esc($section, 'attr') ?>" class="btn btn-ghost" formaction="<?= base_url('business/profile/' . $section . '/preview') ?>" formtarget="_blank" formnovalidate data-preview-submit data-pane-preview-button>Preview</button>
            </div>
            <p class="hint mb-0 mt-3">Saves this section only. The other sections keep what you saved before.</p>
        </div>
        <?php if (! empty($strength)): ?>
            <?= view('directory/_strength_panel', [
                'strength'   => $strength,
                'floor'      => (int) config('Directory')->recentMinQuality,
                'target'     => (int) config('Directory')->qualityTarget,
                'anchorBase' => 'business/profile',
            ], ['saveData' => false]) ?>
        <?php endif ?>
    </aside>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?><?= esc($title) ?><?= $this->endSection() ?>

<?= $this->section('shell_actions') ?>
<?php if ($live): ?>
    <a class="btn btn-ghost btn-xs" href="<?= esc(listing_url($listing), 'attr') ?>" target="_blank" rel="noopener">View profile</a>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= view('directory/_editor_assets') ?>
<?= $this->endSection() ?>

<?php // Loads directory.js (layouts/business.php). ?>
<?= $this->section('profile_form') ?>1<?= $this->endSection() ?>
