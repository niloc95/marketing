<?php

use App\Libraries\MailHealth;
use App\Libraries\SystemHealth;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The shared health checks behind /health and the admin status page.
 *
 * These were private methods on the Health controller until the admin page
 * needed them too. The reason they were extracted rather than duplicated is
 * that two definitions of "healthy" drift, and the drift is invisible: the
 * monitor says fine, the admin page says broken, and nobody knows which to
 * believe. So the contract worth pinning is the shape, and that a failing
 * dependency actually shows up as failing.
 *
 * @internal
 */
final class SystemHealthTest extends CIUnitTestCase
{
    private SystemHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health = new SystemHealth();
        MailHealth::clear();
    }

    protected function tearDown(): void
    {
        MailHealth::clear();
        parent::tearDown();
    }

    public function testReportsTheFourChecksInTheExpectedShape(): void
    {
        $checks = $this->health->checks();

        $this->assertSame(['database', 'cache', 'storage', 'mail'], array_keys($checks));

        foreach ($checks as $name => $c) {
            $this->assertArrayHasKey('ok', $c, $name);
            $this->assertArrayHasKey('detail', $c, $name);
            $this->assertIsBool($c['ok'], $name);
            $this->assertIsString($c['detail'], $name);
        }
    }

    public function testHealthyLocallyMeansAllOk(): void
    {
        $checks = $this->health->checks();

        // If this fails the local environment is genuinely broken, which is
        // exactly what the checks are for — read the detail.
        $this->assertTrue(
            $this->health->allOk($checks),
            'failing checks: ' . json_encode(array_filter($checks, static fn ($c) => ! $c['ok']))
        );
    }

    public function testAllOkIsFalseWhenAnyCheckFails(): void
    {
        $this->assertFalse($this->health->allOk([
            'database' => ['ok' => true, 'detail' => 'ok'],
            'mail'     => ['ok' => false, 'detail' => 'boom'],
        ]));
    }

    /**
     * The check the whole mail-observability effort exists to drive. Two
     * recorded failures is the threshold MailHealth treats as an outage.
     */
    public function testMailCheckFailsWhenSendsAreFailing(): void
    {
        MailHealth::recordFailure('SMTP connect refused');
        MailHealth::recordFailure('SMTP connect refused');

        $checks = $this->health->checks();

        $this->assertFalse($checks['mail']['ok']);
        $this->assertStringContainsString('consecutive send failures', $checks['mail']['detail']);
        $this->assertFalse($this->health->allOk($checks));
    }

    public function testMailCheckRecoversAfterClearing(): void
    {
        MailHealth::recordFailure('a');
        MailHealth::recordFailure('b');
        $this->assertFalse($this->health->checks()['mail']['ok']);

        MailHealth::clear();

        $this->assertTrue($this->health->checks()['mail']['ok']);
    }

    public function testStoragePathsCoverTheDirectoriesThatMatter(): void
    {
        // Losing any one of these breaks something silently: sessions, the
        // rate limiter, the log the admin page reads, or uploads.
        $this->assertSame(
            ['writable/logs', 'writable/session', 'writable/cache', 'public/assets/listings'],
            array_keys($this->health->storagePaths())
        );
    }

    /** A check that throws must report a failure, never propagate. */
    public function testAThrowingCheckIsReportedNotRaised(): void
    {
        $guard = new ReflectionMethod(SystemHealth::class, 'guard');
        $guard->setAccessible(true);

        $result = $guard->invoke($this->health, static function (): ?string {
            throw new RuntimeException('kaboom');
        });

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('kaboom', $result['detail']);
    }
}
