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
 * @var array    $vServices   service rows (old input wins), rendered by _service_fields.php
 * @var array    $vAttributes ticked feature keys (old input wins), rendered by _attribute_fields.php
 * @var array    $photos      stored gallery photos (edit pages only), shown above the upload
 * @var string   $deleteBase  where a photo's delete button posts, e.g. base_url('manage/photo-delete')
 * @var bool     $addressRequired  insist on a full address (public signup only)
 * @var bool     $privateDetailsRequired  insist on a title and a contact person (not admin intake)
 * @var array    $countries  Config\Countries::grouped()
 * @var bool     $lockCountry  show the country as read-only text (owner edit)
 *
 * Layout: the essentials a profile cannot do without come first and are always
 * open — category, name, contact, a short description and where you are. Then
 * everything that makes a profile look good but is optional sits below in
 * collapsed <details>, so a first signup is a two-minute job and nothing is
 * hidden that has content or an error in it.
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
$vServices   = $vServices   ?? [];
$vAttributes = $vAttributes ?? [];
$photos      = $photos      ?? [];
$deleteBase  = $deleteBase  ?? '';

// Only public signup insists on an address. The two edit pages pass false
// explicitly: 10 of the listings that predate the rule have no street address,
// and their owners must not be locked out of changing a phone number over it.
// Backfilling those is a separate job. See _address_inputs.php on why every
// caller passes this rather than leaning on the default.
$addressRequired = $addressRequired ?? false;

// The private "Your details" pair. Required wherever the business itself is
// filling the form in — signup and the owner edit both pass true, and
// DirectoryListingMutationService::validate() enforces it on both paths.
// Admin passes false for the same reason it passes addressRequired false:
// intake covers imports and phone captures where nobody has yet said who to
// ask for.
$privateDetailsRequired = $privateDetailsRequired ?? false;

// The country select belongs to the listing's own address only — a branch is
// another location of the same business and inherits it. See _address_inputs.
$countries   = $countries   ?? [];
$lockCountry = $lockCountry ?? false;
helper('directory_hours');
?>
<?php if ($photos !== [] && $deleteBase !== ''): ?>
    <?php // Pressing Enter in a text field submits the form through its *first*
          // submit button in tree order. The photo delete buttons further down are
          // submit buttons, so without this one Enter would ask to delete a photo
          // instead of saving. Off-screen rather than hidden: a button that is not
          // rendered is not reliably used as the default. ?>
    <button type="submit" class="form-default-submit" tabindex="-1" aria-hidden="true">Save</button>
<?php endif; ?>
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
        <label for="field-category">Category *</label>
        <select id="field-category" name="category_id">
            <option value="">Choose…</option>
            <?php $cur = ''; foreach ($categories as $p): ?>
                <?php if (($p['group_name'] ?? '') !== $cur): $cur = $p['group_name']; ?>
                    <optgroup label="<?= esc($cur, 'attr') ?>">
                <?php endif; ?>
                <option value="<?= (int) $p['id'] ?>" data-group="<?= esc((string) ($p['group_name'] ?? ''), 'attr') ?>" <?= (string) $v('category_id') === (string) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
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
        <label for="field-phone">Phone</label>
        <input type="text" id="field-phone" name="phone" value="<?= esc($v('phone'), 'attr') ?>" maxlength="40">
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
    <label for="field-website">Website</label>
    <input type="text" id="field-website" name="website" value="<?= esc($v('website'), 'attr') ?>" maxlength="255" placeholder="https://…">
    <?php if ($err('website')): ?><div class="err"><?= esc($err('website')) ?></div><?php endif; ?>
</div>

<div class="field" data-rich-text data-rich-text-max="<?= \App\Libraries\RichText::MAX_PLAIN_LENGTH ?>">
    <label for="description">Business description</label>
    <?php /*
        The textarea is the real form field and stays that way. directory.js
        hides it, mounts Quill into the div below, and copies the editor's HTML
        back into it on submit — so with JavaScript off or Quill failing to
        load, this posts plain text and RichText::sanitise() turns it into
        paragraphs. Nothing about the form depends on the editor existing.

        maxlength is gone because it counts markup, not words. The cap
        (RichText::MAX_PLAIN_LENGTH, on the wrapper above) is enforced against
        the plain text by the counter in directory.js and, authoritatively, by
        the service on save.
    */ ?>
    <textarea id="description" name="description" rows="6" placeholder="A few sentences: what you do, who it’s for, and why people choose you."><?= esc($v('description')) ?></textarea>
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
    <div class="hint">
        Keep it short &mdash; up to <?= number_format(\App\Libraries\RichText::MAX_PLAIN_LENGTH) ?> characters.
        Cover <strong>what you do</strong>, <strong>who it&rsquo;s for</strong> and <strong>why choose you</strong>.
        List individual services and prices under <em>Services &amp; prices</em> below instead.
    </div>
    <?php if ($err('description')): ?><div class="err"><?= esc($err('description')) ?></div><?php endif; ?>
