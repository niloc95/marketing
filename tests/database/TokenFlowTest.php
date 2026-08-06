<?php

use App\Models\DirectoryListingModel;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Verification and manage-link redemption, against a real schema.
 *
 * These are the app's only credentials. There is no password to fall back on:
 * whoever holds a manage link can edit the listing, and whoever holds a verify
 * link can publish it. A regression here is not a broken page, it is either
 * owners locked out of their own listings or strangers let into them — and
 * until now none of it was covered.
 *
 * Runs against the `tests` database group, which must be a real MySQL database
 * (see .env). SQLite cannot build this schema: the migrations use FULLTEXT, a
 * spatial POINT column, and SHA2().
 *
 * @internal
 */
final class TokenFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // 'App' rather than the trait's default of 'Tests\Support' — the schema
    // under test is the real one, built by the real migrations.
    protected $namespace = 'App';

    // Rebuilt per test. The backfill case below runs an unscoped UPDATE, the
    // same one the migration runs, so it must not see rows another test left
    // behind — it would hash an already-hashed value and prove nothing.
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingMutationService $svc;
    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new DirectoryListingMutationService();
        $this->listings = new DirectoryListingModel();
    }

    /**
     * A listing holding $column = hash($token), expiring $ttl seconds out.
     * Returns [id, raw token].
     *
     * @return array{0:int,1:string}
     */
    private function seedListing(string $column, string $expiresColumn, int $ttl, string $status = 'pending'): array
    {
        $token = bin2hex(random_bytes(32));
        $id    = $this->listings->insert([
            'display_name' => 'Token Flow Co',
            'email'        => 'tokenflow+' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'         => 'token-flow-' . bin2hex(random_bytes(4)),
            'status'       => $status,
            'is_verified'  => 0,
            $column        => hash('sha256', $token),
            $expiresColumn => date('Y-m-d H:i:s', time() + $ttl),
        ], true);

        return [(int) $id, $token];
    }

    private function column(int $id, string $column): ?string
    {
        $row = $this->listings->find($id);

        return $row[$column] ?? null;
    }

    // ------------------------------------------------------------ verify

    public function testVerifyPublishesWithTheRawToken(): void
    {
        [$id, $token] = $this->seedListing('verify_token', 'verify_expires', 3600);

        $listing = $this->svc->verify($token);

        $this->assertIsArray($listing);
        $this->assertSame('published', $this->column($id, 'status'));
        $this->assertSame('1', (string) $this->column($id, 'is_verified'));
    }

    public function testVerifyIsSingleUse(): void
    {
        [, $token] = $this->seedListing('verify_token', 'verify_expires', 3600);

        $this->assertIsArray($this->svc->verify($token));
        $this->assertNull($this->svc->verify($token), 'a verify link must not be replayable');
    }

    public function testVerifyRejectsAnExpiredToken(): void
    {
        [$id, $token] = $this->seedListing('verify_token', 'verify_expires', -60);

        $this->assertNull($this->svc->verify($token));
        $this->assertSame('pending', $this->column($id, 'status'));
    }

    public function testVerifyRejectsAnUnknownToken(): void
    {
        $this->seedListing('verify_token', 'verify_expires', 3600);

        $this->assertNull($this->svc->verify(bin2hex(random_bytes(32))));
        $this->assertNull($this->svc->verify(''));
    }

    /**
     * The point of the whole exercise: someone who can read the table must not
     * be able to use what they find there as a link.
     */
    public function testTheStoredVerifyTokenIsNotUsableAsAToken(): void
    {
        [$id, $token] = $this->seedListing('verify_token', 'verify_expires', 3600);

        $stored = (string) $this->column($id, 'verify_token');
        $this->assertNotSame($token, $stored, 'the raw token must never be stored');
        $this->assertSame(hash('sha256', $token), $stored);

        $this->assertNull($this->svc->verify($stored), 'the stored hash must not redeem');
    }

    // ------------------------------------------------------ manage token

    public function testRedeemManageTokenReturnsTheListing(): void
    {
        [$id, $token] = $this->seedListing('manage_token', 'manage_expires', 3600, 'published');

        $listing = $this->svc->redeemManageToken($token);

        $this->assertIsArray($listing);
        $this->assertSame($id, (int) $listing['id']);
    }

    public function testRedeemManageTokenIsSingleUse(): void
    {
        [$id, $token] = $this->seedListing('manage_token', 'manage_expires', 3600, 'published');

        $this->assertIsArray($this->svc->redeemManageToken($token));
        $this->assertNull($this->svc->redeemManageToken($token));
        $this->assertNull($this->column($id, 'manage_token'));
        $this->assertNull($this->column($id, 'manage_expires'));
    }

    public function testRedeemManageTokenRejectsExpiredAndUnknown(): void
    {
        [, $expired] = $this->seedListing('manage_token', 'manage_expires', -60, 'published');

        $this->assertNull($this->svc->redeemManageToken($expired));
        $this->assertNull($this->svc->redeemManageToken(bin2hex(random_bytes(32))));
        $this->assertNull($this->svc->redeemManageToken(''));
    }

    public function testTheStoredManageTokenIsNotUsableAsAToken(): void
    {
        [$id, $token] = $this->seedListing('manage_token', 'manage_expires', 3600, 'published');

        $stored = (string) $this->column($id, 'manage_token');
        $this->assertNotSame($token, $stored);
        $this->assertNull($this->svc->redeemManageToken($stored));
    }

    // -------------------------------------------------- migration backfill

    /**
     * The migration hashed existing rows in place with SQL SHA2(), so links
     * already in people's inboxes had to keep working. This reproduces that:
     * a row written the old way (plaintext) and then converted exactly as the
     * migration converts it must still redeem with the original token.
     */
    public function testAPlaintextTokenConvertedByTheMigrationStillRedeems(): void
    {
        $token = bin2hex(random_bytes(32));

        // The pre-migration state: the raw token, in the column.
        $id = $this->listings->insert([
            'display_name'   => 'Legacy Token Co',
            'email'          => 'legacy+' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'           => 'legacy-token-' . bin2hex(random_bytes(4)),
            'status'         => 'published',
            'manage_token'   => $token,
            'manage_expires' => date('Y-m-d H:i:s', time() + 3600),
        ], true);

        // Exactly what 2026-08-05-100000_HashListingTokens does.
        $table = $this->db->prefixTable('directory_listings');
        $this->db->query(
            "UPDATE `{$table}` SET `manage_token` = SHA2(`manage_token`, 256) WHERE `manage_token` IS NOT NULL"
        );

        $this->assertSame(hash('sha256', $token), $this->column((int) $id, 'manage_token'));
        $this->assertIsArray(
            $this->svc->redeemManageToken($token),
            'a link issued before the migration must still work after it'
        );
    }
}
