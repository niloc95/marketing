<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The app never builds an /index.php/ URL.
 *
 * FrontControllerPath refuses /index.php/{path} for admin and for posts, and
 * moves the rest. Until 27 Oct 2026 App::$indexPage was still 'index.php', so
 * previous_url() carried the prefix and every redirect()->back() after a
 * failed form was a 404. The two must never disagree again.
 *
 * @internal
 */
final class CleanUrlTest extends CIUnitTestCase
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
        // The shared response keeps the last test's Content-Type. After a JSON
        // test the form would be "application/json", and CodeIgniter only
        // remembers HTML pages, so the page under test would go unrecorded.
        Services::resetSingle('response');
    }

    public function testTheIndexPageIsEmpty(): void
    {
        $this->assertSame('', config('App')->indexPage, 'FrontControllerPath refuses /index.php/ URLs, so the app must never build one');
    }

    public function testGeneratedUrlsHaveNoFrontController(): void
    {
        $this->assertStringNotContainsString('index.php', site_url('add-profile'));
        $this->assertStringNotContainsString('index.php', base_url('add-profile'));
    }

    public function testTheRememberedPageHasNoFrontController(): void
    {
        // A session, as a browser has: CodeIgniter only remembers the page
        // when one exists.
        $this->withSession([])->get('add-profile?plan=free')->assertOK();

        $previous = (string) session('_ci_previous_url');
        $this->assertStringContainsString('add-profile?plan=free', $previous);
        $this->assertStringNotContainsString('index.php', $previous);
    }

    public function testBackGoesToACleanUrl(): void
    {
        $this->withSession([])->get('add-profile?plan=free')->assertOK();

        $this->assertStringNotContainsString('index.php', redirect()->back()->getHeaderLine('Location'));
    }
}
