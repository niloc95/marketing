<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Shared upload handling for listing logos and gallery photos: validates,
 * resizes, and re-encodes to WebP. Used in place of the ad-hoc, duplicated
 * resolveLogo() that used to live separately in Listing.php and Manage.php.
 *
 * GD only (no Imagick dependency). SVG and GIF pass through unresized: SVG is
 * vector (nothing to raster-resize), and GIF may be animated (re-encoding via
 * GD would silently flatten it to a single frame).
 */
class ListingImageProcessor
{
    public const ALLOWED_EXT      = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];
    public const MAX_UPLOAD_BYTES = 2_097_152;

    /**
     * @return array{path:string,width:?int,height:?int}|null null on any
     *   validation or GD failure (logged, never thrown).
     */
    public function process(
        UploadedFile $file,
        string $destDir,
        string $filenamePrefix,
        int $maxDimension = 1600,
        int $webpQuality = 82
    ): ?array {
        if (! $file->isValid() || $file->hasMoved()) {
            return null;
        }

        $ext = strtolower($file->getExtension() ?: '');
        if (! in_array($ext, self::ALLOWED_EXT, true) || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return null;
        }

        if (! is_dir($destDir) && ! @mkdir($destDir, 0755, true) && ! is_dir($destDir)) {
            log_message('error', "ListingImageProcessor: could not create {$destDir}");
            return null;
        }

        if (in_array($ext, ['svg', 'gif'], true)) {
            return $this->moveAsIs($file, $destDir, $filenamePrefix, $ext);
        }

        return $this->resizeToWebp($file, $destDir, $filenamePrefix, $maxDimension, $webpQuality);
    }

    /**
     * @return array{path:string,width:?int,height:?int}|null
     */
    private function moveAsIs(UploadedFile $file, string $destDir, string $prefix, string $ext): ?array
    {
        $name = $this->generateName($prefix, $ext);
        try {
            $file->move($destDir, $name);
        } catch (\Throwable $e) {
            log_message('error', 'ListingImageProcessor upload failed: ' . $e->getMessage());
            return null;
        }

        return ['path' => $this->relativePath($destDir, $name), 'width' => null, 'height' => null];
    }

    /**
     * @return array{path:string,width:?int,height:?int}|null
     */
    private function resizeToWebp(UploadedFile $file, string $destDir, string $prefix, int $maxDimension, int $webpQuality): ?array
    {
        $tmpPath = $file->getTempName();
        $info    = @getimagesize($tmpPath);
        if ($info === false) {
            log_message('error', 'ListingImageProcessor: upload is not a valid image');
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($tmpPath));
        if ($source === false) {
            log_message('error', 'ListingImageProcessor: imagecreatefromstring failed');
            return null;
        }
        // imagewebp() rejects palette (indexed-colour) images — a plain PNG
        // export from most graphics tools. No-op if already truecolor.
        imagepalettetotruecolor($source);

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
        $written = imagewebp($image, $destDir . '/' . $name, $webpQuality);
        imagedestroy($image);

        if (! $written) {
            log_message('error', 'ListingImageProcessor: imagewebp failed');
            return null;
        }

        return ['path' => $this->relativePath($destDir, $name), 'width' => $finalW, 'height' => $finalH];
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
}
