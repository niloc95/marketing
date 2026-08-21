<?php
/**
 * Shared listing form fields.
 *
 * Rendered by three consumers, so they can never drift apart:
 *   - directory/form.php        public signup
 *   - directory/manage_edit.php owner self-service edit  ($lockEmail = true)
 *   - admin/edit.php            admin edit/create        (+ its own privileged panel)
 *
 * These are the *business* fields only. Privileged fields (status, is_featured,
 * is_verified, slug) deliberately live in the admin view alone — the server-side
 * whitelist is the real control, but keeping them out of here means the owner
 * form cannot even render them by accident.
 *
 * @var callable $v          fn(string $field, string $default = ''): string
 * @var callable $err        fn(string $field): string
 * @var array    $categories grouped rows from DirectoryCategoryModel::active()
 * @var array    $provinces  DirectoryService::SA_PROVINCES
 * @var bool     $lockEmail  render email read-only (owners cannot change identity)
 * @var bool     $showConsent
 * @var array    $vHours     decoded trading hours, keyed mon..sun (old value wins on resubmit)
 * @var string   $existingLogo  stored logo path, so an editor can see what is already set
 * @var int      $gallerySlots  photos still addable (8 minus what the listing has)
 * @var array    $vTeam       team rows (old input wins), rendered by _team_fields.php
 * @var array    $vLocations  branch rows (old input wins), rendered by _location_fields.php
 * @var bool     $showExtras  render the team and location sections at all
 */
$lockEmail    = $lockEmail    ?? false;
$showConsent  = $showConsent  ?? false;
$vHours       = $vHours       ?? [];
// Signup has no listing yet, so both default to "nothing stored, every slot
// free" — the edit pages pass real values from HandlesListingUploads.
$existingLogo = $existingLogo ?? '';
$gallerySlots = $gallerySlots ?? \App\Controllers\Listing::GALLERY_MAX;

