<?php
/**
 * The two file inputs behind a Verified Business application.
 *
 * Shared by the optional block on the public signup form and the upgrade panel
 * on the owner dashboard, so the field names — which HandlesVerificationUploads
 * looks for by name — are written once.
 *
 * Deliberately NOT part of _form_fields.php. That partial is also included by
 * admin/edit.php, and an admin has no business uploading an owner's ID on their
 * behalf: the whole point of the documents is that the owner sent them.
 *
 * @var string $amount monthly price, already formatted to two decimals
 */
?>
<div class="field">
    <label for="verify-doc-registration">Company registration document</label>
    <input type="file" id="verify-doc-registration" name="verify_doc_registration"
           accept=".pdf,application/pdf,image/jpeg,image/png">
    <div class="hint">Your CIPC registration certificate, or equivalent. PDF, JPEG or PNG, up to 10&nbsp;MB.</div>
</div>

<div class="field">
    <label for="verify-doc-id">Owner ID document</label>
    <input type="file" id="verify-doc-id" name="verify_doc_id"
           accept=".pdf,application/pdf,image/jpeg,image/png">
    <div class="hint">
        ID card, ID book page or passport for the person who owns the business.
        A clear photo is fine, as long as the whole document is in frame.
    </div>
</div>

<p class="hint">
    We need both. Your documents are stored privately, are never shown on your public profile,
    and are only ever seen by our review team.
</p>
