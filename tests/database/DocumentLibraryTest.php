<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * The document library (Config\Documents).
 *
 * Pinned: every published entry's file is a PDF in public/assets/documents/
 * and nowhere else; the old /assets/playbook/ and /assets/proposal/ URLs 301
 * to the library, but only for a file the library publishes; and unlisted
 * documents stay off /resources.
 *
 * @internal
 */
final class DocumentLibraryTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    public function testEveryPublishedDocumentIsAPdfInTheLibraryFolder(): void
    {
        $library = config('Documents');
        $this->assertNotEmpty($library->published);

        foreach ($library->published as $key => $doc) {
            $this->assertSame(basename($doc['file']), $doc['file'], $key . ' names a file, not a path');
            $path = $library->publishedPath($key);
            $this->assertNotNull($path, $key . ' has a file');
            $this->assertSame(FCPATH . 'assets/documents/' . $doc['file'], $path);
            $this->assertSame('%PDF', (string) file_get_contents($path, false, null, 0, 4), $key . ' is a PDF');
        }
    }

    public function testNoPublicPdfLivesOutsideTheLibrary(): void
    {
        $library = config('Documents');
        $files   = array_column($library->published, 'file');
        $stray   = [];
        $it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FCPATH . 'assets', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (strtolower($file->getExtension()) !== 'pdf') {
                continue;
            }
            if (dirname($file->getPathname()) !== rtrim(FCPATH . 'assets/documents', '/') || ! in_array($file->getFilename(), $files, true)) {
                $stray[] = substr($file->getPathname(), strlen(FCPATH));
            }
        }

        $this->assertSame([], $stray, 'every public PDF is in public/assets/documents/ and registered');
    }

    public function testOldUrlsRedirectToTheLibrary(): void
    {
        $this->get('assets/playbook/webscheduler-local-visibility-playbook.pdf')
            ->assertRedirectTo(base_url('assets/documents/webscheduler-local-visibility-playbook.pdf'));
        $result = $this->get('assets/proposal/webscheduler-local-proposal.pdf');
        $result->assertStatus(301);
        $result->assertRedirectTo(base_url('assets/documents/webscheduler-local-proposal.pdf'));
    }

    public function testAnUnknownOldUrlIs404(): void
    {
        $this->get('assets/playbook/nope.pdf')->assertStatus(404);
        $this->get('assets/proposal/..%2F..%2F.env')->assertStatus(404);
    }

    public function testUnlistedDocumentsStayOffTheResourcesPage(): void
    {
        $html = (string) $this->get('resources')->response()->getBody();

        foreach (config('Documents')->published as $key => $doc) {
            if ($doc['listed']) {
                $this->assertStringContainsString($doc['file'], $html, $key . ' is listed');
            } else {
                $this->assertStringNotContainsString($doc['file'], $html, $key . ' is not listed');
            }
        }
    }
}
