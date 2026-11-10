<?php

use App\Filters\AdminFilter;
use App\Libraries\PaymentReference;
use App\Services\SystemHandbookService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * /admin/documents/system, the system handbook.
 *
 * Pinned: the written half keeps up with the schema (every table has a
 * Config\Handbook entry and no entry names a table that is gone, so a new
 * migration cannot pass without one); every chapter has its view; every
 * payment reference prefix is explained; the live figures are right; only
 * an admin reaches the page; and no secret from the environment is printed.
 *
 * @internal
 */
final class SystemHandbookTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    /** @return list<string> */
    private function schemaTables(): array
    {
        $db = db_connect();

        return array_column($db->query(
            'SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$db->getDatabase(), 'BASE TABLE']
        )->getResultArray(), 'n');
    }

    public function testEveryTableIsDescribed(): void
    {
        $written = config('Handbook')->tables;
        $tables  = $this->schemaTables();

        $this->assertSame([], array_values(array_diff($tables, array_keys($written))), 'add these tables to Config\Handbook::$tables');
        $this->assertSame([], array_values(array_diff(array_keys($written), $tables)), 'these Config\Handbook::$tables entries name a table that no longer exists');

        foreach ($written as $name => $entry) {
            $this->assertNotSame('', trim($entry['purpose']), $name . ' says what it holds');
            $this->assertNotSame('', trim($entry['owner']), $name . ' names its writer');
            $this->assertContains($entry['product'], ['local', 'bookings'], $name);
            if (str_starts_with($name, 'xs_booking_')) {
                $this->assertSame('bookings', $entry['product'], $name . ' is a Bookings table');
            }
        }
    }

    public function testEveryChapterHasAView(): void
    {
        foreach (array_keys(config('Handbook')->chapters) as $id) {
            $this->assertFileExists(APPPATH . 'Views/admin/handbook/' . $id . '.php', 'chapter ' . $id);
        }
    }

    public function testEveryPaymentReferenceIsExplained(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/handbook/ids.php');
        foreach ((new ReflectionClass(PaymentReference::class))->getReflectionConstants() as $const) {
            if ($const->isPublic() && is_string($const->getValue())) {
                $this->assertStringContainsString("'" . $const->getName() . "'", $view, $const->getName() . ' needs an example in admin/handbook/ids.php');
            }
        }
    }

    public function testTheLiveFiguresAreRight(): void
    {
        $db = db_connect();
        $db->table('directory_categories')->insert(['name' => 'Handbook test', 'slug' => 'handbook-test']);
        $id = (int) $db->insertID();

        $tables = (new SystemHandbookService())->tables();
        $byName = array_column($tables, null, 'name');
        $cats   = $byName[$db->prefixTable('directory_categories')];

        $this->assertSame((int) $db->table('directory_categories')->countAllResults(), $cats['rows']);
        $this->assertSame($id, $cats['max_id']);
        $this->assertGreaterThan($id, $cats['next_id']);
        $this->assertSame('id', $cats['primary']);

        $this->assertSame('site', $byName[$db->prefixTable('directory_listings')]['scope']);
        $this->assertSame('business', $byName[$db->prefixTable('booking_appointments')]['scope']);
        $this->assertSame('profile', $byName[$db->prefixTable('directory_listing_photos')]['scope']);
    }

    public function testTheScheduleAndTokensAreRead(): void
    {
        $live = new SystemHandbookService();

        $commands = array_column($live->schedule(), 'command');
        $this->assertContains('spark discovery:learn', array_map(static fn ($c) => explode(' --', $c)[0], $commands));
        $this->assertSame('#003049', $live->tokens()['brand']['ocean'] ?? null);
    }

    public function testOnlyAnAdminSeesIt(): void
    {
        $this->get('admin/documents/system')->assertRedirect();
    }

    public function testThePageRendersEveryChapterAndNoSecret(): void
    {
        $result = $this->asAdmin()->get('admin/documents/system');
        $result->assertOK();
        $html = (string) $result->response()->getBody();

        foreach (config('Handbook')->chapters as $id => $title) {
            $this->assertStringContainsString('id="' . $id . '"', $html);
        }
        $this->assertStringNotContainsString('Not available right now', $html, 'every live section read');
        $this->assertStringContainsString('xs_directory_listings', $html);
        $this->assertStringContainsString('WSB-BOOK', $html);
        $this->assertStringNotContainsString('style="', $html, 'CSP: no inline styles');

        foreach (['database.default.password', 'database.tests.password', 'encryption.key', 'directory.paystackSecretKey', 'directory.adminPasswordHash', 'directory.mapboxToken'] as $key) {
            $value = (string) env($key, '');
            if (strlen($value) >= 6) {
                $this->assertStringNotContainsString($value, $html, $key . ' must never be printed');
            }
        }
    }

    public function testTheDocumentsListLinksIt(): void
    {
        $this->asAdmin()->get('admin/documents')->assertSee('System handbook');
    }
}
