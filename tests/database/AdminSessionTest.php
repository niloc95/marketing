<?php

use App\Filters\AdminFilter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Admin sign-out and idle timeout.
 *
 * Sign-out is POST-only, so no third-party page can fire it, and it destroys
 * the session rather than dropping one key. An admin session untouched for
 * AdminFilter::IDLE_SECONDS is ended on its next request.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminSessionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Not what is under test, and it cannot be satisfied from here — the
        // token is cookie-bound. Same reasoning as PhotoDeleteAjaxTest.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    public function testSignOutIsNotReachableByGet(): void
    {
        // A 404 is a response now (Controllers\Errors, the 404 override), not
        // an exception the test catches.
        $this->withSession(['dir_admin' => true])->get('admin/logout')->assertStatus(404);
    }

    public function testSignOutByPostEndsTheSession(): void
    {
        $result = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])
            ->post('admin/logout');

        $result->assertRedirectTo(base_url('admin/login?signed_out=1'));
        $this->assertNull(session('dir_admin'));
    }

    public function testAnIdleSessionIsSignedOut(): void
    {
        $stale = time() - AdminFilter::IDLE_SECONDS - 60;

        $result = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => $stale])
            ->get('admin');

        $result->assertRedirectTo(base_url('admin/login?expired=1'));
        $this->assertNull(session('dir_admin'));
    }

    public function testAnActiveSessionIsKeptAndItsClockRefreshed(): void
    {
        $recent = time() - 60;

        $result = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => $recent])
            ->get('admin');

        $result->assertOK();
        $this->assertGreaterThan($recent, (int) session(AdminFilter::SEEN_KEY));
    }
}
