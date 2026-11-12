<?php

use App\Services\DirectorySettings;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Online bookings come with the Verified Business badge (co.za) and Plus
 * (.app) since 12 Nov 2026, and the sales copy says so: home pricing and the
 * bookings moment, the signup plan cards, /verified, the FAQ.
 *
 * Pinned: every one of those promises follows bookings_in_premium(), so it is
 * there while bookings are on and come with the plan, and gone when bookings
 * are switched off or a separate Bookings plan is required. A promise that
 * outlives the feature would be false.
 *
 * @internal
 */
final class BookingsInPremiumCopyTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private const PAGES = ['/', '/add-profile', '/verified', '/faq'];

    protected function setUp(): void
    {
        parent::setUp();
        (new DirectorySettings())->forget();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    private function body(string $uri): string
    {
        return html_entity_decode((string) $this->get($uri)->response()->getBody(), ENT_QUOTES);
    }

    public function testTheHelperFollowsTheSwitchAndThePlan(): void
    {
        $this->setEnv('bookings.enabled', 'false');
        $this->assertFalse(bookings_in_premium('za'));

        $this->setEnv('bookings.enabled', 'true');
        $this->assertTrue(bookings_in_premium('za'));
        $this->assertTrue(bookings_in_premium('app'));

        $this->setEnv('bookings.planRequired', 'true');
        $this->assertFalse(bookings_in_premium('za'), 'A separate Bookings plan means the badge does not include them.');
    }

    public function testEverySalesPageSellsBookingsWhileTheyAreOn(): void
    {
        $this->setEnv('bookings.enabled', 'true');

        foreach (self::PAGES as $uri) {
            $this->assertStringContainsString(bookings_pitch(), $this->body($uri), $uri . ' should sell online bookings.');
        }
        $this->assertStringContainsString('id="bookings"', $this->body('/'), 'The home page has a bookings moment.');
        $this->assertStringContainsString('Can customers book an appointment on my profile?', $this->body('/faq'));
    }

    public function testNothingPromisesBookingsWhileTheyAreOff(): void
    {
        $this->setEnv('bookings.enabled', 'false');

        foreach (self::PAGES as $uri) {
            $body = $this->body($uri);
            $this->assertStringNotContainsString(bookings_pitch(), $body, $uri);
            $this->assertStringNotContainsString('Can customers book an appointment on my profile?', $body, $uri);
        }
        $this->assertStringNotContainsString('id="bookings"', $this->body('/'));
    }
}
