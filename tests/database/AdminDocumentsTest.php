<?php

use App\Filters\AdminFilter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * /admin/documents: internal PDFs with no public URL.
 *
 * Pinned: only a signed in admin reaches them; a document is looked up by
 * registry key, so an unknown key or a path is a 404; every registered file
 * exists and is a PDF; and none of them sits under public/.
 *
 * @internal
 */
final class AdminDocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    public function testEveryRegisteredDocumentExistsAndIsAPdfOutsidePublic(): void
    {
        $config = config('AdminDocuments');
        $this->assertNotEmpty($config->documents);

        foreach (array_keys($config->documents) as $key) {
            $path = $config->path($key);
            $this->assertNotNull($path, $key . ' has a file');
            $this->assertSame('%PDF', substr((string) file_get_contents($path, false, null, 0, 4), 0, 4), $key . ' is a PDF');
            $this->assertStringNotContainsString(DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR, $path, $key . ' is not web reachable');
        }
    }

    public function testTheListNeedsAnAdmin(): void
    {
        $this->get('admin/documents')->assertRedirect();
        $this->get('admin/documents/partner-program-guide')->assertRedirect();
    }

    public function testAnAdminSeesTheListAndOpensADocument(): void
    {
        $list = $this->asAdmin()->get('admin/documents');
        $list->assertOK();
        $list->assertSee('How the Partner Program works');

        $result = $this->asAdmin()->get('admin/documents/partner-program-guide');
        $result->assertOK();
        $result->assertHeader('Content-Type', 'application/pdf');
        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $result->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $result->response()->getBody());
    }

    public function testDownloadAsksTheBrowserToSave(): void
    {
        $_GET['download'] = '1';
        $result           = $this->asAdmin()->get('admin/documents/partner-program-guide?download=1');
        unset($_GET['download']);

        $this->assertStringStartsWith('attachment;', $result->response()->getHeaderLine('Content-Disposition'));
    }

    public function testAnUnknownKeyOrAPathIs404(): void
    {
        $this->asAdmin()->get('admin/documents/nope')->assertStatus(404);
        $this->asAdmin()->get('admin/documents/..%2F..%2F.env')->assertStatus(404);
        $this->assertNull(config('AdminDocuments')->path('../../.env'));
    }
}
