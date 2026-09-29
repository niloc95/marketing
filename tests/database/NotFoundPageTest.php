<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The branded 404 (Controllers\Errors, the router's 404 override).
 *
 * Pinned: an unknown route and a 404 thrown from a controller both render the
 * site's own page with a real 404 status, it is never indexed, it carries the
 * same security headers as every other page, and it never echoes the URL that
 * was asked for.
 *
 * @internal
 */
final class NotFoundPageTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    public function testAnUnknownRouteGetsTheBrandedPage(): void
    {
        $result = $this->get('wp2/wp-includes/wlwmanifest.xml');

        $result->assertStatus(404);
        $html = (string) $result->response()->getBody();
        $this->assertStringContainsString("We couldn't find that page", html_entity_decode($html));
        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertStringNotContainsString('wlwmanifest', $html, 'the requested path is never echoed');
        $this->assertSame('DENY', $result->response()->getHeaderLine('X-Frame-Options'));
    }

    public function testAMissingListingSaysSo(): void
    {
        $result = $this->get('directory/no-such-business-here');

        $result->assertStatus(404);
        $html = html_entity_decode((string) $result->response()->getBody());
        $this->assertStringContainsString("We couldn't find that business", $html);
        $this->assertStringContainsString('Manage your profile', $html);
        $this->assertStringNotContainsString('no-such-business-here', $html);
    }
}
