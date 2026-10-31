<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'  => 'Edit your profile | ' . config('Directory')->siteName(),
    'robots' => 'noindex, nofollow',
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
// The shared partial reads "specializations" via $v(); seed it from the stored
// tag names so the field arrives prefilled. Must happen before $v is bound.
$listing['specializations'] = implode(', ', $tags);
// The video field shows the link as a clean page address, rebuilt from the
// parsed columns: there is no video_url column to read it from.
$listing['video_url'] = \App\Services\ListingVideoService::formValue($listing);

// Flashed input wins (so a failed save keeps what was typed), then the stored
// listing, then the default.
$v = function (string $f, string $default = '') use ($old, $listing) {
    if (array_key_exists($f, $old)) return form_old_value($old[$f]);
    if (array_key_exists($f, $listing) && $listing[$f] !== null) return (string) $listing[$f];
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';

helper('directory_hours');
// The hours section asks on this listing's own clock and calendar.
hours_context($listing);
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : (hours_decode($listing['trading_hours'] ?? null) ?? []);
// The public holiday answer travels with the days as hours[holidays], the
// shape the form posts; the stored copy is decoded separately.
if (! is_array($old['hours'] ?? null)) {
    $vHours['holidays'] = hours_holiday_rule($listing['trading_hours'] ?? null);
}

// Same precedence as every other field: what was typed into a rejected save
// wins over what is stored, so nobody retypes a team after one bad row.
$vTeam      = is_array($old['team'] ?? null) ? $old['team'] : $team;
$vLocations = is_array($old['locations'] ?? null) ? $old['locations'] : $locations;

// Keyed off the section marker, not the array: a rejected save with every box
// unticked sends no attributes[] at all, and that must not fall back to the
// stored set the owner was trying to clear.
$vServices   = array_key_exists('services_present', $old) ? (is_array($old['services'] ?? null) ? $old['services'] : []) : $services;
$vAttributes = array_key_exists('attributes_present', $old) ? (is_array($old['attributes'] ?? null) ? $old['attributes'] : []) : $attributes;
// Same marker rule again. Old input is re-filtered on the way back, against the
// category the rejected save was *asking for* rather than the stored one, so a
// save that changed the category redraws the new category's questions.
$vFacets = array_key_exists('facets_present', $old)
    ? (new App\Services\ListingFacetService())->groupedFromInput(
        ((int) ($old['category_id'] ?? $listing['category_id'] ?? 0)) ?: null,
        $old['facets'] ?? null,
    )
    : $facets;
// Same reason: a rejected save with the box unticked must not fall back to a
// stored opt-in. Signup uses the same marker, but falls back to ticked — there
// is no stored value there, and the report is on by default.
$vMarketing  = array_key_exists('marketing_present', $old) ? ! empty($old['marketing_opt_in']) : ! empty($listing['marketing_opt_in']);
?>
<section class="section">
    <div class="container">
        <div class="form-card">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <span class="eyebrow">Manage your profile</span>
                    <h1 class="mb-1.5 mt-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?= esc($listing['display_name']) ?></h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Status:
                        <span class="pill pill-<?= esc($listing['status'], 'attr') ?>"><?= esc($listing['status']) ?></span>
                        <?php if ($listing['status'] === 'published'): ?>
                            &middot; <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $listing['slug'])) ?>" target="_blank">View public page</a>
                        <?php endif; ?>
                    </p>
                    <?php if ($listing['status'] === 'published' && listing_website_url($listing['website'] ?? '') === ''): ?>
                        <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                            No website? Use <strong class="text-slate-700 dark:text-slate-200"><?= esc(preg_replace('#^https?://#i', '', base_url('directory/' . $listing['slug']))) ?></strong>
                            wherever a form asks for one: Google Business Profile, Facebook, your email signature, business cards.
                        </p>
                    <?php endif; ?>
                </div>
                <?php // data-draft-signout: signing out also drops this browser's
                      // unsaved-draft copy, so the details don't linger on a shared PC. ?>
                <a class="btn btn-ghost btn-xs" href="<?= base_url('manage/signout') ?>" data-draft-signout>Sign out</a>
            </div>

            <?php // Outside the listing form: these panels post their own forms.
                  //
                  // Hosting first, deliberately. When it is unpaid the listing is
                  // not public at all, which outranks anything the badge panel
                  // below has to say. ?>
            <?php if ($hostingRequired): ?>
                <?= view('directory/_hosting_panel', [
                    'listing'      => $listing,
                    'subscription' => $hosting,
                    'amount'       => $hostingAmount,
                    'payable'      => $verificationPayable,
                    'pending'      => $hostingPending,
                ]) ?>
            <?php endif; ?>

            <?php if (! empty($plusShown)): ?>
                <?= view('directory/_plus_panel', [
                    'listing'      => $listing,
                    'subscription' => $hosting,
                    'amount'       => $hostingAmount,
                    'usd'          => $plusUsd ?? null,
                    'payable'      => $verificationPayable,
                    'pending'      => $hostingPending,
                ]) ?>
            <?php endif; ?>

            <?php if ($verificationOffered): ?>
                <?= view('directory/_verification_panel', [
                    'verification' => $verification,
                    'amount'       => $verificationAmount,
                    'payable'      => $verificationPayable,
                    'pending'      => $verificationPending,
                ]) ?>
            <?php endif; ?>

            <?php // Last of the three panels, and outside the form like the other
                  // two. It is the only one that is always relevant, so it sits
                  // closest to the fields it is talking about — and below the
                  // two that can be telling the owner their listing is offline,
                  // which outranks anything about completeness. ?>
            <?php // Before the strength panel: the numbers say what is wrong, the
                  // strength panel says what is left to fill in. ?>
            <?php if (! empty($stats)): ?>
                <?= view('directory/_stats_panel', [
                    'stats'       => $stats,
                    'upgradeUrl'  => $stats['full'] ? '' : (! empty($plusShown) ? base_url('plus') : (! $hostingRequired && $verificationOffered ? base_url('verified') : '')),
                    'upgradeName' => ! empty($plusShown) ? 'Plus' : 'Verified',
                ]) ?>
            <?php endif; ?>

            <?= view('directory/_strength_panel', [
                'strength' => $strength,
                'floor'    => $strengthFloor,
                'target'   => $strengthTarget ?? 0,
            ]) ?>

            <?= view('directory/_profile_setup_offer') ?>

            <?php // data-draft: directory.js keeps a browser-side copy of unsaved edits
                  // and puts them back after any reload. The version is what tells a
                  // draft apart from one made before the listing was saved elsewhere. ?>
            <?= view('directory/_error_summary', ['errors' => $errors ?? []]) ?>
            <form method="post" action="<?= base_url('manage/edit') ?>" enctype="multipart/form-data"
                  data-draft="manage-<?= (int) $listing['id'] ?>"
                  data-draft-version="<?= esc((string) ($listing['updated_at'] ?? ''), 'attr') ?>">
                <?= csrf_field() ?>

                <?= view('directory/_form_fields', [
                    'v'            => $v,
                    'err'          => $err,
                    'categories'   => $categories,
                    'provinces'    => $provinces,
                    'lockEmail'    => true,
                    // Explicitly false: an owner whose listing predates the
                    // address rule must still be able to save other edits.
                    'addressRequired' => false,
                    // True, unlike the address above: the owner's own name and
                    // title are always to hand, and this form is where the
                    // listings that predate the rule get them filled in.
                    'privateDetailsRequired' => true,
                    'countries'   => $countries,
                    // Read-only here: country decides whether this listing
                    // needs an International Listing subscription, so the
                    // person being charged does not get to set it. updateOwn()
                    // ignores a posted country outright — this only stops the
                    // form offering a control that would silently do nothing.
                    'lockCountry' => true,
                    'vHours'       => $vHours,
                    'existingLogo' => (string) ($listing['logo_path'] ?? ''),
                    'gallerySlots' => $slots,
                    'vTeam'        => $vTeam,
                    'vLocations'   => $vLocations,
                    'vServices'    => $vServices,
                    'vAttributes'  => $vAttributes,
                    'vFacets'      => $vFacets,
                    'photos'       => $photos,
                    'deleteBase'   => base_url('manage/photo-delete'),
                    'brands'       => $brands ?? [],
                    'brandDeleteBase' => base_url('manage/brand-delete'),
                    'menuFiles'    => $menuFiles ?? [],
                    'profileUrl'   => base_url('directory/' . $listing['slug']),
                    'showExtras'   => $showExtras,
                ]) ?>

                <?php // The marker is what lets updateOwn() read an unticked box
                      // as "opt out" — an unticked checkbox posts nothing at all. ?>
                <div class="field">
                    <label>Email preferences</label>
                    <input type="hidden" name="marketing_present" value="1">
                    <label><input type="checkbox" name="marketing_opt_in" value="1" <?= $vMarketing ? 'checked' : '' ?>> We use your email to send you monthly analytics for your profile: how many views it got and where your leads came from.</label>
                    <div class="hint">Optional. Emails about your profile itself (edit links and any badge billing) still arrive either way.</div>
                </div>

                <?php // The step tracker, in a sticky bar of its own: this page has no
                      // live score bar for it to ride in (that is /add-profile's).
                      // Save rides along too, so an owner who fixed one thing
                      // halfway down need not scroll to the end. Hidden until
                      // directory.js runs, like the score bar; the button at the
                      // end is the one that always works. ?>
                <?php // Preview changes posts this same form to manage/preview in a new
                      // tab, which renders it and saves nothing (Manage::preview()).
                      // formnovalidate: a half finished form is still worth seeing,
                      // and the preview lists what Save would refuse. ?>
                <div class="form-steps-dock" data-form-steps-dock hidden>
                    <?= view('directory/_form_steps_nav', [], ['saveData' => false]) ?>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="submit" class="btn btn-ghost btn-xs" formaction="<?= base_url('manage/preview') ?>" formtarget="_blank" formnovalidate data-preview-submit>Preview</button>
                        <button type="submit" class="btn btn-accent btn-xs">Save changes</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-accent btn-block">Save changes</button>
                <button type="submit" class="btn btn-ghost btn-block mt-2" formaction="<?= base_url('manage/preview') ?>" formtarget="_blank" formnovalidate data-preview-submit>Preview changes</button>
                <?php if (($listing['status'] ?? '') === 'published'): ?>
                    <p class="mt-2 text-center text-sm"><a class="underline" href="<?= esc(base_url('directory/' . $listing['slug']), 'attr') ?>" target="_blank" rel="noopener">View my live profile</a></p>
                <?php endif; ?>
            </form>

            <?= view('directory/_jobs_panel', [
                'listing' => $listing,
                'posts'   => $jobPosts ?? [],
                'svc'     => new App\Services\JobBoardService(),
            ], ['saveData' => false]) ?>

            <?= view('directory/_reviews_panel', [
                'listing' => $listing,
                'reviews' => $reviews ?? [],
            ], ['saveData' => false]) ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= view('directory/_editor_assets') ?>
<?= $this->endSection() ?>
