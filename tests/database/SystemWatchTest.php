<?php

use App\Libraries\Heartbeat;
use App\Libraries\SystemHealth;
use App\Libraries\SystemWatch;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The system watch (owner, 20 Nov 2026): the booking engine check in
 * /health, and the emails for health faults, new log errors, failed jobs
 * and the morning summary.
 *
 * @internal
 */
final class SystemWatchTest extends CIUnitTestCase
{
    use FreshDatabase;
    use WithEnv;

    private string $dir;

    private string $logs;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir  = sys_get_temp_dir() . '/watch-' . bin2hex(random_bytes(4)) . '/';
        $this->logs = $this->dir . 'logs/';
        mkdir($this->logs, 0777, true);
        Heartbeat::forget('bookings:dispatch');
        Heartbeat::forget('bookings:payments');
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Heartbeat::forget('bookings:dispatch');
        Heartbeat::forget('bookings:payments');
        foreach (glob($this->logs . '*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir . '*') ?: [] as $f) {
            is_dir($f) ? rmdir($f) : unlink($f);
        }
        rmdir($this->dir);
        SystemWatch::$certGlobs = ['/etc/ssl/cloudflare/*.pem'];
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // The booking engine in /health

    public function testBookingsAreNotCheckedWhileOffOrWhileNoBusinessTakesThem(): void
    {
        $this->assertTrue((new SystemHealth())->checks()['bookings']['ok'], 'Bookings off everywhere');

        $this->setEnv('bookings.enabled', 'true');
        $this->assertTrue((new SystemHealth())->checks()['bookings']['ok'], 'On, but no business takes bookings');
    }

    public function testAStoppedJobAPileOfWaitingMessagesAndFailuresAreFaults(): void
    {
        $this->setEnv('bookings.enabled', 'true');
        $listing = $this->business();

        $check = (new SystemHealth())->checks()['bookings'];
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('bookings:dispatch has not finished a run', $check['detail']);

        Heartbeat::beat('bookings:dispatch');
        Heartbeat::beat('bookings:payments');
        $this->assertTrue((new SystemHealth())->checks()['bookings']['ok'], 'Both jobs just ran');

        file_put_contents(Heartbeat::dir() . 'beat-bookings-dispatch', (string) (time() - 600));
        $this->assertStringContainsString('bookings:dispatch last finished 10 minutes ago', (new SystemHealth())->checks()['bookings']['detail']);
        Heartbeat::beat('bookings:dispatch');

        $this->queued($listing, 'queued', gmdate('Y-m-d H:i:s', time() - 20 * 60));
        $this->queued($listing, 'queued', gmdate('Y-m-d H:i:s', time() + 600));
        $this->assertStringContainsString('1 booking messages waiting, the oldest for 20 minutes', (new SystemHealth())->checks()['bookings']['detail'], 'Only due messages count');

        $this->db->table('booking_notification_queue')->where('status', 'queued')->update(['status' => 'sent']);
        foreach (range(1, SystemHealth::FAILED_PER_HOUR) as $i) {
            $this->queued($listing, 'failed', gmdate('Y-m-d H:i:s'));
        }
        $this->assertStringContainsString(SystemHealth::FAILED_PER_HOUR . ' booking messages failed in the last hour', (new SystemHealth())->checks()['bookings']['detail']);
    }

    // Emails from the 5 minute run

    public function testAFaultIsEmailedOnceAnHourAndOnceMoreWhenFixed(): void
    {
        $ok    = ['database' => ['ok' => true, 'detail' => 'ok']];
        $fault = ['database' => ['ok' => false, 'detail' => 'connection refused']];
        $t     = strtotime('2030-01-07 10:00:00 UTC');

        $this->watch(fn () => $fault, $t)->run();
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Problem: database', $this->sent[0]['subject']);
        $this->assertStringContainsString('connection refused', (string) $this->sent[0]['body']);

        $this->watch(fn () => $fault, $t + 300)->run();
        $this->assertCount(1, $this->sent, 'Not again within the hour');

        $this->watch(fn () => $fault, $t + SystemWatch::REPEAT_AFTER)->run();
        $this->assertCount(2, $this->sent, 'Again after an hour while it lasts');

        $worse = ['database' => ['ok' => false, 'detail' => 'connection refused; too many connections']];
        $this->watch(fn () => $worse, $t + SystemWatch::REPEAT_AFTER + 300)->run();
        $this->assertCount(3, $this->sent, 'At once when the fault changes');
        $this->watch(fn () => ['database' => ['ok' => false, 'detail' => 'connection refused; too many connections']], $t + SystemWatch::REPEAT_AFTER + 600)->run();
        $this->assertCount(3, $this->sent, 'Not again while it stays the same');
        array_splice($this->sent, 2, 1);

        $this->watch(fn () => $ok, $t + 5000)->run();
        $this->assertCount(3, $this->sent);
        $this->assertStringContainsString('Fixed: database', $this->sent[2]['subject']);

        $this->watch(fn () => $ok, $t + 5300)->run();
        $this->assertCount(3, $this->sent, 'Nothing while all is well');
    }

    public function testNewLogErrorsAreEmailedByKindButNotTheHistoryBeforeTheFirstRun(): void
    {
        $now = strtotime('2030-01-07 10:00:00 UTC');
        $log = $this->logs . 'log-2030-01-07.log';
        file_put_contents($log, "CRITICAL - 2030-01-07 08:00:00 --> Old trouble before the watch existed\n");

        $this->watch(null, $now)->run();
        $this->assertSame([], $this->sent, 'The first run starts at the end of the log');

        file_put_contents($log, "WARNING - 2030-01-07 10:01:00 --> Only a warning\n"
            . "CRITICAL - 2030-01-07 10:01:00 --> Cache unable to write to \"/var/www/writable/cache/\".\n[Method: GET, Route: directory/salon-lumi/book]\n"
            . "CRITICAL - 2030-01-07 10:02:00 --> Cache unable to write to \"/var/www/writable/cache/\".\n"
            . "ERROR - 2030-01-07 10:03:00 --> Duplicate entry 'career-9-email' for key 42\n", FILE_APPEND);
        $this->watch(null, $now + 300)->run();
        $this->assertCount(1, $this->sent, 'One email for all new kinds');
        $this->assertStringContainsString('2 new errors', $this->sent[0]['subject']);
        $body = $this->text($this->sent[0]);
        $this->assertStringContainsString('CRITICAL x2: Cache unable to write', $body);
        $this->assertStringContainsString('[GET directory/salon-lumi/book]', $body);
        $this->assertStringContainsString('ERROR x1: Duplicate entry', $body);
        $this->assertStringNotContainsString('Only a warning', $body);

        file_put_contents($log, "CRITICAL - 2030-01-07 10:06:00 --> Cache unable to write to \"/var/www/writable/cache/\".\n", FILE_APPEND);
        $this->watch(null, $now + 600)->run();
        $this->assertCount(1, $this->sent, 'The same kind is not emailed again within the hour');

        // Past midnight: the rest of yesterday's file is read, then today's.
        file_put_contents($log, "ALERT - 2030-01-07 23:59:00 --> Late trouble\n", FILE_APPEND);
        file_put_contents($this->logs . 'log-2030-01-08.log', "EMERGENCY - 2030-01-08 00:01:00 --> Early trouble\n");
        $this->watch(null, strtotime('2030-01-08 00:05:00 UTC'))->run();
        $this->assertCount(2, $this->sent);
        $body = (string) $this->sent[1]['body'];
        $this->assertStringContainsString('Late trouble', $body);
        $this->assertStringContainsString('Early trouble', $body);
    }

    public function testAFailedJobIsEmailedWithItsOutputOnceAnHour(): void
    {
        file_put_contents($this->dir . 'job-db-dump.out', "mysqldump: Got error: 1045: Access denied\n");
        $t = strtotime('2030-01-07 02:15:00 UTC');

        $this->assertTrue($this->watch(null, $t)->jobFailed('db-dump', 2));
        $this->assertStringContainsString('Scheduled job failed: db-dump', $this->sent[0]['subject']);
        $this->assertStringContainsString('Access denied', (string) $this->sent[0]['body']);
        $this->assertStringContainsString('exit code 2', (string) $this->sent[0]['body']);

        $this->assertFalse($this->watch(null, $t + 600)->jobFailed('db-dump', 2), 'Once an hour');
        $this->assertTrue($this->watch(null, $t + 600)->jobFailed('bookings-dispatch', 1), 'Another job is its own alert');
    }

    public function testTheMorningSummaryIsSentEvenWhenAllIsWellAndNamesWhatNeedsALook(): void
    {
        $now = strtotime('2030-01-08 05:05:00 UTC');
        SystemWatch::$certGlobs = [];
        $ok = fn () => ['database' => ['ok' => true, 'detail' => 'ok']];

        $r = $this->watch($ok, $now)->daily();
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('All well: daily system summary for 2030-01-07', $this->sent[0]['subject']);
        $this->assertStringContainsString('If it stops arriving', (string) $this->sent[0]['body']);

        file_put_contents($this->logs . 'log-2030-01-07.log', "ERROR - 2030-01-07 10:00:00 --> Something broke 1\nERROR - 2030-01-07 11:00:00 --> Something broke 2\n");
        file_put_contents($this->dir . 'job-db-dump.json', json_encode(['at' => $now - 9000, 'exit' => 2, 'seconds' => 3]));
        $r = $this->watch($ok, $now)->daily();
        $this->assertFalse($r['ok']);
        $this->assertSame(2, $r['errors']);
        $mail = $this->sent[1];
        $this->assertStringContainsString('Needs a look', $mail['subject']);
        $body = $this->text($mail);
        $this->assertStringContainsString('db-dump failed on its last run', $body);
        $this->assertStringContainsString('2 x error: Something broke 1', $body, 'Same kind counted together');
    }

    /** @param array<string,mixed> $mail */
    private function text(array $mail): string
    {
        return html_entity_decode(strip_tags((string) $mail['body']), ENT_QUOTES);
    }

    private function watch(?callable $checks, int $now): SystemWatch
    {
        return new SystemWatch($checks ?? static fn (): array => [], $this->dir, $this->logs, $now);
    }

    private function business(): int
    {
        $this->db->table('directory_listings')->insert([
            'type' => 'practice', 'display_name' => 'Salon Lumi', 'slug' => 'salon-lumi', 'email' => 'salon@example.test',
            'status' => 'published', 'country' => 'South Africa', 'bookings_enabled' => 1,
        ]);

        return (int) $this->db->insertID();
    }

    private function queued(int $listingId, string $status, string $runAfter): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->table('booking_notification_queue')->insert([
            'listing_id' => $listingId, 'appointment_id' => 999999, 'event' => 'confirmed', 'channel' => 'email',
            'status' => $status, 'run_after' => $runAfter, 'idempotency_key' => bin2hex(random_bytes(8)),
            'correlation_id' => bin2hex(random_bytes(16)), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
