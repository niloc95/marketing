<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => ($listing ? 'Edit' : 'New') . ' listing | Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$isNew = $listing === null;
$base  = $listing ?? [];
if (! $isNew) {
    $base['specializations'] = implode(', ', $tags);
    $base['video_url']       = \App\Services\ListingVideoService::formValue($base);
}
$v = function (string $f, string $default = '') use ($old, $base) {
    if (array_key_exists($f, $old)) return form_old_value($old[$f]);
    if (array_key_exists($f, $base) && $base[$f] !== null) return (string) $base[$f];
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';
$action = $isNew ? base_url('admin/new') : base_url('admin/edit/' . $listing['id']);
helper('directory_hours');
// Flashed "old" hours are already the raw hours[day][...] array shape from the
// failed submit; the stored value is JSON and needs decoding. Old wins.
// The hours section asks on this listing's own clock and calendar.
hours_context(array_merge($base, array_filter(['country' => $old['country'] ?? null])));
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : (hours_decode($base['trading_hours'] ?? null) ?? []);
// The public holiday answer travels with the days as hours[holidays], the
// shape the form posts; the stored copy is decoded separately.
if (! is_array($old['hours'] ?? null)) {
    $vHours['holidays'] = hours_holiday_rule($base['trading_hours'] ?? null);
}
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-white"><?= $isNew ? 'New profile' : 'Edit profile' ?></h1>
                <div class="flex flex-wrap gap-2">
                    <?php if (! $isNew): ?>
                        <?php // The saved profile, whatever its status. Save first to see an edit. ?>
                        <a class="btn btn-ghost btn-xs" href="<?= base_url('admin/preview/' . (int) $listing['id']) ?>" target="_blank" rel="noopener"><?= lucide('external-link', 'h-4 w-4 shrink-0') ?>Preview</a>
                    <?php endif; ?>
                    <a class="btn btn-ghost btn-xs" href="<?= base_url('admin') ?>"><?= lucide('arrow-left', 'h-4 w-4 shrink-0') ?>Back to profiles</a>
                </div>
            </div>
            <?php // Read-only: which channel brought this signup (Listing::store()).
                  // Blank for listings from before it was recorded. ?>
            <?php if (! $isNew && ! empty($listing['signup_source'])): ?>
                <p class="-mt-4 mb-6 text-xs text-slate-500 dark:text-slate-400">Signed up via: <strong><?= esc($listing['signup_source']) ?></strong></p>
            <?php endif; ?>

            <?php // The owner's profile strength meter, so an admin can see what is
                  // missing and pass it on. Outside the form: the email button is a
                  // form of its own, and forms cannot nest. ?>
            <?php if (! empty($stats)): ?>
                <?= view('directory/_stats_panel', ['stats' => $stats, 'audience' => 'admin']) ?>
            <?php endif; ?>

            <?php if (! $isNew && $strength !== null): ?>
                <?= view('directory/_strength_panel', [
                    'strength' => $strength,
                    'floor'    => $strengthFloor,
                    'target'   => $strengthTarget,
                    'audience' => 'admin',
                ]) ?>
                <?php
                $optedIn  = ! empty($listing['marketing_opt_in']);
                $hasEmail = trim((string) ($listing['email'] ?? '')) !== '';
                ?>
                <?php if ($strength['next'] !== []): ?>
                    <div class="panel -mt-2 mb-6 flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm text-slate-600 dark:text-slate-300">
                            <strong>Email suggestions to the owner.</strong>
                            Sends the top <?= \App\Services\ProfileNudgeService::STEPS ?> steps with a link straight into their manage page.
                            <?php if (! empty($listing['quality_nudge_sent_at'])): ?>
                                <br><span class="text-xs text-slate-500 dark:text-slate-400">Last emailed <?= esc(local_datetime($listing['quality_nudge_sent_at'])) ?>.</span>
                            <?php endif; ?>
                            <?php if (! $optedIn): ?>
                                <br><span class="text-xs text-slate-500 dark:text-slate-400">The owner has not opted in to profile emails, or has unsubscribed, so this cannot be sent. Phone them instead.</span>
                            <?php elseif (! $hasEmail): ?>
                                <br><span class="text-xs text-slate-500 dark:text-slate-400">No email address on this profile.</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= base_url('admin/edit/' . (int) $listing['id'] . '/nudge') ?>"
                              data-confirm="Email the owner the top steps to improve their profile?">
                            <?= csrf_field() ?>
                            <button class="btn btn-primary btn-xs" <?= $optedIn && $hasEmail ? '' : 'disabled' ?>>Email suggestions</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php // Verify this profile directly (Admin::grantVerification()), for a
                  // customer who emailed their documents in. Only for a Verified
                  // build. Outside the main form, like the nudge, because forms
                  // cannot nest. ?>
            <?php if (! $isNew && $badgeOffer && $adminPlan === 'verified'): ?>
                <?php
                $badgeRow   = $badge['verification'] ?? null;
                $badgeState = (string) ($badgeRow['state'] ?? '');
                $canGrant   = ! in_array($badgeState, [
                    \App\Models\DirectoryVerificationModel::STATE_ACTIVE,
                    \App\Models\DirectoryVerificationModel::STATE_SUBMITTED,
                ], true);
                ?>
                <?php $confirmed = ! empty($listing['is_verified']); ?>
                <details class="panel -mt-2 mb-6" id="badge" open>
                    <summary class="cursor-pointer text-sm text-slate-600 dark:text-slate-300">
                        <strong>Verified Business badge.</strong>
                        <?php if ($badgeState === ''): ?>
                            Not verified.
                        <?php elseif ($badgeState === \App\Models\DirectoryVerificationModel::STATE_ACTIVE): ?>
                            Active until <?= esc(date('j M Y', strtotime((string) $badgeRow['paid_until']))) ?>.
                        <?php elseif ($badgeState === \App\Models\DirectoryVerificationModel::STATE_APPROVED): ?>
                            Approved, waiting for the owner to pay.
                        <?php elseif ($badgeState === \App\Models\DirectoryVerificationModel::STATE_SUBMITTED): ?>
                            The owner applied: decide it in the queue.
                        <?php else: ?>
                            <?= esc(ucfirst($badgeState)) ?>.
                        <?php endif; ?>
                        <?php if ($badgeState !== ''): ?>
                            <a class="ml-1 underline" href="<?= base_url('admin/verifications?state=' . $badgeState) ?>">Verifications</a>
                        <?php endif; ?>
                    </summary>
                    <?php if ($canGrant): ?>
                        <form method="post" action="<?= base_url('admin/edit/' . (int) $listing['id'] . '/verify-badge') ?>"
                              enctype="multipart/form-data" class="mt-4 grid gap-3 text-sm">
                            <?= csrf_field() ?>
                            <p class="hint">Verify without an owner application: attach the documents the customer sent, or
                                write how you checked the business. The note is kept with the decision.</p>
                            <label class="grid gap-1">Company registration document
                                <input type="file" name="verify_doc_registration" accept=".pdf,application/pdf,image/jpeg,image/png">
                            </label>
                            <label class="grid gap-1">Owner's ID
                                <input type="file" name="verify_doc_id" accept=".pdf,application/pdf,image/jpeg,image/png">
                            </label>
                            <label class="grid gap-1">Note (required without both documents)
                                <textarea name="note" rows="2" maxlength="500" placeholder="e.g. Documents received by email, CIPC number checked"></textarea>
                            </label>
                            <?php // Usable once the customer has approved the preview (is_verified);
                                  // grantByAdmin() refuses before that either way. ?>
                            <div class="flex flex-wrap items-center gap-2">
                                <button class="btn btn-primary btn-xs" name="mode" value="owner_pays" <?= $confirmed ? '' : 'disabled' ?>>Approve, owner pays</button>
                                <span class="text-slate-400">or</span>
                                <?php // w-16, not an inline width: CSP enforces style-src-attr. ?>
                                <input type="number" name="months" value="1" min="1" max="24" class="text-xs w-16" title="Months" <?= $confirmed ? '' : 'disabled' ?>>
                                <button class="btn btn-ghost btn-xs" name="mode" value="activate" <?= $confirmed ? '' : 'disabled' ?>
                                        data-confirm="Activate the badge now without PayFast? Only once payment has arrived, or if it is given free.">Activate now (EFT or free)</button>
                            </div>
                            <?php if (! $confirmed): ?>
                                <p class="hint">Waiting for the customer to approve the preview. Send it, then come back here once they approve.</p>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </details>
            <?php endif; ?>

            <?php // Unsaved-draft backup — see manage_edit.php. ?>
            <?= view('directory/_error_summary', ['errors' => $errors ?? []]) ?>
            <form method="post" action="<?= esc($action) ?>" enctype="multipart/form-data"
                  data-draft="<?= $isNew ? 'admin-new' : 'admin-' . (int) $listing['id'] ?>"
                  data-draft-version="<?= esc((string) ($base['updated_at'] ?? ''), 'attr') ?>">
                <?= csrf_field() ?>

                <?php // Free or Verified, for a profile built for a customer
                      // (DirectoryAdminService::asBuilt()). Verified opens team,
                      // branches and video here and in the preview; the public page
                      // shows them once the badge is live. directory.js shows and
                      // hides [data-admin-plan-extras] as it changes. ?>
                <?php if ($badgeOffer): ?>
                    <?php $planNow = in_array($v('admin_plan'), ['free', 'verified'], true) && array_key_exists('admin_plan', $old) ? $v('admin_plan') : $adminPlan; ?>
                    <fieldset class="panel mb-6" data-admin-plan>
                        <legend class="text-sm font-semibold text-slate-900 dark:text-white">Profile type</legend>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm">
                            <label class="attr-option"><input type="radio" name="admin_plan" value="free" <?= $planNow === 'free' ? 'checked' : '' ?>> Free</label>
                            <label class="attr-option"><input type="radio" name="admin_plan" value="verified" <?= $planNow === 'verified' ? 'checked' : '' ?>> Verified (team, branches and video)</label>
                        </div>
                        <p class="hint mt-1">Verified opens the paid sections for the build and the preview. They show publicly once the badge is active: after the customer approves the preview, use the badge panel to approve (owner pays) or activate.</p>
                    </fieldset>
                <?php endif; ?>

                <?= view('directory/_form_fields', [
                    'v'            => $v,
                    'err'          => $err,
                    'categories'   => $categories,
                    'provinces'    => $provinces,
                    'lockEmail'    => false,
                    // Explicitly false: admin intake covers imports and phone
                    // captures where the address genuinely is not known yet.
                    'addressRequired' => false,
                    // False for the same reason, and DirectoryAdminService does
                    // not apply the rule either: an imported row often has no
                    // named contact until somebody phones it.
                    'privateDetailsRequired' => false,
                    'countries'   => $countries,
                    // Admin is the only place a country can actually change.
                    'lockCountry' => false,
                    'vHours'       => $vHours,
                    'existingLogo' => (string) ($base['logo_path'] ?? ''),
                    'gallerySlots' => $slots,
                    'vTeam'        => is_array($old['team'] ?? null) ? $old['team'] : $team,
                    'vLocations'   => is_array($old['locations'] ?? null) ? $old['locations'] : $locations,
                    // Marker, not the array — see manage_edit.php.
                    'vServices'    => array_key_exists('services_present', $old) ? (is_array($old['services'] ?? null) ? $old['services'] : []) : $services,
                    'vAttributes'  => array_key_exists('attributes_present', $old) ? (is_array($old['attributes'] ?? null) ? $old['attributes'] : []) : $attributes,
                    'vFacets'      => array_key_exists('facets_present', $old)
                        ? (new App\Services\ListingFacetService())->groupedFromInput(
                            ((int) ($old['category_id'] ?? $listing['category_id'] ?? 0)) ?: null,
                            $old['facets'] ?? null,
                        )
                        : $facets,
                    // Child rows are saved after the insert, so a new Verified
                    // build can carry them from its first save.
                    'showExtras'   => $showExtras,
                    'extrasHidden' => ! $extrasOpen && ($planNow ?? 'free') !== 'verified',
                    'photos'       => $photos,
                    'deleteBase'   => base_url('admin/photo-delete'),
                    'brands'       => $brands ?? [],
                    'brandDeleteBase' => base_url('admin/brand-delete'),
                    'menuFiles'    => $menuFiles ?? [],
                ]) ?>

                <?php // Privileged fields — deliberately not in the shared partial, so the
                      // owner form cannot render them even by accident. ?>
                <div class="panel mt-2 bg-slate-50 dark:bg-slate-800/60">
                    <h3>Admin controls</h3>
                    <div class="form-row">
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <?php foreach (['pending', 'published', 'unpublished', 'rejected'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $v('status', 'pending') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>URL slug</label>
                            <input type="text" name="slug" value="<?= esc($v('slug'), 'attr') ?>" placeholder="auto from the name">
                            <div class="hint">Changing this breaks existing links to the profile.</div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="font-medium"><input type="checkbox" name="is_featured" value="1" <?= $v('is_featured') ? 'checked' : '' ?>> Featured</label>
                    </div>
                    <?php // Read only: the owner's switch. Published reviews stay up
                          // either way; take one down at /admin/reviews. ?>
                    <?php if (! $isNew && ! \App\Services\ReviewService::acceptsReviews($listing)): ?>
                        <p class="hint">New reviews: off (switched off by the owner). Reviews already published stay on the profile.</p>
                    <?php endif; ?>
                    <?php // Admin only: lets the profile say "checked" instead of
                          // "self declared". Only offered beside a stored number;
                          // the owner changing the number clears it. ?>
                    <?php if (! $isNew && ! empty($listing['reg_number'])): ?>
                        <div class="field">
                            <input type="hidden" name="reg_checked_present" value="1">
                            <label class="font-medium"><input type="checkbox" name="reg_checked" value="1" <?= ! empty($listing['reg_checked_at']) ? 'checked' : '' ?>> Registration number <?= esc($listing['reg_number']) ?> checked against the register</label>
                            <?php if (! empty($listing['reg_checked_at'])): ?><div class="hint">Checked <?= esc(date('j F Y', strtotime((string) $listing['reg_checked_at']))) ?>.</div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php // Admin-only, deliberately: a venue is a claim about a
                          // shared building, so it is not on the owner form and not
                          // in OWNER_EDITABLE. Manage the list at /admin/venues. ?>
                    <div class="field">
                        <label>Venue <span class="text-xs font-normal text-slate-400">complex, mall or building</span></label>
                        <select name="venue_id">
                            <option value="">None</option>
                            <?php foreach ($venues as $ven): ?>
                                <option value="<?= (int) $ven['id'] ?>" <?= (string) $v('venue_id') === (string) $ven['id'] ? 'selected' : '' ?>>
                                    <?= esc($ven['name']) ?><?= $ven['city'] ? ', ' . esc($ven['city']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (! $isNew && ($listing['type'] ?? '') === 'place'): ?>
                            <?php $ownVenue = null;
                            foreach ($venues as $ven) {
                                if ((int) ($ven['listing_id'] ?? 0) === (int) $listing['id']) {
                                    $ownVenue = $ven;
                                }
                            } ?>
                            <?php if ($ownVenue !== null): ?>
                                <div class="hint">This place is the venue <a href="<?= esc(base_url('directory/at/' . $ownVenue['slug'])) ?>" target="_blank"><?= esc($ownVenue['name']) ?></a>. Set the businesses inside it to this venue and they show on its profile.</div>
                            <?php else: ?>
                                <?php // formaction: the button sits inside the edit form, which
                                      // cannot hold a second form. Unsaved edits are not kept. ?>
                                <button class="btn btn-ghost btn-xs mt-2" formaction="<?= base_url('admin/edit/' . (int) $listing['id'] . '/make-venue') ?>" formnovalidate>Create venue from this place</button>
                                <div class="hint">Save your edits first. Copies the name, address and pin.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label class="font-medium"><input type="checkbox" name="is_verified" value="1" <?= $v('is_verified') ? 'checked' : '' ?>> Email verified</label>
                    </div>
                    <?php if (! $isNew): ?>
                        <?php // Read-only on purpose. The choice has to come from the owner —
                              // an admin can see it, never set it. See MarketingConsentService. ?>
                        <?php
                        $fmt = static fn ($d) => $d ? date('j M Y', strtotime((string) $d)) : '';
                        $src = static fn () => ! empty($listing['marketing_consent_source']) ? ' (' . $listing['marketing_consent_source'] . ')' : '';
                        ?>
                        <div class="field">
                            <label>Consent record</label>
                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                Terms: <?= $listing['terms_accepted_at'] ? 'accepted ' . esc($fmt($listing['terms_accepted_at'])) . ' (version ' . esc((string) $listing['terms_version']) . ')' : 'no record, listed before consent was stored' ?><br>
                                Analytics report:
                                <?php if (! empty($listing['marketing_opt_in'])): ?>
                                    on <?= esc($fmt($listing['marketing_consent_at']) . $src()) ?>
                                <?php elseif (! empty($listing['marketing_withdrawn_at'])): ?>
                                    switched off <?= esc($fmt($listing['marketing_withdrawn_at']) . $src()) ?>
                                <?php else: ?>
                                    off
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block mt-4"><?= $isNew ? 'Create profile' : 'Save changes' ?></button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= view('directory/_editor_assets') ?>
<?= $this->endSection() ?>
