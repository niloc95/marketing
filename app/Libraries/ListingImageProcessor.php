<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Shared upload handling for listing logos and gallery photos: validates,
 * resizes, and re-encodes to WebP. Used in place of the ad-hoc, duplicated
 * resolveLogo() that used to live separately in Listing.php and Manage.php.
 *
 * GD only (no Imagick dependency). GIF passes through unresized because it may
 * be animated and GD would silently flatten it to a single frame — and because
 * that makes its accepted size its stored size, it carries a much tighter cap
 * of its own (MAX_PASSTHROUGH_BYTES). SVG is no longer accepted at all; the
 * sanitising path below is kept only for logos stored before it was removed.
 *
 * Every rejection returns an explanation. This class used to return null for
 * all of them, which meant an oversized phone photo was dropped in silence
 * while the form still reported success.
 */
class ListingImageProcessor
{
    /**
     * Extensions we accept. Everything except gif is decoded by GD and
     * re-encoded to WebP, so this list is bounded by what GD can read:
     * heic/heif are accepted only so we can return a useful message (see
     * HEIC_EXT) — GD cannot decode them and neither can we without Imagick.
     */
    public const ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'avif', 'heic', 'heif'];

    /** Passed through to disk rather than re-encoded. */
    public const PASSTHROUGH_EXT = ['gif'];

    /**
     * Ceiling on declared pixel count, checked before GD is handed the bytes.
     *
     * getimagesize() reads the header only; imagecreatefromstring() allocates
     * the whole decompressed canvas at roughly 4 bytes a pixel. Those are very
     * different costs, and the gap is the decompression bomb: a ~2 MB PNG can
     * legitimately declare 30000x30000, which is 900 megapixels and about
     * 3.6 GB of RAM — the process dies, or the box starts swapping, for the
     * price of one upload. 40 MP is past any real camera (a 100 MP phone photo
     * is ~12000x9000 = 108 MP, but nobody uploads one as a shop logo) and far
     * under anything dangerous.
     */
    public const MAX_PIXELS = 40_000_000;

    /** Accepted by the picker but undecodable server-side; see process(). */
    public const HEIC_EXT = ['heic', 'heif'];

    /**
     * Ceiling on what we accept, not on what we keep. Everything that reaches
     * resizeToWebp() leaves it as a 1600px WebP — a 6 MB phone photo is written
     * to disk at roughly 50-250 KB — so this number governs the upload, not the
     * storage bill. It is deliberately generous: the browser downscales before
     * sending, but with no JavaScript the camera original arrives whole, and
     * phone photos are routinely 3-6 MB. A tighter limit here would reject
     * ordinary uploads to save disk space that is never used.
     */
    public const MAX_UPLOAD_BYTES = 10_485_760; // 10 MiB

    /**
     * The exception, and the reason it needs its own number: a GIF is the one
     * format written to disk byte for byte (see PASSTHROUGH_EXT — GD would
     * flatten an animation to a single frame). For a GIF the accepted size *is*
     * the stored size, so MAX_UPLOAD_BYTES would let one profile put 80 MB of
     * animation on the disk through the eight gallery slots.
     *
     * 2 MiB is past any GIF logo and well short of that.
     */
    public const MAX_PASSTHROUGH_BYTES = 2_097_152; // 2 MiB

    /**
     * Detected MIME must match the claimed extension. The extension whitelist
     * is the first gate and getimagesize()/imagecreatefromstring() is the real
     * one; this sits between them to reject a .txt renamed to .jpg before we
     * hand its bytes to GD. octet-stream is tolerated only for the container
     * formats finfo routinely misreports (HEIC, AVIF, BMP).
     */
    private const ALLOWED_MIME = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp', 'application/octet-stream'],
        'avif' => ['image/avif', 'application/octet-stream'],
        'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
        'heif' => ['image/heic', 'image/heif', 'application/octet-stream'],
    ];

    /**
     * @return array{ok:bool,path:string,width:?int,height:?int,error:string}
     *   error is a user-facing sentence, empty when ok. Never throws.
     */
    public function process(
        UploadedFile $file,
        string $destDir,
        string $filenamePrefix,
        int $maxDimension = 1600,
        int $webpQuality = 82
    ): array {
        if (! $file->isValid() || $file->hasMoved()) {
            // getErrorString() covers the PHP-level failures we cannot see
            // otherwise, most usefully UPLOAD_ERR_INI_SIZE.
            return $this->fail(sprintf(
                '“%s” did not upload correctly (%s).',
                $file->getClientName(),
                strtolower($file->getErrorString() ?: 'unknown error')
            ));
        }

        $name = $file->getClientName();

        // getClientExtension(), not getExtension(): the latter derives the
        // extension from the detected MIME and maps anything finfo cannot place
        // to 'bin' — which would reject a perfectly good AVIF or HEIC as
        // "unsupported" before it ever reached its own branch below. The
        // claimed extension decides the route; the MIME check right after is
        // what stops it being trusted, and GD is the final gate.
        $ext = strtolower($file->getClientExtension() ?: '');

        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return $this->fail(sprintf(
                '“%s” is not a supported image. Use JPEG, PNG, WebP, GIF, BMP or AVIF.',
                $name
            ));
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->fail(sprintf(
                '“%s” is %s — the limit is %s per image.',
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

        // HEIC is the iPhone default. Browsers transcode it to JPEG on the way
        // out (which is why the picker still offers it), so reaching here means
        // the conversion did not happen — say so rather than "not an image".
        if (in_array($ext, self::HEIC_EXT, true)) {
            return $this->fail(sprintf(
                '“%s” is an iPhone HEIC photo that reached us unconverted. Your browser normally '
                . 'converts these automatically — try again with JavaScript enabled, or set '
                . 'iPhone Camera → Formats → Most Compatible.',
                $name
            ));
        }

        if (! is_dir($destDir) && ! @mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
            log_message('error', "ListingImageProcessor: could not create {$destDir}");
            return $this->fail('The server could not store the image. Please try again later.');
        }

        if ($ext === 'svg') {
            return $this->storeSanitisedSvg($file, $destDir, $filenamePrefix);
        }

        if ($ext === 'gif') {
            // Checked here rather than beside the MAX_UPLOAD_BYTES gate above so
            // the two limits stay legible: that one is about what we accept,
            // this one is about what we keep.
            if ($file->getSize() > self::MAX_PASSTHROUGH_BYTES) {
                return $this->fail(sprintf(
                    '“%s” is %s. A GIF may be animated, so it is stored exactly as it '
                    . 'arrives rather than resized — the limit for GIFs is %s. Save it as '
                    . 'a JPEG or PNG and the limit is %s.',
                    $name,
                    $this->humanBytes((int) $file->getSize()),
                    $this->humanBytes(self::MAX_PASSTHROUGH_BYTES),
                    $this->humanBytes(self::MAX_UPLOAD_BYTES)
                ));
            }

            return $this->moveAsIs($file, $destDir, $filenamePrefix, $ext);
        }

        return $this->resizeToWebp($file, $destDir, $filenamePrefix, $maxDimension, $webpQuality);
    }

    /**
     * @return array{ok:bool,path:string,width:?int,height:?int,error:string}
     */
    private function moveAsIs(UploadedFile $file, string $destDir, string $prefix, string $ext): array
    {
        $name = $this->generateName($prefix, $ext);
        try {
            $file->move($destDir, $name);
        } catch (\Throwable $e) {
            log_message('error', 'ListingImageProcessor upload failed: ' . $e->getMessage());
            return $this->fail(sprintf('“%s” could not be saved. Please try again.', $file->getClientName()));
        }

        return $this->ok($this->relativePath($destDir, $name));
    }

    /**
     * No longer reachable: 'svg' was removed from ALLOWED_EXT, so uploads never
     * take this branch. Kept, with sanitizeSvg() below, because logos uploaded
     * before that change are still on disk and still served.
     *
     * Why SVG went: an SVG is an XML document, not a bitmap, so it is served
     * back as active same-origin content — navigate straight to the stored file
     * and any script in it runs on our domain. sanitizeSvg() is a regex
     * blacklist, and regex blacklists on a nesting, entity-encoding grammar
     * leak: `&#106;avascript:` never matches `#javascript\s*:#i` because the
     * browser decodes entities after our filter has run. Doing it properly
     * needs a real XML parser with an element/attribute allowlist. A logo
     * uploader does not justify that, so the format is simply not accepted —
     * PNG and WebP cover the same need and go through GD.
     *
     * @return array{ok:bool,path:string,width:?int,height:?int,error:string}
     */
    private function storeSanitisedSvg(UploadedFile $file, string $destDir, string $prefix): array
    {
        $raw = @file_get_contents($file->getTempName());
        if ($raw === false || stripos($raw, '<svg') === false) {
            return $this->fail(sprintf('“%s” is not a valid SVG file.', $file->getClientName()));
        }

        $clean = $this->sanitizeSvg($raw);
        $name  = $this->generateName($prefix, 'svg');

        if (@file_put_contents($destDir . '/' . $name, $clean) === false) {
            log_message('error', 'ListingImageProcessor: could not write sanitised SVG');
            return $this->fail('The server could not store the image. Please try again later.');
        }

        return $this->ok($this->relativePath($destDir, $name));
    }

    /**
     * Deliberately blunt: strip whole scriptable elements, every on* handler,
     * and any URL scheme that can execute. An SVG logo needs none of them.
     */
    public function sanitizeSvg(string $svg): string
    {
        $patterns = [
            // Scriptable or embedding elements, with or without a closing tag.
            '#<\s*(script|foreignObject|iframe|embed|object|handler|set|animate)\b[^>]*>.*?<\s*/\s*\1\s*>#is',
            '#<\s*(script|foreignObject|iframe|embed|object|handler|set|animate)\b[^>]*/?\s*>#is',
            // Inline event handlers: on…="…" / on…='…' / on…=bare
            '#\son[a-z-]+\s*=\s*"[^"]*"#is',
            "#\son[a-z-]+\s*=\s*'[^']*'#is",
            '#\son[a-z-]+\s*=\s*[^\s>]+#is',
            // Executable URL schemes in href/xlink:href/style, quoted or not.
            '#(?:xlink:)?href\s*=\s*"\s*(?:javascript|data:text/html)[^"]*"#is',
            "#(?:xlink:)?href\s*=\s*'\s*(?:javascript|data:text/html)[^']*'#is",
            '#javascript\s*:#i',
        ];

        return (string) preg_replace($patterns, '', $svg);
    }

    /**
     * @return array{ok:bool,path:string,width:?int,height:?int,error:string}
     */
    private function resizeToWebp(UploadedFile $file, string $destDir, string $prefix, int $maxDimension, int $webpQuality): array
    {
        if (! function_exists('imagewebp')) {
            log_message('error', 'ListingImageProcessor: GD has no WebP support');
            return $this->fail('The server cannot process images right now. Please try again later.');
        }

        $tmpPath  = $file->getTempName();
        $clientNm = $file->getClientName();
        $info     = @getimagesize($tmpPath);
        if ($info === false) {
            log_message('error', 'ListingImageProcessor: upload is not a valid image');
            return $this->fail(sprintf('“%s” could not be read as an image — the file may be corrupt.', $clientNm));
        }

        // Between the header read above and the full decode below — see
        // MAX_PIXELS. Everything past this line has the whole canvas in memory.
        $declaredPixels = (int) ($info[0] ?? 0) * (int) ($info[1] ?? 0);
        if ($declaredPixels > self::MAX_PIXELS || $declaredPixels <= 0) {
            log_message('error', sprintf(
                'ListingImageProcessor: refusing %dx%d image (%d px) from "%s"',
                $info[0] ?? 0,
                $info[1] ?? 0,
                $declaredPixels,
                $clientNm
            ));

            return $this->fail(sprintf(
                '“%s” has unusually large dimensions (%dx%d). Please resize it and try again.',
                $clientNm,
                $info[0] ?? 0,
                $info[1] ?? 0
            ));
        }

        $bytes  = (string) file_get_contents($tmpPath);
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            log_message('error', 'ListingImageProcessor: imagecreatefromstring failed');
            return $this->fail(sprintf('“%s” could not be read as an image — the file may be corrupt.', $clientNm));
        }
        // imagewebp() rejects palette (indexed-colour) images — a plain PNG
        // export from most graphics tools. No-op if already truecolor.
        imagepalettetotruecolor($source);

        // Re-encoding drops the EXIF orientation tag, so a portrait phone photo
        // would come out on its side. Apply the rotation to the pixels instead.
        $source = $this->applyExifOrientation($source, $bytes, $info[2] ?? 0);

        [$srcW, $srcH] = [imagesx($source), imagesy($source)];
        $longEdge      = max($srcW, $srcH);

        if ($longEdge > $maxDimension) {
            $scale = $maxDimension / $longEdge;
            $dstW  = max(1, (int) round($srcW * $scale));
            $dstH  = max(1, (int) round($srcH * $scale));

            $resized = imagecreatetruecolor($dstW, $dstH);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($source);
            $image = $resized;
            [$finalW, $finalH] = [$dstW, $dstH];
        } else {
            $image = $source;
            [$finalW, $finalH] = [$srcW, $srcH];
        }

        $name    = $this->generateName($prefix, 'webp');
        $target  = $destDir . '/' . $name;
        $written = @imagewebp($image, $target, $webpQuality);
        imagedestroy($image);

        // imagewebp() returns true while writing nothing at all — an oversized
        // canvas produces a truthy return and a 0-byte file, which is how two
        // broken photos ended up in the gallery. Trust the file, not the flag.
        if (! $written || ! is_file($target) || filesize($target) === 0) {
            if (is_file($target)) {
                @unlink($target);
            }
            log_message('error', 'ListingImageProcessor: imagewebp produced no output');
            return $this->fail(sprintf('“%s” could not be converted. Try saving it as a JPEG or PNG first.', $clientNm));
        }

        return $this->ok($this->relativePath($destDir, $name), $finalW, $finalH);
    }

    /**
     * @param \GdImage $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, string $bytes, int $imageType)
    {
        if ($imageType !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        // exif_read_data() needs a stream; we already hold the bytes, so wrap
        // them rather than re-reading the upload from disk.
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $degrees = match ($orientation) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function generateName(string $prefix, string $ext): string
    {
        return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    }

    private function relativePath(string $destDir, string $name): string
    {
        $fcpath = rtrim(FCPATH, '/');
        $rel    = ltrim(str_replace($fcpath, '', $destDir), '/');
        return $rel . '/' . $name;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /**
     * @return array{ok:bool,path:string,width:?int,height:?int,error:string}
     */
    private function ok(string $path, ?int $width = null, ?int $height = null): array
    {
        return ['ok' => true, 'path' => $path, 'width' => $width, 'height' => $height, 'error' => ''];
    }

    /**
     * @return array{ok:bool,path:string,width:?int,height:?int,error:string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'path' => '', 'width' => null, 'height' => null, 'error' => $error];
    }
}
