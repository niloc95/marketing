<?php

use App\Libraries\ListingImageProcessor;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Upload handling for listing logos and gallery photos.
 *
 * The rule running through these: nothing is ever rejected in silence. The
 * gallery appeared broken for months because a 3–6 MB phone photo tripped the
 * old 2 MiB cap, returned null, and the form still said "saved". Every branch
 * here therefore asserts on the explanation as much as on the outcome.
 *
 * @internal
 */
final class ListingImageProcessorTest extends CIUnitTestCase
{
    private string $tmpDir;
    private string $destDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir  = sys_get_temp_dir() . '/lip-src-' . bin2hex(random_bytes(4));
        $this->destDir = sys_get_temp_dir() . '/lip-dest-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->destDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmpDir, $this->destDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /**
     * UploadedFile::isValid() requires is_uploaded_file(), which is false for
     * anything not arriving over HTTP, and getMimeType() runs finfo over the
     * real bytes. The double below pins both so each branch can be reached;
     * everything else — getClientExtension(), getSize(), getTempName() — is the
     * framework's own behaviour.
     */
    private function upload(string $contents, string $clientName, string $mime): UploadedFile
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);

        // UploadedFileInterface pins the constructor signature, so the pretend
        // MIME is set afterwards rather than passed in.
        $file = new class ($path, $clientName, $mime, strlen($contents), UPLOAD_ERR_OK) extends UploadedFile {
            public string $fakeMime = '';

            public function isValid(): bool
            {
                return true;
            }

            public function getMimeType(): string
            {
                return $this->fakeMime;
            }
        };
        $file->fakeMime = $mime;

        return $file;
    }

    /**
     * Noise, not a flat fill: a solid-colour PNG of any size compresses to a
     * few KB, which would make the size-limit fixtures meaningless.
     */
    private function pngBytes(int $width, int $height, bool $noisy = false): string
    {
        $im = imagecreatetruecolor($width, $height);

        if ($noisy) {
            for ($x = 0; $x < $width; $x += 2) {
                for ($y = 0; $y < $height; $y += 2) {
                    imagesetpixel($im, $x, $y, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
                }
            }
        } else {
            imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        }

        ob_start();
        imagepng($im, null, 0);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function process(UploadedFile $file, int $maxDimension = 1600): array
    {
        return (new ListingImageProcessor())->process($file, $this->destDir, 'test', $maxDimension);
    }

    public function testNormalisesAPngToWebpAndReportsDimensions(): void
    {
        $result = $this->process($this->upload($this->pngBytes(120, 60), 'logo.png', 'image/png'));

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame('', $result['error']);
        $this->assertStringEndsWith('.webp', $result['path']);
        $this->assertSame(120, $result['width']);
        $this->assertSame(60, $result['height']);

        $written = $this->destDir . '/' . basename($result['path']);
        $this->assertFileExists($written);
        $this->assertGreaterThan(0, filesize($written));
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($written)[2]);
    }

    /**
     * The hero rotation writes two renditions of one upload — 1600 for the page
     * and 800 for phones — by calling process() twice on the same UploadedFile.
     * That only works because the resize path reads the temp file with
     * file_get_contents and never moves it. Nothing in process() advertises
     * that, so a refactor to $file->move() would break the hero's srcset
     * silently: the first pass would still succeed and only the small file
     * would quietly stop appearing.
     *
     * See App\Services\HeroImageService::processUpload().
     */
    public function testTheSameUploadCanBeProcessedTwiceAtDifferentSizes(): void
    {
        $file = $this->upload($this->pngBytes(1600, 900), 'hero.png', 'image/png');

        $large = $this->process($file, 1600);
        $small = $this->process($file, 800);

        $this->assertTrue($large['ok'], $large['error']);
        $this->assertTrue($small['ok'], $small['error']);
        $this->assertSame([1600, 900], [$large['width'], $large['height']]);
        $this->assertSame([800, 450], [$small['width'], $small['height']]);

        // Two separate files, both real — the second pass must not overwrite or
        // consume the first.
        $this->assertNotSame($large['path'], $small['path']);
        foreach ([$large, $small] as $result) {
            $written = $this->destDir . '/' . basename($result['path']);
            $this->assertFileExists($written);
            $this->assertGreaterThan(0, filesize($written));
        }
    }

    public function testResizesDownToTheLongEdgeLimit(): void
    {
        $result = $this->process($this->upload($this->pngBytes(400, 200), 'big.png', 'image/png'), 100);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(100, $result['width']);
        $this->assertSame(50, $result['height']);
    }

    public function testRejectsFilesOverTheSizeLimitAndSaysSo(): void
    {
        $oversized = str_repeat('x', ListingImageProcessor::MAX_UPLOAD_BYTES + 1);
        $result    = $this->process($this->upload($oversized, 'huge.jpg', 'image/jpeg'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('huge.jpg', $result['error']);
        $this->assertStringContainsString('the limit is', $result['error']);
        $this->assertStringContainsString('10 MB', $result['error']);
    }

    public function testAcceptsPhotosThatWouldHaveFailedTheOldTwoMegabyteCap(): void
    {
        // The exact regression: a typical phone photo, comfortably over the old
        // 2 MiB limit and comfortably under the new one.
        $this->assertGreaterThan(2_097_152, ListingImageProcessor::MAX_UPLOAD_BYTES);

        $file = $this->upload($this->pngBytes(2000, 1500, true), 'phone-photo.png', 'image/png');
        $this->assertGreaterThan(2_097_152, $file->getSize(), 'fixture must exceed the old cap');
        $this->assertLessThan(ListingImageProcessor::MAX_UPLOAD_BYTES, $file->getSize(), 'fixture must fit the new one');

        $result = $this->process($file);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(1600, $result['width']);
        $this->assertSame(1200, $result['height']);
    }

    public function testRejectsAnUnsupportedExtension(): void
    {
        $result = $this->process($this->upload('II*', 'scan.tiff', 'image/tiff'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a supported image', $result['error']);
    }

    public function testRejectsAFileWhoseContentContradictsItsExtension(): void
    {
        // A .txt renamed to .jpg: the extension gate passes, the MIME gate does not.
        $result = $this->process($this->upload('just some text', 'sneaky.jpg', 'text/plain'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not look like a real JPG file', $result['error']);
    }

    public function testRejectsCorruptImageDataWithItsOwnMessage(): void
    {
        $result = $this->process($this->upload('not a png at all', 'broken.png', 'image/png'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not be read as an image', $result['error']);
    }

    public function testHeicGetsAnExplanationRatherThanAGenericFailure(): void
    {
        $result = $this->process($this->upload('ftypheic', 'IMG_0042.heic', 'image/heic'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HEIC', $result['error']);
        $this->assertStringContainsString('Most Compatible', $result['error']);
    }

    public function testEncodeFailureLeavesNoZeroByteFileBehind(): void
    {
        // WebP caps a dimension at 16383px. Past that, imagewebp() returns true
        // and writes nothing — which is how two 0-byte photos reached the live
        // gallery and rendered as broken images.
        $result = $this->process($this->upload($this->pngBytes(16500, 8), 'wide.png', 'image/png'), 20000);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not be converted', $result['error']);
        $this->assertSame([], glob($this->destDir . '/*') ?: []);
    }

    public function testStripsScriptableContentFromSvg(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" onload="steal()">'
            . '<script>alert(document.cookie)</script>'
            . '<a xlink:href="javascript:alert(1)"><rect width="10" height="10"/></a>'
            . '</svg>';

        $clean = (new ListingImageProcessor())->sanitizeSvg($dirty);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        // The drawing itself must survive — this is a logo, not a threat.
        $this->assertStringContainsString('<rect width="10" height="10"/>', $clean);
    }

    public function testRotatesAJpegAccordingToItsExifOrientation(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif not installed');
        }

        // 40x20 landscape, tagged orientation 6 ("rotate 90° clockwise"), so a
        // correct read produces a 20x40 portrait.
        $file   = $this->upload($this->jpegWithOrientation(40, 20, 6), 'portrait.jpg', 'image/jpeg');
        $result = $this->process($file);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(20, $result['width']);
        $this->assertSame(40, $result['height']);
    }

    /**
     * Hand-build a minimal EXIF APP1 segment; ext-exif can read but not write.
     */
    private function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagejpeg($im, null, 90);
        imagedestroy($im);
        $jpeg = (string) ob_get_clean();

        // One little-endian IFD0 entry: tag 0x0112 (Orientation), SHORT, count 1.
        $tiff = "II\x2a\x00\x08\x00\x00\x00"
            . "\x01\x00"
            . "\x12\x01\x03\x00\x01\x00\x00\x00" . pack('v', $orientation) . "\x00\x00"
            . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        $seg  = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;

        // Insert directly after SOI so it precedes whatever GD emitted.
        return substr($jpeg, 0, 2) . $seg . substr($jpeg, 2);
    }
}
