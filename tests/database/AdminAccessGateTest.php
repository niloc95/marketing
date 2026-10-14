<?php

use App\Libraries\CloudflareAccess;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The admin gate as wired: CloudflareAccessFilter on every /admin* route, and
 * the site-wide cap on failed logins. The token rules themselves are pinned in
 * tests/unit/CloudflareAccessTest.php.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminAccessGateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private const TEAM = 'webscheduler.cloudflareaccess.com';
    private const AUD  = 'test-aud-tag';

    /** @var OpenSSLAsymmetricKey */
    private $key;

    protected function setUp(): void
    {
        parent::setUp();

        // Not what is under test; same reasoning as AdminSessionTest.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);

        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pem       = openssl_pkey_get_details($this->key)['key'];
        cache()->delete('cf_access_certs');
        cache()->delete('cf_access_certs_recent');
        cache()->delete('admin_login_failures');

        Services::injectMock('cloudflareAccess', new CloudflareAccess(self::TEAM, self::AUD, static fn (): array => ['k1' => $pem]));
    }

    protected function tearDown(): void
    {
        // CIUnitTestCase does not clear mocks; see the Mautic note in the skill.
        Services::resetSingle('cloudflareAccess');
        cache()->delete('cf_access_certs');
        cache()->delete('admin_login_failures');
        parent::tearDown();
    }

    public function testNoTokenNoAdmin(): void
    {
        $this->get('admin/login')->assertStatus(403);
        $this->withSession(['dir_admin' => true])->get('admin')->assertStatus(403);
        $this->post('admin/login', ['password' => 'x'])->assertStatus(403);
    }

    public function testTheHeadCopiesAreGatedToo(): void
    {
        $this->call('head', 'admin/login')->assertStatus(403);
    }

    public function testAGenuineTokenIsLetThrough(): void
    {
        $this->withHeaders([CloudflareAccess::HEADER => $this->token()])->get('admin/login')->assertOK();
    }

    public function testAForgedHeaderIsNot(): void
    {
        $this->withHeaders([CloudflareAccess::HEADER => 'eyJhbGciOiJub25lIn0.e30.'])->get('admin/login')->assertStatus(403);
    }

    public function testWithTheSettingsUnsetNothingChanges(): void
    {
        Services::injectMock('cloudflareAccess', new CloudflareAccess('', ''));

        $this->get('admin/login')->assertOK();
    }

    public function testPublicPagesNeverAskForAToken(): void
    {
        $this->get('faq')->assertOK();
    }

    public function testFailedLoginsSiteWideLockTheForm(): void
    {
        Services::injectMock('cloudflareAccess', new CloudflareAccess('', ''));

        $this->post('admin/login', ['password' => 'wrong'])->assertRedirectTo(base_url('admin/login'));
        $this->assertSame(1, (int) cache('admin_login_failures'));

        cache()->save('admin_login_failures', 30, HOUR);
        $this->post('admin/login', ['password' => 'wrong'])->assertRedirectTo(base_url('admin/login'));
        $this->assertSame('Too many attempts. Please wait a few minutes.', session()->getFlashdata('error'));
    }

    private function token(): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $h   = $b64(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
        $p   = $b64(json_encode(['aud' => [self::AUD], 'iss' => 'https://' . self::TEAM, 'exp' => time() + 600]));
        openssl_sign($h . '.' . $p, $sig, $this->key, OPENSSL_ALGO_SHA256);

        return $h . '.' . $p . '.' . $b64($sig);
    }
}
