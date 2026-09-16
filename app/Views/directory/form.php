<?= $this->extend('layouts/public') ?>

<?php
$siteName = config('Directory')->siteName();

// Which of the two comparison cards brought them here. Presentation only —
// both paths post to the same endpoint and save the same listing.
$plan       = ($plan ?? 'free') === 'verified' ? 'verified' : 'free';
$isVerified = $plan === 'verified';

// Written once, rendered in whichever of the two headings this page ends up with:
// above the comparison cards normally, or on the form card itself when the badge
// is switched off and there are no cards.
$eyebrow  = 'List your business &mdash; free';
$headline = 'Get found by new customers';

// Both pages canonicalise to /add-listing. They are one form showing the same
// fields, so the verified variant is not a separate thing to index — and the
// free one is the page we want people landing on from search.
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'List your business — ' . $siteName,
    'description' => 'List your business on ' . $siteName . ' so new customers can find you. Free, always.',
    'canonical'   => base_url('add-listing'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$v = function (string $f, string $default = '') use ($old) {
    if (array_key_exists($f, $old)) return form_old_value($old[$f]);
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : [];
?>
<section class="section">
    <div class="container">
        <?php // The comparison comes first. The two options used to meet only inside a
              // collapsed <details> further down, which is why businesses kept arriving
              // unsure what the difference was. Renders nothing when the badge is off. ?>
        <?php if ($verificationOffered): ?>
            <div class="plan-choose">
                <span class="eyebrow"><?= $eyebrow ?></span>
                <h1 class="plan-choose-title"><?= $headline ?></h1>
                <?= view('directory/_plan_cards', [
                    'amount'   => $verificationAmount,
                    'offered'  => $verificationOffered,
                    'selected' => $plan,
                ]) ?>
            </div>
        <?php endif; ?>

        <div class="form-card" id="listing-form">
            <?php if (! $verificationOffered): ?>
                <?php // No cards above, so the page still needs its own heading. ?>
                <span class="eyebrow"><?= $eyebrow ?></span>
                <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white"><?= $headline ?></h1>
            <?php else: ?>
                <?php // The <h1> is up with the cards in this case, so this is an <h2>. ?>
                <h2 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Your business details</h2>
            <?php endif; ?>
            <?php if ($isVerified): ?>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                    Tell us about your business, then attach your two documents at the bottom.
                    We'll email you a link to verify and publish your profile &mdash; and your
                    listing goes live either way, whatever the badge review decides.
                </p>
            <?php else: ?>
                <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">Tell us about your business. We'll email you a link to verify and publish your profile &mdash; it's free.</p>
            <?php endif; ?>

            <form method="post" action="<?= base_url('add-listing') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <!-- honeypot -->
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

                <?php // view() (not $this->include) — the partial needs $v/$err, which are
                      // locals here and so are not in the view's shared data. ?>
                <?= view('directory/_form_fields', [
                    'v'           => $v,
                    'err'         => $err,
                    'categories'  => $categories,
                    'provinces'   => $provinces,
                    'showConsent' => true,
                    'vHours'      => $vHours,
                    'vServices'   => is_array($old['services'] ?? null) ? $old['services'] : [],
                    'vAttributes' => is_array($old['attributes'] ?? null) ? $old['attributes'] : [],
                ]) ?>

                <?php if (! empty($verificationOffered)): ?>
                    <?php // ONE block in two states, not two blocks. The only difference
                          // between the free and verified paths is whether this <details>
                          // arrives open, which is what lets the plan picker switch between
                          // them in place instead of loading a second page — and what keeps
                          // the no-JS rendering identical to the JS one.
                          //
                          // Rendering both variants was the alternative and it is a trap:
                          // _verification_fields.php would appear twice, so the form would
                          // carry two inputs named verify_doc_registration and two elements
                          // with the same id.
                          //
                          // Collapsed is still the free default. The listing is free and the
                          // form should say that first; an upsell sitting open above the
                          // submit button on the free path would say otherwise.
                          //
                          // Open never means required. See Listing::store(): submitting with
                          // nothing attached saves the listing and says so. A missing or
                          // rejected document must never cost someone their listing. ?>
                    <div class="verify-pick">
                        <input type="hidden" name="plan" value="<?= esc($plan, 'attr') ?>" data-plan-input>
                        <details class="verify-offer" data-verify-offer<?= $isVerified ? ' open' : '' ?>>
                            <?php // The whole closed state lives in the summary, because
                                  // .verify-offer[open] > summary hides it in one go — the same
                                  // trick the owner dashboard uses at _verification_panel.php.
                                  //
                                  // No verified badge anywhere in here, deliberately. A green tick
                                  // sitting on the control you click reads as a box you have just
                                  // ticked, and people were coming away thinking they had already
                                  // been verified. The badge appears in the open state below,
                                  // where it honestly means "you have asked for this". ?>
                            <summary>
                                <span class="verify-cta-heading">Want the Verified Business badge?</span>
                                <span class="hint">
                                    R<?= esc($verificationAmount) ?> a month. We review your documents first and
                                    only ask for payment if they check out &mdash; nothing to pay now. Cancel any
                                    time, and your listing is free either way.
                                </span>
                                <?php // A span, not a <button>: the <summary> is already the control,
                                      // and nesting a button inside it swallows the click. ?>
                                <span class="btn btn-accent">Add Verified Business</span>
                            </summary>
                            <div>
                                <p class="verify-chosen">
                                    <span class="badge badge-verified gap-1"><?= lucide('badge-check', 'h-3.5 w-3.5 shrink-0') ?>Verified Business</span>
                                    added &mdash; R<?= esc($verificationAmount) ?> a month once we have approved you
                                </p>
                                <p class="hint verify-offer-docs">
                                    <?php // Careful not to contradict _verification_fields.php below,
                                          // which says "we need both" — true of an application, but
                                          // not of the listing, which publishes regardless. ?>
                                    Send your company registration document and the owner's ID. We review
                                    them, usually within two working days, and only ask for payment once
                                    they pass. Nothing to attach yet? Your listing still publishes without
                                    them, and you can send both later from
                                    <a href="<?= base_url('manage') ?>">manage your profile</a>.
                                </p>
                                <?= view('directory/_verification_fields', ['amount' => $verificationAmount]) ?>
                                <?php // A link to the free route carrying the same data-plan-pick the
                                      // cards use, not a <button>. Three things fall out of that: the
                                      // picker already handles it, so there is no second code path;
                                      // it does the identical thing as picking the Free card, so it
                                      // cannot drift from it; and with JS off it still works, where a
                                      // button would have been a dead control. ?>
                                <a class="btn btn-ghost btn-xs mt-4" data-plan-pick="free"
                                   href="<?= base_url('add-listing') ?>">Remove &mdash; keep my listing free</a>
                            </div>
                        </details>
                        <?php // Outside the <details>, because it has to be readable once the
                              // panel it is talking about has closed. ?>
                        <p class="hint verify-cleared" data-plan-cleared hidden>
                            Your documents were removed. Your listing stays free.
                        </p>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-accent btn-block">Submit &amp; verify by email</button>
            </form>

            <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
                Already added? <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">Manage your profile</a>.
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= view('directory/_editor_assets') ?>
<?= $this->endSection() ?>