</div>

<?php // id: the profile-strength panel links here for the address, city and
      // province steps. The ids for those three inputs cannot live in
      // _address_inputs.php itself — branch rows render the same partial, so
      // they would repeat down the page. ?>
<div id="field-address" data-address-autocomplete
     data-suggest-url="<?= base_url('address-suggest') ?>">
    <?php // The address block itself — shared with every branch row in
          // _location_fields.php, which renders the same partial with a
          // 'locations[i]' prefix and no autocomplete. ?>
    <?= view('directory/_address_inputs', [
        'n'         => static fn (string $f): string => $f,
        'val'       => $v,
        'e'         => $err,
        'provinces' => $provinces,
        // The listing's listbox keeps the bare id it has always had; branches
        // suffix theirs with the row index.
        'listId'    => 'address-suggest-list',
        // false: the wrapper is opened above, because the pin picker below has
        // to sit inside it.
        'wrap'      => false,
        'required'  => $addressRequired,
        // The listing's own address is the one that carries a country.
        'withCountry' => true,
        'countries'   => $countries,
        'lockCountry' => $lockCountry,
    ]) ?>

    <?php // The pin picker. No geocoder has a record of every real South African
          // suburb, so some of these businesses will never be placed correctly
          // by lookup alone — dragging the marker is the only thing that can,
          // and a pin placed this way is saved as 'manual' and never recomputed.
          // Progressive enhancement: without JavaScript this is an empty div and
          // the form still submits, falling back to server-side geocoding
          // exactly as before. ?>
    <div class="field map-picker"
         id="field-map"
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

<?php // Title and contact person name a private individual, so they are kept for
      // administration only — _contact_panel.php does not render either. The
      // fieldset says so up front rather than leaving owners to guess. ?>
<fieldset class="form-private">
    <legend>Your details <span class="form-private-note">not shown on your profile</span></legend>
    <div class="form-row">
        <div class="field">
            <label>Title<?= $privateDetailsRequired ? ' *' : '' ?></label>
            <input type="text" name="title" value="<?= esc($v('title'), 'attr') ?>" maxlength="60" placeholder="Dr, Mrs, Prof…" <?= $privateDetailsRequired ? 'required' : '' ?>>
            <?php if ($err('title')): ?><div class="err"><?= esc($err('title')) ?></div><?php endif; ?>
        </div>
        <div class="field">
            <label>Contact person<?= $privateDetailsRequired ? ' *' : '' ?></label>
            <input type="text" name="contact_person" value="<?= esc($v('contact_person'), 'attr') ?>" maxlength="150" <?= $privateDetailsRequired ? 'required' : '' ?>>
            <?php if ($err('contact_person')): ?><div class="err"><?= esc($err('contact_person')) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="hint">For our records, and so we know who to address when we contact you about this listing.</div>
</fieldset>

<div class="form-section-head">
    <h3>Make your profile stand out</h3>
    <p class="hint">All optional, and you can come back to any of it later. Each section you fill in adds a panel to your public profile.</p>
</div>

<div id="field-services">
<?= view('directory/_service_fields', ['rows' => $vServices, 'err' => $err]) ?>
</div>

<div id="field-features">
<?= view('directory/_attribute_fields', [
    'v'          => $v,
    'err'        => $err,
    'categories' => $categories,
    'selected'   => $vAttributes,
]) ?>
</div>

<?php // Shared with every branch row — see _hours_inputs.php. Open once any
      // day has something in it. ?>
<details class="disclosure" id="field-hours" <?= array_filter($vHours, static fn ($d): bool => is_array($d) && (! empty($d['open']) || ! empty($d['close']) || ! empty($d['closed']))) ? 'open' : '' ?>>
    <summary class="disclosure-summary">
        <span>Opening hours</span>
        <span class="hint">So customers know when to call</span>
    </summary>
    <div class="disclosure-body">
<?= view('directory/_hours_inputs', [
    'n'     => static fn (string $f): string => $f,
    'hours' => $vHours,
    'copy'  => true,
]) ?>
    </div>
</details>

<?php // Below every field belonging to the business's own address — its
      // address, its pin and its hours — because a branch repeats all three.
      // Asking for a second location before the first one has been given reads
      // backwards, and it used to sit above the address block. ?>
<?php if ($showExtras): ?>
    <?= view('directory/_location_fields', [
        'rows'      => $vLocations,
        'provinces' => $provinces,
        'err'       => $err,
    ]) ?>
<?php endif; ?>

<?php // Open by default: photos do more for a profile than anything else on
      // this form, so this is the one optional section that is not folded away. ?>
