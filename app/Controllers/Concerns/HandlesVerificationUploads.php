<?php

namespace App\Controllers\Concerns;

use App\Libraries\VerificationDocumentProcessor;
use App\Models\DirectoryVerificationDocumentModel;

/**
 * Verified Business document uploads, for the two controllers that accept them:
 * Listing (the optional block on public signup) and Manage (the owner
 * dashboard's upgrade panel).
 *
 * Separate from HandlesListingUploads because the two have nothing in common
 * beyond the word "upload". That trait deals in web-served images with a
 * per-listing count cap and a "leave the existing one alone" empty-path
 * convention; this one deals in two fixed private documents where absence
 * simply means the owner isn't applying.
 *
 * Files land on disk here, before any row exists — same shape as the gallery,
 * and unavoidable, since PHP releases the temp files at the end of the request
 * whether or not the database co-operated. VerificationService cleans up
 * anything it could not attach to a row.
 *
 * @property \CodeIgniter\HTTP\IncomingRequest $request
 */
trait HandlesVerificationUploads
{
    /**
     * Form field names, mapped to what the document is. Kept here rather than in
     * the view so a renamed input breaks in one obvious place.
     */
    private const VERIFICATION_FIELDS = [
        'verify_doc_registration' => DirectoryVerificationDocumentModel::KIND_REGISTRATION,
        'verify_doc_id'           => DirectoryVerificationDocumentModel::KIND_OWNER_ID,
    ];

    /**
     * Validate and store whatever verification documents this request carries.
     *
     * @return array{docs:array<string,array{name:string,mime:string,bytes:int,original_name:string}>,errors:array<int,string>}
     *   `docs` is keyed by document kind and empty when nobody is applying —
     *   the normal case on a signup, not an error.
     */
    protected function resolveVerificationDocuments(int $listingId): array
    {
        $docs   = [];
        $errors = [];

        $destDir   = DirectoryVerificationDocumentModel::storageRoot() . '/' . $listingId;
        $processor = new VerificationDocumentProcessor();

        foreach (self::VERIFICATION_FIELDS as $field => $kind) {
            $file = $this->request->getFile($field);

            if (! $file || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $result = $processor->process($file, $destDir);

            if ($result['ok']) {
                $docs[$kind] = [
                    'name'          => $result['name'],
                    'mime'          => $result['mime'],
                    'bytes'         => $result['bytes'],
                    'original_name' => $file->getClientName(),
                ];
            } else {
                $errors[] = $result['error'];
            }
        }

        return ['docs' => $docs, 'errors' => $errors];
    }
}
