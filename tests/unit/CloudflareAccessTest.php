<?php

use App\Filters\FrontControllerPath;
use App\Libraries\CloudflareAccess;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The two halves of the admin gate added after /index.php/admin/login got
 * past Cloudflare Access (14 Oct 2026).
 *
 * CloudflareAccess must accept only an RS256 token signed by the team's key,
 * for our AUD and issuer, inside its lifetime. Each refusal below is a real
 * way JWT checks have been bypassed. FrontControllerPath must 404 the admin,
 * POST and oddly spelled /index.php/{path} forms, 301 every other GET to the
 * clean address on our own host, and leave everything else alone.
 *
 * @internal
 */
final class CloudflareAccessTest extends CIUnitTestCase
{
    private const TEAM = 'webscheduler.cloudflareaccess.com';
    private const AUD  = 'test-aud-tag';
    private const KID  = 'kid-1';

    /** @var OpenSSLAsymmetricKey */
    private static $private;

    private static string $publicPem;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$private   = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$publicPem = openssl_pkey_get_details(self::$private)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // The keys are cached; a previous test's fetcher must not answer this one's.
        cache()->delete('cf_access_certs');
        cache()->delete('cf_access_certs_recent');
    }

    private function access(?Closure $fetch = null): CloudflareAccess
    {
        return new CloudflareAccess(self::TEAM, self::AUD, $fetch ?? static fn (): array => [self::KID => self::$publicPem]);
    }

    /** @param array<string,mixed> $claims */
    private static function token(array $claims = [], array $header = [], $key = null): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $h = $b64(json_encode($header + ['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT']));
        $p = $b64(json_encode($claims + [
            'aud' => [self::AUD],
            'iss' => 'https://' . self::TEAM,
            'exp' => time() + 600,
            'nbf' => time() - 10,
        ]));
        openssl_sign($h . '.' . $p, $sig, $key ?? self::$private, OPENSSL_ALGO_SHA256);

        return $h . '.' . $p . '.' . $b64($sig);
    }

    // --------------------------------------------------------------- tokens

    public function testAGenuineTokenIsAccepted(): void
    {
        $this->assertTrue($this->access()->verify(self::token()));
    }

    public function testOffUnlessBothSettingsAreSet(): void
    {
        $this->assertFalse((new CloudflareAccess('', self::AUD))->enabled());
        $this->assertFalse((new CloudflareAccess(self::TEAM, ''))->enabled());
        $this->assertTrue($this->access()->enabled());
    }

    public function testAnotherApplicationsTokenIsRefused(): void
    {
        $this->assertFalse($this->access()->verify(self::token(['aud' => ['some-other-app']])));
    }

    public function testAnotherTeamsTokenIsRefused(): void
    {
        $this->assertFalse($this->access()->verify(self::token(['iss' => 'https://evil.cloudflareaccess.com'])));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->assertFalse($this->access()->verify(self::token(['exp' => time() - 3600])));
    }

    public function testATokenNotYetValidIsRefused(): void
    {
        $this->assertFalse($this->access()->verify(self::token(['nbf' => time() + 3600])));
    }

    public function testATokenSignedWithAnotherKeyIsRefused(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $this->assertFalse($this->access()->verify(self::token([], [], $other)));
    }

    public function testAlgNoneAndHmacAreRefused(): void
    {
        $this->assertFalse($this->access()->verify(self::token([], ['alg' => 'none'])));
        $this->assertFalse($this->access()->verify(self::token([], ['alg' => 'HS256'])));
    }

    public function testATamperedPayloadIsRefused(): void
    {
        [$h, , $s] = explode('.', self::token());
        $forged    = rtrim(strtr(base64_encode(json_encode([
            'aud' => [self::AUD], 'iss' => 'https://' . self::TEAM, 'exp' => time() + 99999,
        ])), '+/', '-_'), '=');

        $this->assertFalse($this->access()->verify($h . '.' . $forged . '.' . $s));
    }

    public function testGarbageIsRefused(): void
    {
        foreach (['', 'abc', 'a.b', 'a.b.c', '...'] as $junk) {
            $this->assertFalse($this->access()->verify($junk), var_export($junk, true));
        }
    }

    public function testNoKeysMeansNobodyGetsIn(): void
    {
        $down = $this->access(static fn (): array => throw new RuntimeException('Cloudflare unreachable'));

        $this->assertFalse($down->verify(self::token()));
    }

    // ---------------------------------------------------------- the path

    /** @return iterable<string,array{string}> */
    public static function frontControllerPaths(): iterable
    {
        yield 'plain'        => ['/index.php/admin/login'];
        yield 'capitals'     => ['/INDEX.PHP/admin/login'];
        yield 'double slash' => ['//index.php/admin'];
        yield 'encoded dot'  => ['/index%2Ephp/admin/login'];
        yield 'admin prefix' => ['/index.php/adminer'];
        yield 'admin, caps'  => ['/INDEX.PHP/Admin'];
        yield 'admin slashes' => ['//index.php//admin'];
        yield 'encoded page' => ['/index%2Ephp/directory'];
        yield 'twice'        => ['/index.php/index.php/directory'];
        yield 'twice, slash' => ['/index.php//index.php/directory'];
    }

    /** @dataProvider frontControllerPaths */
    public function testTheFrontControllerFormIsA404(string $uri): void
    {
        $this->expectException(PageNotFoundException::class);
        (new FrontControllerPath())->before($this->requestFor($uri));
    }

    /** A form post never follows a 301 as a post, so it is refused, not moved. */
    public function testAPostToTheFrontControllerFormIsA404(): void
    {
        $this->expectException(PageNotFoundException::class);
        (new FrontControllerPath())->before($this->requestFor('/index.php/add-profile', 'POST'));
    }

    /**
     * Old /index.php/ links, which the app itself built until 27 Oct 2026,
     * move to the clean address on our own host with the query untouched.
     *
     * @return iterable<string,array{string,string,string}>
     */
    public static function legacyPaths(): iterable
    {
        yield 'search'      => ['GET', '/index.php/directory?q=Wood+%26+Marble&group=', 'directory?q=Wood+%26+Marble&group='];
        yield 'signup plan' => ['GET', '/index.php/add-profile?plan=free', 'add-profile?plan=free'];
        yield 'no query'    => ['HEAD', '/index.php/directory/some-profile', 'directory/some-profile'];
        yield 'encoded'     => ['GET', '/index.php/directory%20x', 'directory%20x'];
        yield 'off host'    => ['GET', '/index.php//evil.example/x', 'evil.example/x'];
        yield 'capitals'    => ['get', '/Index.PHP/faq', 'faq'];
    }

    /** @dataProvider legacyPaths */
    public function testLegacyLinksMoveToTheCleanAddress(string $method, string $uri, string $path): void
    {
        $response = (new FrontControllerPath())->before($this->requestFor($uri, $method));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(rtrim(base_url(), '/') . '/' . $path, $response->getHeaderLine('Location'));
    }

    /** @return iterable<string,array{string}> */
    public static function ordinaryPaths(): iterable
    {
        yield 'admin'           => ['/admin/login'];
        yield 'payfast'         => ['/payfast/notify'];
        yield 'bare front file' => ['/index.php'];
        yield 'query mentions'  => ['/directory?q=/index.php/'];
        yield 'home'            => ['/'];
    }

    /** @dataProvider ordinaryPaths */
    public function testEverythingElsePasses(string $uri): void
    {
        $this->assertNull((new FrontControllerPath())->before($this->requestFor($uri)));
    }

    private function requestFor(string $uri, string $method = 'GET'): IncomingRequest
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getServer')->with('REQUEST_URI')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);

        return $request;
    }
}
