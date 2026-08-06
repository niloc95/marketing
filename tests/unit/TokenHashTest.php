<?php

use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The stored form of a magic-link token.
 *
 * This pins a contract that spans two languages: PHP writes and reads the
 * column, and the migration that converted the existing rows
 * (2026-08-05-100000_HashListingTokens) did it in SQL with SHA2(x, 256). If
 * those two ever disagree, every link issued before the migration silently
 * stops working — and because there is no self-service way to re-request a
 * *verification* email, the affected listings are stranded in 'pending' with no
 * way out. Hence the fixed vector below rather than a round-trip test: it is
 * the SQL side's assumption written down.
 *
 * @internal
 */
final class TokenHashTest extends CIUnitTestCase
{
    private function hash(string $token): string
    {
        $m = new ReflectionMethod(DirectoryListingMutationService::class, 'hashToken');
        $m->setAccessible(true);

        return $m->invoke(new DirectoryListingMutationService(), $token);
    }

    /**
     * Generated with: mysql -e "SELECT SHA2('the-token-value', 256)"
     * If this fails, PHP and the migration have diverged.
     */
    public function testMatchesMysqlSha2ForAKnownVector(): void
    {
        $this->assertSame(
            '618d151425d29d48face09e3d62f40a634781f82d0b4e948b9513e377033337d',
            $this->hash('the-token-value'),
            'PHP hash() no longer agrees with the SHA2() used by the backfill migration'
        );
    }

    public function testIsSixtyFourHexCharacters(): void
    {
        // The column is VARCHAR(64). A longer digest would be silently
        // truncated by MySQL in non-strict mode, and every lookup would then
        // match the wrong row or no row at all.
        $hash = $this->hash(bin2hex(random_bytes(32)));

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
    }

    public function testIsDeterministic(): void
    {
        // Lookups are a WHERE on this value, so the same token must always
        // produce the same digest. A salted or randomised hash would make the
        // token unfindable.
        $token = bin2hex(random_bytes(32));

        $this->assertSame($this->hash($token), $this->hash($token));
    }

    public function testDiffersFromTheRawToken(): void
    {
        $token = bin2hex(random_bytes(32));

        $this->assertNotSame($token, $this->hash($token));
    }
}
