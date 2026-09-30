<?php

use App\Filters\AdminFilter;
use App\Services\FunnelService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * /admin/funnel and the FunnelService behind it.
 *
 * The counts that are easy to get wrong: International Listing rows must not
 * count as badge applications, a re-application must not count the listing
 * twice, a renewal must not count as a second paying business, and an EFT
 * activation (no PayFast event) must still count as paid.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminFunnelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private string $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = date('Y-m-d H:i:s');
        $this->seedFunnel();
    }

    public function testStagesCountEachBusinessOnce(): void
    {
        $f = (new FunnelService())->stages();
        $counts = array_column($f['stages'], 'count', 'key');

        $this->assertSame([
            'listings' => 6, // the deleted one is left out
            'emailed'  => 5,
            'applied'  => 3, // A (twice), B, C; D's international row is not an application
            'approved' => 3,
            'paid'     => 2, // A by PayFast, C by EFT
            'active'   => 2,
            'renewed'  => 1, // A
        ], $counts);
        $this->assertSame(1, $f['cancelling']); // C
        $this->assertSame(1, $f['churned']);
    }

    public function testWeeklySplitsFirstPaymentsFromRenewals(): void
    {
        $totals = ['listings' => 0, 'applications' => 0, 'first_payments' => 0, 'renewals' => 0, 'cancellations' => 0];
        foreach ((new FunnelService())->weekly(13) as $w) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $w[$k];
            }
        }

        $this->assertSame([
            'listings'       => 6,
            'applications'   => 4, // A's two attempts + B + C
            'first_payments' => 2, // A's first event, C's EFT activation
            'renewals'       => 1,
            'cancellations'  => 1,
        ], $totals);
    }

    public function testBySourceGroupsUntrackedSignups(): void
    {
        $rows = array_column((new FunnelService())->bySource(), null, 'label');

        $this->assertSame(['label' => 'invite', 'listings' => 2, 'applied' => 2, 'paid' => 2], $rows['invite']);
        $this->assertSame(3, $rows['(before tracking)']['listings']);
    }

    public function testPaceNeverGoesNegative(): void
    {
        $pace = (new FunnelService())->pace(1, date('Y-m-d', strtotime('+30 days')));

        $this->assertSame(2, $pace['active']);
        $this->assertSame(0, $pace['remaining']);
        $this->assertSame(0, $pace['perWeek']);
        $this->assertNull((new FunnelService())->pace(10, '2000-01-01')['perWeek']);
    }

    public function testThePageRendersForAnAdmin(): void
    {
        $result = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])
            ->get('admin/funnel');

        $result->assertOK();
        $result->assertSee('Verified Business funnel');
        $result->assertSee('Renewed at least once');
    }

    public function testThePageNeedsAnAdmin(): void
    {
        $this->get('admin/funnel')->assertRedirectTo(base_url('admin/login'));
    }

    // ------------------------------------------------------------------ fixtures

    /**
     *  A  invite, confirmed, rejected then approved, paid by PayFast and renewed
     *  B  marketing-site, confirmed, approved, never paid
     *  C  invite, confirmed, approved, paid by EFT, then cancelled (still in its paid month)
     *  D  untracked, confirmed, International Listing only
     *  E  untracked, confirmed, no application
     *  F  untracked, never confirmed
     *  X  deleted, had a paid badge — must vanish from every stage
     */
    private function seedFunnel(): void
    {
        $paidUntil = date('Y-m-d', strtotime('+20 days'));

        $a = $this->listing('a', 'invite', 1);
        $b = $this->listing('b', 'marketing-site', 1);
        $c = $this->listing('c', 'invite', 1);
        $d = $this->listing('d', null, 1);
        $this->listing('e', null, 1);
        $this->listing('f', null, 0);
        $x = $this->listing('x', 'invite', 1, $this->now);

        $va = $this->verification($a, 'badge', 'active', ['activated_at' => $this->now, 'paid_until' => $paidUntil]);
        $this->submission($va, 1, 'rejected');
        $this->submission($va, 2, 'approved');
        $this->event($va, 'pf-a1', date('Y-m-d H:i:s', strtotime('-40 days')));
        $this->event($va, 'pf-a2', $this->now);

        $vb = $this->verification($b, 'badge', 'approved');
        $this->submission($vb, 1, 'approved');

        $vc = $this->verification($c, 'badge', 'active', [
            'activated_at' => $this->now, 'paid_until' => $paidUntil, 'cancelled_at' => $this->now,
        ]);
        $this->submission($vc, 1, 'approved');

        $this->verification($d, 'international', 'active', ['activated_at' => $this->now, 'paid_until' => $paidUntil]);

        $vx = $this->verification($x, 'badge', 'active', ['activated_at' => $this->now, 'paid_until' => $paidUntil]);
        $this->submission($vx, 1, 'approved');
        $this->event($vx, 'pf-x1', $this->now);
    }

    private function listing(string $tag, ?string $source, int $confirmed, ?string $deletedAt = null): int
    {
        $db = db_connect();
        $db->table('xs_directory_listings')->insert([
            'display_name'  => 'Funnel ' . $tag,
            'email'         => "funnel-{$tag}@example.test",
            'slug'          => 'funnel-' . $tag,
            'status'        => $confirmed ? 'published' : 'pending',
            'is_verified'   => $confirmed,
            'signup_source' => $source,
            'created_at'    => $this->now,
            'updated_at'    => $this->now,
            'deleted_at'    => $deletedAt,
        ]);

        return (int) $db->insertID();
    }

    /** @param array<string,mixed> $extra */
    private function verification(int $listingId, string $plan, string $state, array $extra = []): int
    {
        $db = db_connect();
        $db->table('xs_directory_verifications')->insert($extra + [
            'listing_id'   => $listingId,
            'plan'         => $plan,
            'state'        => $state,
            'amount'       => '29.99',
            'submitted_at' => $this->now,
            'created_at'   => $this->now,
            'updated_at'   => $this->now,
        ]);

        return (int) $db->insertID();
    }

    private function submission(int $verificationId, int $attempt, string $outcome): void
    {
        db_connect()->table('xs_directory_verification_submissions')->insert([
            'verification_id' => $verificationId,
            'attempt_no'      => $attempt,
            'outcome'         => $outcome,
            'submitted_at'    => $this->now,
            'created_at'      => $this->now,
            'updated_at'      => $this->now,
        ]);
    }

    private function event(int $verificationId, string $paymentId, string $at): void
    {
        db_connect()->table('xs_directory_verification_events')->insert([
            'verification_id' => $verificationId,
            'pf_payment_id'   => $paymentId,
            'payment_status'  => 'COMPLETE',
            'amount_gross'    => '29.99',
            'payload'         => '{}',
            'created_at'      => $at,
            'updated_at'      => $at,
        ]);
    }
}
