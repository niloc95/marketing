<?php

use App\Libraries\VerificationDocumentProcessor;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The gate in front of private document storage.
 *
 * Everything this class accepts is written to disk untouched and later handed
 * to an admin's browser, so the tests that matter most are the rejections — and
 * in particular the one that proves bytes are preserved exactly, which is the
 * property that separates this class from ListingImageProcessor and the reason
 * it exists at all.
 *
 * @internal
 */
final class VerificationDocumentProcessorTest extends CIUnitTestCase
{
    private string $tmp;
    private string $dest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp  = sys_get_temp_dir() . '/verifdoc-src-' . bin2hex(random_bytes(4));
        $this->dest = sys_get_temp_dir() . '/verifdoc-dst-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmp, $this->dest] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    /** A real, if minimal, PDF. */
    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** A real 1x1 PNG, built rather than pasted so the CRCs are correct. */
    private function pngBytes(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress("\x00\xFF\xFF\xFF"))
            . $chunk('IEND', '');
    }

    private function jpegBytes(): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 32) . "\xFF\xD9";
    }

    /**
     * The same double ListingImageProcessorTest uses, for the same reasons:
     * isValid() requires is_uploaded_file(), which is false for anything not
     * arriving over HTTP, and getMimeType() would run finfo over the real bytes
     * rather than the declared type a caller wants to test.
     *
     * move() is overridden here as well — the parent calls move_uploaded_file(),
     * which likewise only works on a genuine upload. Renaming keeps the rest of
     * the assertions (bytes preserved, permissions, generated name) honest,
     * because they run against a file that really was written to the
     * destination.
     */
    private function upload(string $name, string $contents, string $mime): UploadedFile
    {
        $path = $this->tmp . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);

        $file = new class ($path, $name, $mime, strlen($contents), UPLOAD_ERR_OK) extends UploadedFile {
            public string $fakeMime = '';

            public function isValid(): bool
            {
                return true;
            }

            public function getMimeType(): string
            {
                return $this->fakeMime;
            }

            public function move(?string $targetPath = null, ?string $name = null, bool $overwrite = false)
            {
                $target = rtrim((string) $targetPath, '/') . '/' . ($name ?? $this->getName());

                if (! @rename($this->getTempName(), $target)) {
                    throw new RuntimeException('could not move the test upload into place');
                }

                $this->hasMoved = true;

                return $this;
            }
        };
        $file->fakeMime = $mime;

        return $file;
    }

    // -------------------------------------------------------------- acceptance

    public function testAcceptsPdfJpegAndPng(): void
    {
        $cases = [
            ['reg.pdf', $this->pdfBytes(), 'application/pdf', 'application/pdf'],
            ['id.png', $this->pngBytes(), 'image/png', 'image/png'],
            ['id.jpg', $this->jpegBytes(), 'image/jpeg', 'image/jpeg'],
        ];

        foreach ($cases as [$name, $bytes, $mime, $expectedMime]) {
            $result = (new VerificationDocumentProcessor())->process($this->upload($name, $bytes, $mime), $this->dest);

            $this->assertTrue($result['ok'], $name . ' should be accepted: ' . $result['error']);
            $this->assertSame($expectedMime, $result['mime']);
            $this->assertSame(strlen($bytes), $result['bytes']);
            $this->assertFileExists($this->dest . '/' . $result['name']);
        }
    }

    /**
     * The whole reason this class is not ListingImageProcessor. A document is
     * evidence; if we re-encoded it, "here is what they sent us" would stop
     * being true.
     */
    public function testStoredBytesAreIdenticalToTheUpload(): void
    {
        $bytes  = $this->pdfBytes();
        $result = (new VerificationDocumentProcessor())->process($this->upload('reg.pdf', $bytes, 'application/pdf'), $this->dest);

        $this->assertTrue($result['ok']);
        $this->assertSame($bytes, file_get_contents($this->dest . '/' . $result['name']));
    }

    /** An image must survive intact too — no WebP conversion, no resizing. */
    public function testImagesAreNotReEncoded(): void
    {
        $bytes  = $this->pngBytes();
        $result = (new VerificationDocumentProcessor())->process($this->upload('id.png', $bytes, 'image/png'), $this->dest);

        $this->assertTrue($result['ok']);
        $this->assertSame($bytes, file_get_contents($this->dest . '/' . $result['name']));
        $this->assertStringEndsWith('.png', $result['name'], 'the extension must not change either');
    }

    /**
     * The digest is what turns "a copy of their document" into "provably their
     * document" if the verification is ever questioned. Taken from the file as
     * stored, not from the upload, because the stored bytes are the ones we will
     * still have to answer for.
     */
    public function testReturnsTheSha256OfTheStoredFile(): void
    {
        $bytes  = $this->pdfBytes();
        $result = (new VerificationDocumentProcessor())->process($this->upload('reg.pdf', $bytes, 'application/pdf'), $this->dest);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(hash('sha256', $bytes), $result['sha256']);
        $this->assertSame(
            hash_file('sha256', $this->dest . '/' . $result['name']),
            $result['sha256'],
            'the digest must describe the file on disk'
        );
    }

    /**
     * Callers read $result['sha256'] unconditionally, so a rejection has to
     * carry the key too — the alternative is an undefined-index warning on the
     * one path that is already going badly.
     */
    public function testARejectionCarriesTheSameKeysAsASuccess(): void
    {
        $ok       = (new VerificationDocumentProcessor())->process($this->upload('reg.pdf', $this->pdfBytes(), 'application/pdf'), $this->dest);
        $rejected = (new VerificationDocumentProcessor())->process($this->upload('note.txt', 'plain', 'text/plain'), $this->dest);

        $this->assertFalse($rejected['ok']);
        $this->assertSame(array_keys($ok), array_keys($rejected));
        $this->assertSame('', $rejected['sha256']);
    }

    // --------------------------------------------------------------- rejection

    public function testRejectsExtensionsOutsideTheAllowList(): void
    {
        foreach ([
            ['payload.exe', 'application/octet-stream'],
            ['logo.svg', 'image/svg+xml'],
            ['company.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['docs.zip', 'application/zip'],
            ['photo.heic', 'image/heic'],
            ['photo.webp', 'image/webp'],
        ] as [$name, $mime]) {
            $result = (new VerificationDocumentProcessor())->process($this->upload($name, $this->pdfBytes(), $mime), $this->dest);

            $this->assertFalse($result['ok'], $name . ' must be refused');
            $this->assertStringContainsString('not a supported document', $result['error']);
        }
    }

    /**
     * The check finfo cannot make for us. A file whose extension and declared
     * MIME both say PDF, but whose bytes are something else, is exactly the
     * shape of an upload designed to be mis-rendered later.
     */
    public function testRejectsAFileWhoseBytesContradictItsExtension(): void
    {
        $result = (new VerificationDocumentProcessor())->process(
            // PNG bytes, but everything else claims PDF.
            $this->upload('registration.pdf', $this->pngBytes(), 'application/pdf'),
            $this->dest
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('contents are something else', $result['error']);
    }

    public function testRejectsHtmlDisguisedAsAnImage(): void
    {
        $result = (new VerificationDocumentProcessor())->process(
            $this->upload('id.png', '<html><script>alert(1)</script></html>', 'image/png'),
            $this->dest
        );

        $this->assertFalse($result['ok'], 'HTML claiming to be a PNG must never reach storage');
    }

    public function testRejectsAMimeThatDoesNotMatchTheExtension(): void
    {
        $result = (new VerificationDocumentProcessor())->process(
            $this->upload('id.png', $this->pngBytes(), 'text/html'),
            $this->dest
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not look like a real PNG', $result['error']);
    }

    public function testRejectsFilesOverTheSizeCap(): void
    {
        $big = $this->pdfBytes() . str_repeat('A', VerificationDocumentProcessor::MAX_UPLOAD_BYTES);

        $result = (new VerificationDocumentProcessor())->process($this->upload('reg.pdf', $big, 'application/pdf'), $this->dest);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('the limit is', $result['error']);
    }

    public function testEveryRejectionExplainsItself(): void
    {
        $result = (new VerificationDocumentProcessor())->process($this->upload('x.exe', 'MZ', 'application/octet-stream'), $this->dest);

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['error'], 'a silently dropped document leaves the owner waiting forever');
        $this->assertSame('', $result['name']);
    }

    // ---------------------------------------------------------------- storage

    /**
     * The stored name never derives from what the uploader called the file, so
     * a hostile filename cannot reach the filesystem or, later, a header.
     */
    public function testGeneratedNamesAreRandomAndIgnoreTheClientFilename(): void
    {
        $processor = new VerificationDocumentProcessor();

        $first  = $processor->process($this->upload('../../etc/passwd.pdf', $this->pdfBytes(), 'application/pdf'), $this->dest);
        $second = $processor->process($this->upload('../../etc/passwd.pdf', $this->pdfBytes(), 'application/pdf'), $this->dest);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertNotSame($first['name'], $second['name'], 'two uploads must not collide');

        foreach ([$first['name'], $second['name']] as $name) {
            $this->assertMatchesRegularExpression('/^verif_\d{8}_\d{6}_[0-9a-f]{16}\.pdf$/', $name);
            $this->assertStringNotContainsString('passwd', $name);
            $this->assertStringNotContainsString('/', $name);
            $this->assertStringNotContainsString('..', $name);
        }
    }

    public function testStoredFilesAreNotWorldReadable(): void
    {
        $result = (new VerificationDocumentProcessor())->process($this->upload('reg.pdf', $this->pdfBytes(), 'application/pdf'), $this->dest);

        $this->assertTrue($result['ok']);

        // 0600: these are ID copies on what may well be shared hosting.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dest . '/' . $result['name'])), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dest)), -4), 'the directory must not be listable either');
    }
}