<details class="disclosure" open>
    <summary class="disclosure-summary">
        <span>Logo &amp; photos</span>
        <span class="hint">Profiles with photos get far more attention</span>
    </summary>
    <div class="disclosure-body">
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
<?php // Rendered even when the gallery is full, just hidden: deleting a photo
      // happens in place now (see _gallery_manage.php), so the script needs an
      // input to reveal when a slot frees up rather than a page reload. ?>
<div class="field" data-gallery-upload>
    <label for="gallery-input">Photo gallery</label>
    <?php // The photos already saved sit right above the input that adds more.
          // Signup has none, so this renders nothing there. ?>
    <?php if ($photos !== [] && $deleteBase !== ''): ?>
        <?= view('directory/_gallery_manage', [
            'photos'     => $photos,
            'deleteBase' => $deleteBase,
            'max'        => \App\Controllers\Listing::GALLERY_MAX,
        ]) ?>
    <?php endif; ?>
    <div data-gallery-open <?= $gallerySlots > 0 ? '' : 'hidden' ?>>
        <input type="file" id="gallery-input" name="gallery[]" accept="image/*" multiple
               data-image-upload="multi" data-max-files="<?= (int) $gallerySlots ?>">
        <div class="upload-preview" data-image-preview></div>
        <div class="hint">
            Up to <span data-gallery-slots><?= (int) $gallerySlots ?> more photo<?= $gallerySlots === 1 ? '' : 's' ?></span>, 10 MB each.
            Any common photo format — resized and optimised automatically.
        </div>
    </div>
    <div class="hint" data-gallery-full <?= $gallerySlots > 0 ? 'hidden' : '' ?>>This profile already has the maximum number of photos. Delete one above to add another.</div>
</div>
    </div>
</details>

<details class="disclosure" <?= $v('credentials') !== '' || $v('specializations') !== '' || $err('credentials') !== '' || $err('specializations') !== '' ? 'open' : '' ?>>
    <summary class="disclosure-summary">
        <span>More details</span>
        <span class="hint">Qualifications and areas of focus</span>
    </summary>
    <div class="disclosure-body">
<div class="field">
    <label>Credentials</label>
    <textarea name="credentials" rows="2" maxlength="500"><?= esc($v('credentials')) ?></textarea>
    <?php if ($err('credentials')): ?><div class="err"><?= esc($err('credentials')) ?></div><?php endif; ?>
</div>
<div class="field">
    <label>Areas of focus</label>
    <input type="text" id="field-tags" name="specializations" value="<?= esc($v('specializations'), 'attr') ?>" placeholder="Comma-separated, e.g. Bridal packages, Emergency callouts, Home visits">
    <div class="hint">Separate with commas — up to 20.</div>
    <?php if ($err('specializations')): ?><div class="err"><?= esc($err('specializations')) ?></div><?php endif; ?>
</div>
    </div>
</details>

<?php if ($showConsent): ?>
    <?php // New tab for both links, so reading the terms never costs a half-filled form. ?>
    <div class="field">
        <label class="font-medium"><input type="checkbox" name="consent" value="1" <?= $v('consent') ? 'checked' : '' ?>> I confirm I'm authorised to publish these business details publicly on <?= esc(config('Directory')->siteName()) ?>, and I accept the <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('terms') ?>" target="_blank" rel="noopener">Terms of use</a> and <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('privacy') ?>" target="_blank" rel="noopener">Privacy policy</a>.</label>
        <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
    </div>
    <?php // A second question, never part of the one above.
          //
          // Ticked by default, which the old marketing question could not be:
          // POPIA s69 consent must be freely given, and a pre-ticked box does
          // not collect that. What this box offers is not marketing — it is a
          // report about the owner's own listing, on the same footing as the
          // verify and badge-billing email we send without asking. So the
          // default is on and unticking is the whole opt-out.
          //
          // If this ever grows back into news, tips or offers, it has to go
          // back to a deliberate opt-in. The wording below is the limit of
          // what the permission covers.
          //
          // marketing_present is the sticky-render marker only: on a rejected
          // save, a box the person unticked must come back unticked rather
          // than falling back to the default. Same mechanism as
          // manage_edit.php, where the fallback is the stored value instead.
          $vMarketing = $v('marketing_present') === '1' ? $v('marketing_opt_in') === '1' : true; ?>
    <div class="field">
        <input type="hidden" name="marketing_present" value="1">
        <label><input type="checkbox" name="marketing_opt_in" value="1" <?= $vMarketing ? 'checked' : '' ?>> We use your email to send you monthly analytics for your listing — how many views it got and where your leads came from.</label>
        <div class="hint">Untick if you'd rather not. You can change this any time in Manage your profile, and every report has an unsubscribe link.</div>
    </div>
<?php endif; ?>
