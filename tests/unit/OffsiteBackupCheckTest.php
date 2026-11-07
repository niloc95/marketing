<?php

use App\Libraries\OffsiteBackupCheck;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * backups:check reads the last line of scripts/backup-offsite.sh's log. Only
 * a recent OK with a real sized dump passes; a failure, a missing log or a
 * night with no run at all must each produce a reason for the admin email.
 *
 * @internal
 */
final class OffsiteBackupCheckTest extends CIUnitTestCase
{
    private string $log;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = tempnam(sys_get_temp_dir(), 'offsite');
        $this->now = strtotime('2026-11-08T03:10:00+00:00');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function problem(string ...$lines): ?string
    {
        file_put_contents($this->log, implode("\n", $lines) . "\n");

        return (new OffsiteBackupCheck())->problem($this->log, $this->now);
    }

    public function testARecentOkPasses(): void
    {
        $this->assertNull($this->problem(
            '2026-11-07T02:40:09+00:00 FAIL upload of files',
            '2026-11-08T02:40:12+00:00 OK db 794580 files 47219454',
        ));
    }

    public function testAFailedLastRunIsReported(): void
    {
        $problem = $this->problem(
            '2026-11-07T02:40:12+00:00 OK db 794580 files 47219454',
            '2026-11-08T02:40:09+00:00 FAIL upload of /home/deploy/backups/db-2026-11-08.sql.gz',
        );

        $this->assertStringContainsString('last run failed', (string) $problem);
        $this->assertStringContainsString('upload of', (string) $problem);
    }

    public function testANightWithNoRunIsReported(): void
    {
        $this->assertStringContainsString('no successful run since 6 Nov', (string) $this->problem(
            '2026-11-06T02:40:12+00:00 OK db 794580 files 47219454',
        ));
    }

    public function testTheMonthlyDrillGetsALongerLimit(): void
    {
        file_put_contents($this->log, "2026-10-12T04:30:00+00:00 OK db 794580 files 2210 tables 96 seconds 41\n");

        $this->assertNull((new OffsiteBackupCheck())->problem($this->log, $this->now, 768));
        $this->assertNotNull((new OffsiteBackupCheck())->problem($this->log, $this->now));
    }

    public function testATinyDumpIsReported(): void
    {
        $this->assertStringContainsString('only 20 bytes', (string) $this->problem(
            '2026-11-08T02:40:12+00:00 OK db 20 files 47219454',
        ));
    }

    public function testAMissingOrEmptyLogIsReported(): void
    {
        $this->assertStringContainsString('empty', (string) $this->problem(''));

        unlink($this->log);
        $this->assertStringContainsString('no backup log', (string) (new OffsiteBackupCheck())->problem($this->log, $this->now));
    }
}
