<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Upload handling for Verified Business evidence: a company registration
 * document and a copy of the owner's ID.
 *
 * A sibling of ListingImageProcessor rather than a mode of it, because the two
 * want opposite things. That class exists to *transform* what it is given —
 * resize, apply EXIF rotation, re-encode everything to WebP — since a logo's
 * only job is to look right at 200px. This one must return the bytes it was
 * handed, unchanged: the file is evidence. If a registration certificate is
 * ever queried, "here is what they sent us" has to be literally true, and a
 * re-encoded JPEG is not that. Threading a "don't touch it" flag through the
 * other class's branches would have left the two intentions tangled in one
 * place where a later edit could quietly re-encode a passport scan.
 *
 * The other difference is where the output lands. Listing images go to
 * public/assets/listings and are served directly by Apache. These go under
 * writable/, outside the docroot, and are readable only through a session-gated
 * streaming route. Nothing here should ever be given a path under FCPATH.
 *
 * Like its sibling, every rejection returns a sentence rather than null — a
 * document that vanishes silently is worse than one that is refused loudly,
 * because the owner sits waiting for a review that will never be queued.
 */
class VerificationDocumentProcessor
{
    /**
     * Deliberately narrower than the image processor's list. A registration
     * certificate is a CIPC PDF; an ID is a PDF or a phone photo of a document.
     * Nothing else is a legitimate answer, and every extra format is another
     * parser an admin's browser will be pointed at. No HEIC (we cannot convert
     * it and would only be storing something unopenable), no Office formats
     * (macro-bearing), no archives.
     */
    public const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    /** Same ceiling as listing images — a scanned certificate fits easily. */
    public const MAX_UPLOAD_BYTES = 10_485_760; // 10 MiB

    /**
     * Detected MIME must match the claimed extension. Unlike the image path,
     * there is no octet-stream tolerance: all three formats have well-known
     * signatures that finfo reads reliably, so a file finfo cannot place is a
     * file we do not want.
     */
    private const ALLOWED_MIME = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png'],
    ];

    /**
     * Leading bytes each format must begin with.
     *
     * The image processor gets this check for free: it hands everything to GD,
     * which fails on anything that is not really an image. Here nothing decodes
     * the file at all, so without an explicit signature check the only thing
     * standing between an arbitrary payload and long-term storage would be
     * finfo — and finfo is a guess over the same bytes an attacker controls.
     * Reading eight bytes closes the gap for the cost of one fopen.
     */
    private const MAGIC = [
        'pdf'  => ["%PDF-"],
        'jpg'  => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png'  => ["\x89PNG\r\n\x1A\n"],
    ];

    /**
     * Validate and store one document.
     *
     * @param string $destDir absolute path, must be under the private storage root
     *
     * @return array{ok:bool,name:string,mime:string,bytes:int,error:string}
     *   `name` is the generated filename only; the caller composes the stored
     *   path, because only it knows the listing subdirectory. Never throws.
     */
    public function process(UploadedFile $file, string $destDir): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            return $this->fail(sprintf(
                '“%s” did not upload correctly (%s).',
                $file->getClientName(),
                strtolower($file->getErrorString() ?: 'unknown error')
            ));
        }

        $name = $file->getClientName();

        // getClientExtension() for the same reason ListingImageProcessor gives:
        // getExtension() derives from the detected MIME. The claimed extension
        // picks the rules; the MIME and magic-byte checks below are what stop it
        // being trusted.
        $ext = strtolower($file->getClientExtension() ?: '');

        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return $this->fail(sprintf(
                '“%s” is not a supported document. Use PDF, JPEG or PNG.',
                $name
            ));
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->fail(sprintf(
                '“%s” is %s — the limit is %s per document.',
                $name,
                $this->humanBytes((int) $file->getSize()),
                $this->humanBytes(self::MAX_UPLOAD_BYTES)
            ));
        }

        $mime = strtolower(trim(explode(';', (string) $file->getMimeType())[0]));
        if (! in_array($mime, self::ALLOWED_MIME[$ext], true)) {
            return $this->fail(sprintf(
                '“%s” does not look like a real %s file (it is %s).',
                $name,
                strtoupper($ext),
                $mime !== '' ? $mime : 'unrecognised'
            ));
        }

        if (! $this->hasMagicFor($file->getTempName(), $ext)) {
            return $this->fail(sprintf(
                '“%s” is named like a %s but its contents are something else.',
                $name,
                strtoupper($ext)
            ));
        }

        // 0700, not the 0755 used for public assets. Nothing but PHP should be
        // able to list this directory, and on shared hosting "nothing but PHP"
        // is worth spelling out rather than inheriting.
        if (! is_dir($destDir) && ! @mkdir($destDir, 0700, true) && ! is_dir($destDir)) {
            log_message('error', 'VerificationDocumentProcessor: could not create storage directory');

            return $this->fail('The server could not store the document. Please try again later.');
        }

        // The mode argument to mkdir() is advisory — it is masked by the
        // process umask, and with recursive creation PHP does not apply it
        // consistently across platforms. Observed in practice: a directory asked
        // for as 0700 arriving as 0755. Restating it here is the only way the
        // permission is actually what this code says it is.
        @chmod($destDir, 0700);

        $stored = $this->generateName($ext);
        $bytes  = (int) $file->getSize();

        try {
            // move(), with no re-encoding of any kind. See the class docblock.
            $file->move($destDir, $stored);
        } catch (\Throwable $e) {
            log_message('error', 'VerificationDocumentProcessor upload failed: ' . $e->getMessage());

            return $this->fail(sprintf('“%s” could not be saved. Please try again.', $name));
        }

        @chmod($destDir . '/' . $stored, 0600);

        return ['ok' => true, 'name' => $stored, 'mime' => $mime, 'bytes' => $bytes, 'error' => ''];
    }

    /**
     * Does the file begin with a signature this extension requires?
     *
     * A read failure counts as a failed check. This runs before anything is
     * stored, so refusing is free, and "we could not read it" is not a reason to
     * keep a file we could not verify.
     */
    private function hasMagicFor(string $tempName, string $ext): bool
    {
        $handle = @fopen($tempName, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = @fread($handle, 8);
        @fclose($handle);

        if (! is_string($head) || $head === '') {
            return false;
        }

        foreach (self::MAGIC[$ext] as $signature) {
            if (str_starts_with($head, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sixteen hex characters of entropy rather than the eight the public image
     * names carry. Those names sit behind no auth at all and are meant to be
     * unguessable-ish; these sit behind an admin session, and the filename is
     * the last thing standing if that session boundary is ever weakened. The
     * timestamp prefix stays because it makes the storage directory legible to
     * a human during an incident.
     */
    private function generateName(string $ext): string
    {
        return 'verif_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /**
     * @return array{ok:bool,name:string,mime:string,bytes:int,error:string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'name' => '', 'mime' => '', 'bytes' => 0, 'error' => $error];
    }
}