// Team and branches are part of the paid badge and belong to a listing that
// already exists, so public signup gets neither — it passes none of these and
// the defaults switch both sections off. The services re-check the badge
// against the stored row; this only decides what is drawn.
$vTeam      = $vTeam      ?? [];
$vLocations = $vLocations ?? [];
$showExtras = $showExtras ?? false;
helper('directory_hours');
?>
<div class="form-row">
    <div class="field">
        <label>Profile type</label>
        <select name="type">
            <?php foreach (['person' => 'Individual / sole trader', 'practice' => 'Business / practice', 'facility' => 'Facility / branch'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $v('type', 'person') === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Category *</label>
        <select name="category_id">
            <option value="">Choose…</option>
            <?php $cur = ''; foreach ($categories as $p): ?>
                <?php if (($p['group_name'] ?? '') !== $cur): $cur = $p['group_name']; ?>
                    <optgroup label="<?= esc($cur, 'attr') ?>">
                <?php endif; ?>
                <option value="<?= (int) $p['id'] ?>" <?= (string) $v('category_id') === (string) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('category_id')): ?><div class="err"><?= esc($err('category_id')) ?></div><?php endif; ?>
    </div>
</div>

<?php // Directly under the profile type on purpose: "Business / practice" is the
      // answer that decides whether this listing has people at all, so "what
      // kind of business are you" reading straight into "who works here" is one
      // thought rather than two. ?>
<?php if ($showExtras): ?>
    <?= view('directory/_team_fields', ['rows' => $vTeam, 'err' => $err]) ?>
<?php endif; ?>

<div class="form-row">
    <div class="field">
        <label>Business or trading name *</label>
        <input type="text" name="display_name" value="<?= esc($v('display_name'), 'attr') ?>" maxlength="200" required>
        <?php if ($err('display_name')): ?><div class="err"><?= esc($err('display_name')) ?></div><?php endif; ?>
    </div>
    <div class="field">
        <label>Contact person</label>
        <input type="text" name="contact_person" value="<?= esc($v('contact_person'), 'attr') ?>" maxlength="150">
        <?php if ($err('contact_person')): ?><div class="err"><?= esc($err('contact_person')) ?></div><?php endif; ?>
    </div>
</div>

<div class="form-row">
    <div class="field">
        <label>Title</label>
        <input type="text" name="title" value="<?= esc($v('title'), 'attr') ?>" maxlength="60" placeholder="Dr, Mrs, Prof…">
        <?php if ($err('title')): ?><div class="err"><?= esc($err('title')) ?></div><?php endif; ?>
    </div>
    <div class="field">
        <label>Email *</label>
        <?php if ($lockEmail): ?>
            <input type="email" value="<?= esc($v('email'), 'attr') ?>" readonly disabled class="bg-slate-100 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
            <div class="hint">This is how we identify your profile. Contact us to change it.</div>
        <?php else: ?>
            <input type="email" name="email" value="<?= esc($v('email'), 'attr') ?>" required>
            <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="form-row">
    <div class="field">
        <label>Phone</label>
        <input type="text" name="phone" value="<?= esc($v('phone'), 'attr') ?>" maxlength="40">
        <?php if ($err('phone')): ?><div class="err"><?= esc($err('phone')) ?></div><?php endif; ?>
    </div>
    <div class="field">
        <label>Alternative phone</label>
        <input type="text" name="phone_alt" value="<?= esc($v('phone_alt'), 'attr') ?>" maxlength="40">
        <div class="hint">A mobile alongside a landline, say. Both are shown on your profile.</div>
        <?php if ($err('phone_alt')): ?><div class="err"><?= esc($err('phone_alt')) ?></div><?php endif; ?>
    </div>
</div>

<div class="field">
    <label>Website</label>
    <input type="text" name="website" value="<?= esc($v('website'), 'attr') ?>" maxlength="255" placeholder="https://…">
    <?php if ($err('website')): ?><div class="err"><?= esc($err('website')) ?></div><?php endif; ?>
</div>

<div class="field">
    <label>Credentials</label>
    <textarea name="credentials" rows="2" maxlength="500"><?= esc($v('credentials')) ?></textarea>
    <?php if ($err('credentials')): ?><div class="err"><?= esc($err('credentials')) ?></div><?php endif; ?>
</div>
<div class="field" data-rich-text>
    <label for="description">Description</label>
    <?php /*
        The textarea is the real form field and stays that way. directory.js
        hides it, mounts Quill into the div below, and copies the editor's HTML
        back into it on submit — so with JavaScript off or Quill failing to
        load, this posts plain text and RichText::sanitise() turns it into
        paragraphs. Nothing about the form depends on the editor existing.

        maxlength is gone because it counts markup, not words; the same 5000-
        character cap is enforced against the plain text by the counter in
        directory.js and, authoritatively, by the service on save.
    */ ?>
    <textarea id="description" name="description" rows="6" placeholder="What you offer, who you serve…"><?= esc($v('description')) ?></textarea>
    <?php /*
        The inner div is the mount and the outer one is not redundant: Quill
        turns the element it is given into .ql-container and inserts .ql-toolbar
        as its *previous sibling*. Without a wrapper the toolbar lands loose in
        .field, out of reach of the styles below and above the label's own hint.
    */ ?>
    <div class="rt-editor" hidden>
        <div data-rich-text-for="description"></div>
    </div>
    <div class="rt-count" data-rich-text-count hidden></div>
    <div class="hint">Use the toolbar to add headings, bold, lists and links.</div>
    <?php if ($err('description')): ?><div class="err"><?= esc($err('description')) ?></div><?php endif; ?>
</div>
<div class="field">
    <label>Areas of focus</label>
    <input type="text" name="specializations" value="<?= esc($v('specializations'), 'attr') ?>" placeholder="Comma-separated, e.g. Bridal packages, Emergency callouts, Home visits">
    <div class="hint">Separate with commas — up to 20.</div>
    <?php if ($err('specializations')): ?><div class="err"><?= esc($err('specializations')) ?></div><?php endif; ?>
</div>

<div class="field">
    <label class="font-medium"><input type="checkbox" name="accepts_card_payments" value="1" <?= $v('accepts_card_payments') ? 'checked' : '' ?>> Accepts card payments</label>
</div>
<div class="field">
    <label class="font-medium"><input type="checkbox" name="offers_delivery" value="1" <?= $v('offers_delivery') ? 'checked' : '' ?>> Offers delivery / mobile service</label>
</div>
<div class="field">
    <label class="font-medium"><input type="checkbox" name="offers_online_booking" value="1" <?= $v('offers_online_booking') ? 'checked' : '' ?>> Offers online booking</label>
</div>

<?php // Against the address block, where a second address belongs. ?>
<?php if ($showExtras): ?>
    <?= view('directory/_location_fields', [
        'rows'      => $vLocations,
        'provinces' => $provinces,
        'err'       => $err,
    ]) ?>
<?php endif; ?>

<div data-address-autocomplete
     data-suggest-url="<?= base_url('address-suggest') ?>">
    <div class="form-row">
        <div class="field relative">
            <label>Address</label>
            <input type="text" name="address_line" value="<?= esc($v('address_line'), 'attr') ?>" maxlength="255"
                   data-address-field="address_line" autocomplete="off"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="address-suggest-list">
            <ul class="address-suggest-list" id="address-suggest-list" role="listbox" data-address-suggest-list hidden></ul>
            <?php if ($err('address_line')): ?><div class="err"><?= esc($err('address_line')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Suburb</label>
            <input type="text" name="suburb" value="<?= esc($v('suburb'), 'attr') ?>" maxlength="120" data-address-field="suburb">
            <?php if ($err('suburb')): ?><div class="err"><?= esc($err('suburb')) ?></div><?php endif; ?>
        </div>
    </div>
    <?php // Unit, floor, building — detail that helps a customer find the door
          // but only confuses a geocoder, so it is stored and displayed and
          // deliberately left out of the lookup query. ?>
    <div class="field">
        <label>Address line 2 <span class="map-picker-optional">optional</span></label>
        <input type="text" name="address_line_2" value="<?= esc($v('address_line_2'), 'attr') ?>" maxlength="255"
               data-address-field="address_line_2" autocomplete="off"
               placeholder="Unit, floor, building">
        <?php if ($err('address_line_2')): ?><div class="err"><?= esc($err('address_line_2')) ?></div><?php endif; ?>
    </div>
    <div class="form-row">
        <div class="field">
            <label>City / town</label>
            <input type="text" name="city" value="<?= esc($v('city'), 'attr') ?>" maxlength="120" data-address-field="city">
            <?php if ($err('city')): ?><div class="err"><?= esc($err('city')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Postal code</label>
            <input type="text" name="postal_code" value="<?= esc($v('postal_code'), 'attr') ?>"
                   inputmode="numeric" maxlength="4" data-address-field="postal_code">
            <?php if ($err('postal_code')): ?><div class="err"><?= esc($err('postal_code')) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="field">
        <label>Province</label>
        <select name="province" data-address-field="province">
            <option value="">Choose…</option>
            <?php foreach ($provinces as $prov): ?>
                <option value="<?= esc($prov, 'attr') ?>" <?= $v('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('province')): ?><div class="err"><?= esc($err('province')) ?></div><?php endif; ?>
    </div>

    <?php // Picking a suggestion captures the exact point the geocoder returned
          // for that entry, so the server can save it as-is instead of
          // re-deriving a pin from the address text — which is what fails for
          // the many South African suburbs no geocoder has heard of. The script
          // clears these the moment any address field is edited by hand, so a
          // stale pin can't survive an address change.
          //
          // None of these are owner-writable columns: they reach the database
          // only through ListingGeocoder, which re-checks them. ?>
    <input type="hidden" name="latitude" value="<?= esc($v('latitude'), 'attr') ?>" data-address-coord="latitude">
    <input type="hidden" name="longitude" value="<?= esc($v('longitude'), 'attr') ?>" data-address-coord="longitude">
    <input type="hidden" name="geocode_precision" value="<?= esc($v('geocode_precision'), 'attr') ?>" data-address-coord="geocode_precision">

    <?php // The pin picker. No geocoder has a record of every real South African
          // suburb, so some of these businesses will never be placed correctly
          // by lookup alone — dragging the marker is the only thing that can,
          // and a pin placed this way is saved as 'manual' and never recomputed.
          // Progressive enhancement: without JavaScript this is an empty div and
          // the form still submits, falling back to server-side geocoding
          // exactly as before. ?>
    <div class="field map-picker"
         data-map-picker
         data-tile-url="<?= esc(config('Directory')->mapTileUrl(), 'attr') ?>"
         data-tile-attribution="<?= esc(config('Directory')->mapTileAttribution(), 'attr') ?>"
         data-icon-path="<?= base_url('assets/vendor/leaflet/images/') ?>"
         data-locate-url="<?= base_url('address-locate') ?>"
         data-reverse-url="<?= base_url('address-reverse') ?>">
        <?php
        // Say plainly when the saved pin is only a guess. A "street" match is a
        // point somewhere along the road — OpenStreetMap has no house numbers on
        // many South African streets, so it can sit hundreds of metres from the
        // real door — and suburb/city matches are centroids. Those are exactly
        // the listings where dragging the marker is the only real fix, so they
        // get a direct ask rather than the generic invitation.
        $pinPrecision  = $v('geocode_precision');
        $pinIsApprox   = $v('latitude') !== '' && ! in_array($pinPrecision, ['manual', 'exact'], true);
        $approxWording = [
            'street' => 'We could only place you somewhere along your street',
            'suburb' => 'We could only place you in your suburb',
            'city'   => 'We could only place you in your city',
        ][$pinPrecision] ?? 'We could not place you precisely';
        ?>
        <label>Pin your exact location <span class="map-picker-optional"><?= $pinIsApprox ? 'please check' : 'optional' ?></span></label>
        <?php if ($pinIsApprox): ?>
            <p class="map-picker-help map-picker-warn">
                <strong><?= esc($approxWording) ?>.</strong>
                Please drag the marker onto your exact spot &mdash; otherwise directions may send
                customers to the wrong part of the road.
            </p>
        <?php else: ?>
            <p class="map-picker-help">
                Drag the marker to where your business actually is. Worth doing if the map looks
                wrong &mdash; we can&rsquo;t always find smaller suburbs automatically.
            </p>
        <?php endif; ?>
        <div class="map-picker-canvas" data-map-picker-canvas></div>
        <div class="map-picker-actions">
            <button type="button" class="btn-ghost btn-xs" data-map-picker-locate>Find my address on the map</button>
            <button type="button" class="btn-ghost btn-xs" data-map-picker-reset hidden>Clear pin</button>
            <span class="map-picker-status" role="status" data-map-picker-status></span>
        </div>

        <?php // After a drag, the pin is right but the typed address probably
              // isn't. We show what OpenStreetMap says is at that spot and let
              // the user decide — never apply it for them. A hand-placed pin
              // exists precisely because the geocoder was wrong, so it doesn't
              // get to overwrite the address it just lost an argument with.
              // Either way the pin stays 'manual'. ?>
        <div class="map-picker-suggestion" data-map-picker-suggestion hidden>
            <p class="map-picker-suggestion-label">That pin looks like:</p>
            <p class="map-picker-suggestion-text" data-map-picker-suggestion-text></p>
            <div class="map-picker-actions">
                <button type="button" class="btn-ghost btn-xs" data-map-picker-suggestion-accept>Use this address</button>
                <button type="button" class="btn-ghost btn-xs" data-map-picker-suggestion-dismiss>Keep mine</button>
            </div>
        </div>
    </div>
</div>

<div class="field">
    <label>Trading hours</label>
    <?php // Filling the same times seven times is the tedious part of this form,
          // and most businesses trade the same hours Monday to Friday at least.
          //
          // Above the grid, not below it. The seven rows stack on a phone and run
          // to roughly a screen and a half, so a button underneath them is only
          // found by someone who has already typed all seven — which is the one
          // moment it is no use. Up here it is on screen before the first row is
          // filled, and clicking it early is handled: with Monday blank it says
          // so and changes nothing.
          //
          // hidden until directory.js unhides it, the same contract "Use my
          // location" uses on the browse page: with no JavaScript the button
          // could not do anything, and a control that does nothing when pressed
          // is worse than one that was never offered. The grid itself needs no
          // script — it is seven rows of plain inputs either way.
          //
          // The label names Monday because that is what it copies: the first row,
          // not "whichever row you last touched". Naming the source is what makes
          // the result predictable before the click rather than after it. ?>
    <div class="hours-actions">
        <button type="button" class="btn btn-ghost btn-xs" data-hours-copy hidden>Copy Monday to every day</button>
        <span class="hint" role="status" data-hours-copy-note></span>
    </div>
    <div class="hours-grid" data-hours>
        <?php foreach (hours_days() as $key => $label): $row = $vHours[$key] ?? []; ?>
            <div class="hours-row" data-hours-row>
                <span class="hours-day"><?= esc($label) ?></span>
                <label class="hours-closed"><input type="checkbox" name="hours[<?= $key ?>][closed]" value="1" <?= ! empty($row['closed']) ? 'checked' : '' ?>> Closed</label>
                <input type="time" name="hours[<?= $key ?>][open]" value="<?= esc($row['open'] ?? '', 'attr') ?>">
                <span>&ndash;</span>
                <input type="time" name="hours[<?= $key ?>][close]" value="<?= esc($row['close'] ?? '', 'attr') ?>">
                <input type="text" class="hours-note" name="hours[<?= $key ?>][note]" value="<?= esc($row['note'] ?? '', 'attr') ?>" maxlength="120" placeholder="Optional note, e.g. Lunch 12:00–13:30">
            </div>
        <?php endforeach; ?>
    </div>
    <div class="hint">Leave a day's times blank and tick "Closed" for days you don't trade.</div>
</div>

<?php // accept="image/*" on both is deliberate: it is what makes iOS offer the
      // photo library and transcode HEIC to JPEG on the way out. Narrowing it to
      // a MIME list blocks iPhone photos outright. The server re-checks anyway. ?>
<div class="field">
    <label for="logo-input">Logo / photo</label>
    <?php if ($existingLogo !== ''): ?>
        <div class="upload-current" data-image-current>
            <?php // Same absolute-vs-relative rule the card and profile pages use. ?>
            <img src="<?= esc(preg_match('#^https?://#i', $existingLogo) ? $existingLogo : base_url($existingLogo), 'attr') ?>" alt="Current logo">
            <span class="hint">Current logo — choosing a file replaces it.</span>
        </div>
    <?php endif; ?>
    <input type="file" id="logo-input" name="logo" accept="image/*" data-image-upload="single">
    <div class="upload-preview" data-image-preview></div>
    <div class="hint">JPEG, PNG, WebP, GIF, BMP or AVIF — up to 10 MB, resized automatically.</div>
</div>
<div class="field">
    <label for="gallery-input">Photo gallery</label>
    <?php if ($gallerySlots > 0): ?>
        <input type="file" id="gallery-input" name="gallery[]" accept="image/*" multiple
               data-image-upload="multi" data-max-files="<?= (int) $gallerySlots ?>">
        <div class="upload-preview" data-image-preview></div>
        <div class="hint">
            Up to <?= (int) $gallerySlots ?> more photo<?= $gallerySlots === 1 ? '' : 's' ?>, 10 MB each.
            Any common photo format — resized and optimised automatically.
        </div>
    <?php else: ?>
        <div class="hint">This profile already has the maximum number of photos. Delete one above to add another.</div>
    <?php endif; ?>
</div>

<?php if ($showConsent): ?>
    <div class="field">
        <label class="font-medium"><input type="checkbox" name="consent" value="1" <?= $v('consent') ? 'checked' : '' ?>> I confirm I'm authorised to publish these business details publicly on <?= esc(config('Directory')->siteName()) ?>.</label>
        <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
    </div>
<?php endif; ?>
