<?php

use App\Libraries\LogReader;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The log parser behind the admin status page.
 *
 * Two of these tests are security tests rather than correctness tests, and both
 * guard mistakes that are easy to reintroduce:
 *
 *  - the entry regex must be anchored to ^, or attacker-controlled message text
 *    (uploaded filenames, CSP report URLs) can forge a log entry at a level of
 *    its choosing;
 *  - the filename must be validated twice, by pattern and by realpath
 *    containment, because the page reads from a directory that sits next to
 *    session files.
 *
 * @internal
 */
final class LogReaderTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/logreader-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->dir . '/' . $name, $body);
    }

    private function reader(): LogReader
    {
        return new LogReader($this->dir);
    }

    // ------------------------------------------------------------- listing

    public function testListsOnlyLogFilesNewestFirst(): void
    {
        $this->write('log-2026-08-01.log', "ERROR - 2026-08-01 10:00:00 --> a\n");
        $this->write('log-2026-08-03.log', "ERROR - 2026-08-03 10:00:00 --> b\n");
        $this->write('index.html', 'not a log');
        $this->write('notes.txt', 'not a log');

        $names = array_column($this->reader()->files(), 'name');

        $this->assertSame(['log-2026-08-03.log', 'log-2026-08-01.log'], $names);
        $this->assertSame('log-2026-08-03.log', $this->reader()->latestFile());
    }

    // ------------------------------------------------------------- parsing

    public function testParsesLevelTimeAndMessage(): void
    {
        $this->write('log-2026-08-01.log', "ERROR - 2026-08-01 10:00:00 --> something broke\n");

        $e = $this->reader()->entries('log-2026-08-01.log');

        $this->assertCount(1, $e);
        $this->assertSame('ERROR', $e[0]['level']);
        $this->assertSame('2026-08-01 10:00:00', $e[0]['time']);
        $this->assertSame('something broke', $e[0]['message']);
    }

    /**
     * A stack trace is one entry spanning many lines with no terminator, and
     * its frames are not reliably indented — a SQL literal inside a frame can
     * start at column 0.
     */
    public function testKeepsAMultiLineEntryWhole(): void
    {
        $this->write('log-2026-08-01.log', implode("\n", [
            'CRITICAL - 2026-08-01 10:00:00 --> Exception: boom',
            'in /app/Foo.php on line 12',
            ' 1 SYSTEMPATH/Bar.php(9): query(\'SELECT 1',
            'FROM t\', 0)',
            'ERROR - 2026-08-01 10:00:01 --> next entry',
            '',
        ]));

        $e = $this->reader()->entries('log-2026-08-01.log', 5);

        $this->assertCount(2, $e);
        // Newest first.
        $this->assertSame('next entry', $e[0]['message']);
        $this->assertStringContainsString('FROM t', $e[1]['message']);
        $this->assertStringContainsString('boom', $e[1]['message']);
    }

    /**
     * The anchoring test. A message body containing something that looks like a
     * log prefix must stay part of its own entry — never become one.
     */
    public function testDoesNotLetMessageContentForgeAnEntry(): void
    {
        $this->write('log-2026-08-01.log',
            "WARNING - 2026-08-01 10:00:00 --> CSP violation: blocked=x ERROR - 2026-01-01 00:00:00 --> FORGED\n");

        $e = $this->reader()->entries('log-2026-08-01.log');

        $this->assertCount(1, $e, 'mid-line prefixes must not start a new entry');
        $this->assertSame('WARNING', $e[0]['level']);
        $this->assertStringContainsString('FORGED', $e[0]['message'], 'it stays inert inside the real message');
    }

    public function testFiltersByLevel(): void
    {
        $this->write('log-2026-08-01.log', implode("\n", [
            'DEBUG - 2026-08-01 10:00:00 --> noise',
            'INFO - 2026-08-01 10:00:01 --> chatter',
            'WARNING - 2026-08-01 10:00:02 --> worth seeing',
            'ERROR - 2026-08-01 10:00:03 --> definitely',
            '',
        ]));

        $levels = array_column($this->reader()->entries('log-2026-08-01.log', 5), 'level');
        sort($levels);

        $this->assertSame(['ERROR', 'WARNING'], $levels, 'DEBUG and INFO must be dropped at level 5');
    }

    public function testRespectsTheEntryLimit(): void
    {
        $body = '';
        for ($i = 0; $i < 50; $i++) {
            $body .= sprintf("ERROR - 2026-08-01 10:00:%02d --> entry %d\n", $i % 60, $i);
        }
        $this->write('log-2026-08-01.log', $body);

        $this->assertCount(10, $this->reader()->entries('log-2026-08-01.log', 5, 10));
    }

    // ------------------------------------------------------- path handling

    /** @return list<array{0:string}> */
    public static function badNames(): array
    {
        return [
            ['../../.env'],
            ['../../../../etc/passwd'],
            ['log-2026-08-01.log/../../.env'],
            ['index.html'],
            ['notes.txt'],
            ['log-2026-08-01.log.bak'],
            ['log-20260801.log'],
            [''],
            ['.'],
        ];
    }

    /** @dataProvider badNames */
    public function testRefusesAnythingThatIsNotADatedLogFile(string $name): void
    {
        $this->write('log-2026-08-01.log', "ERROR - 2026-08-01 10:00:00 --> a\n");

        $this->assertNull($this->reader()->resolve($name));
        $this->assertSame([], $this->reader()->entries($name));
    }

    public function testResolvesAValidName(): void
    {
        $this->write('log-2026-08-01.log', "ERROR - 2026-08-01 10:00:00 --> a\n");

        $this->assertNotNull($this->reader()->resolve('log-2026-08-01.log'));
    }

    /**
     * The pattern alone cannot see a symlink — only realpath containment can.
     */
    public function testRefusesASymlinkPointingOutsideTheLogDirectory(): void
    {
        $secret = sys_get_temp_dir() . '/logreader-secret-' . bin2hex(random_bytes(4));
        file_put_contents($secret, 'database.default.password = hunter2');
        @symlink($secret, $this->dir . '/log-2026-08-02.log');

        $resolved = $this->reader()->resolve('log-2026-08-02.log');
        @unlink($secret);

        $this->assertNull($resolved, 'a correctly-named symlink out of the directory must still be refused');
    }

    public function testReadingAMissingFileIsEmptyNotAnError(): void
    {
        $this->assertSame([], $this->reader()->entries('log-2026-01-01.log'));
    }
}
