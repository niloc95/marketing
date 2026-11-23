<?php

use App\Models\CareerApplicationModel;
use App\Models\Concerns\ResolvesStoredPath;
use App\Models\DirectoryVerificationDocumentModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The one containment check for verification documents and careers CVs
 * (audit 22 Nov 2026: the two models carried identical copies).
 *
 * Pinned: both stores use the shared trait, and a stored path can only ever
 * resolve to a regular file inside its own storage root.
 *
 * @internal
 */
final class ResolvesStoredPathTest extends CIUnitTestCase
{
    private static string $root = '';

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        self::$root    = sys_get_temp_dir() . '/rsp-root-' . bin2hex(random_bytes(4));
        $this->outside = sys_get_temp_dir() . '/rsp-out-' . bin2hex(random_bytes(4));
        mkdir(self::$root . '/2026/11', 0777, true);
        mkdir($this->outside, 0777, true);
        file_put_contents(self::$root . '/2026/11/doc.pdf', '%PDF');
        file_put_contents($this->outside . '/secret.txt', 'secret');
        symlink($this->outside . '/secret.txt', self::$root . '/2026/11/link.pdf');
    }

    protected function tearDown(): void
    {
        foreach ([self::$root . '/2026/11/link.pdf', self::$root . '/2026/11/doc.pdf', $this->outside . '/secret.txt'] as $f) {
            @unlink($f);
        }
        @rmdir(self::$root . '/2026/11');
        @rmdir(self::$root . '/2026');
        @rmdir(self::$root);
        @rmdir($this->outside);
        parent::tearDown();
    }

    private function store(): object
    {
        return new class () {
            use ResolvesStoredPath;

            public static function storageRoot(): string
            {
                return ResolvesStoredPathTest::root();
            }
        };
    }

    public static function root(): string
    {
        return self::$root;
    }

    public function testBothStoresUseTheSharedCheck(): void
    {
        $this->assertContains(ResolvesStoredPath::class, class_uses(DirectoryVerificationDocumentModel::class));
        $this->assertContains(ResolvesStoredPath::class, class_uses(CareerApplicationModel::class));
    }

    public function testAFileInsideTheRootResolves(): void
    {
        $expected = realpath(self::$root . '/2026/11/doc.pdf');
        $this->assertSame($expected, $this->store()->resolvePath('2026/11/doc.pdf'));
        $this->assertSame($expected, $this->store()->resolvePath('/2026/11/doc.pdf'), 'a leading slash stays inside the root');
    }

    public function testNothingOutsideTheRootOrNotAFileResolves(): void
    {
        $name  = basename($this->outside);
        $cases = [
            'empty'           => '',
            'parent escape'   => '../' . $name . '/secret.txt',
            'deep escape'     => '2026/11/../../../' . $name . '/secret.txt',
            'absolute'        => $this->outside . '/secret.txt',
            'symlink out'     => '2026/11/link.pdf',
            'NUL byte'        => "2026/11/doc.pdf\0.txt",
            'a directory'     => '2026/11',
            'missing'         => '2026/11/none.pdf',
        ];
        foreach ($cases as $label => $path) {
            $this->assertNull($this->store()->resolvePath($path), $label);
        }
    }

    public function testAMissingRootResolvesNothing(): void
    {
        $this->tearDown();
        $this->assertNull($this->store()->resolvePath('2026/11/doc.pdf'));
        $this->setUp();
    }
}
