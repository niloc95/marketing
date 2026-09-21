<?php

use App\Filters\HeadRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * HEAD answers wherever GET answers.
 *
 * CodeIgniter matches a request only against its own verb's bucket, so for most
 * of this app's life every route was GET-only and the whole site returned 404
 * to a HEAD request while returning 200 to GET. Link checkers, uptime monitors
 * and some social scrapers use HEAD, so they all saw a dead site. The fix is a
 * mirroring loop at the foot of Config\Routes.
 *
 * Both things worth pinning here fail silently:
 *
 * 1. The mirror covers everything. A route added *below* that loop is GET-only
 *    again, and nothing at runtime complains — the URL simply stops answering
 *    HEAD. This is the only thing that would notice.
 * 2. The options ride along. The mirrored route has to keep the 'admin' group
 *    filter; a HEAD that skipped it would answer the admin pages, including the
 *    verification queue, without a session.
 *
 * No database — this reads the route table and calls one filter.
 *
 * @internal
 */
final class HeadRouteTest extends CIUnitTestCase
{
    private function routes(): RouteCollection
    {
        $routes = service('routes', false);
        $routes->loadRoutes();

        return $routes;
    }

    public function testEveryGetRouteAlsoAnswersHead(): void
    {
        $routes = $this->routes();
        $get    = $routes->getRoutes('GET', false);
        $head   = $routes->getRoutes('HEAD', false);

        $this->assertNotEmpty($get, 'No GET routes loaded — the test is not reading the real table.');

        $missing = array_diff_key($get, $head);
        $this->assertSame([], $missing, 'GET routes with no HEAD twin: ' . implode(', ', array_keys($missing)));

        foreach ($get as $from => $handler) {
            $this->assertSame($handler, $head[$from], "HEAD {$from} points somewhere else than GET does.");
        }
    }

    public function testMirroredAdminRouteKeepsItsFilter(): void
    {
        $routes = $this->routes();

        $filters = (array) ($routes->getRoutesOptions('admin/verifications', 'HEAD')['filter'] ?? []);
        $this->assertContains('admin', $filters, 'HEAD would reach the admin queue unauthenticated.');
    }

    public function testFilterEmptiesTheBodyOnHeadOnly(): void
    {
        $filter = new HeadRequest();

        $response = (new Response(new App()))->setBody('<html>the page</html>');
        $filter->after(service('request')->withMethod('HEAD'), $response);
        $this->assertSame('', $response->getBody());

        $response = (new Response(new App()))->setBody('<html>the page</html>');
        $filter->after(service('request')->withMethod('GET'), $response);
        $this->assertSame('<html>the page</html>', $response->getBody());
    }
}
