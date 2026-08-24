<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * What the public signup form does when the address is already in the table.
 *
 * The reported symptom was "the listing form sends no email" — it did, but the
 * duplicate guard answered every repeat submission with a manage link, and a
 * manage link cannot publish: only verify() sets status = published. So an
 * owner whose first verification email never arrived got the one link that
 * could not finish what the page had just told them to finish, forever, with
 * verifyTtl expiring behind them. A rejected listing was the same dead end.
 *
 * The other half of the guard matters just as much and is easy to break while
 * fixing the first: every branch must return an identical message, or the form
 * turns into an oracle for whether an address is in the directory.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class SignupDuplicateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingMutationService $svc;
    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new DirectoryListingMutationService();
        $this->listings = new DirectoryListingModel();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Attorneys',
            'slug'       => 'attorneys',
            'group_name' => 'Legal',
            'is_active'  => 1,
        ], true);
    }

    /**
     * Valid public-form input.
     *
     * The coordinates are not decoration: ListingGeocoder::resolve() short-
     * circuits on plausible submitted coords, and without them every call here
     * would hit Nominatim over the network.
     *
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Duplicate Guard Co',
            'email'        => 'dupe-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            'consent'      => 1,
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        return $this->listings->find($id);
    }

    private function countFor(string $email): int
    {
        return $this->listings->where('email', $email)->countAllResults();
    }

    // ------------------------------------------------------- the happy path

    public function testAFreshAddressCreatesAPendingListingWithAVerifyToken(): void
    {
        $result = $this->svc->submitPublic($this->input());

        $this->assertTrue($result['ok']);
        $row = $this->row((int) $result['id']);
        $this->assertSame('pending', $row['status']);
        $this->assertNotNull($row['verify_token'], 'a fresh signup must be able to verify');
        $this->assertNull($row['manage_token']);
    }

    // ------------------------------------------------------ pending: resend

    /**
     * The reported bug. Second submission must mint a NEW verify token rather
     * than a manage link — that is the difference between an owner who can
     * publish and one who cannot.
     */
    public function testResubmittingAPendingAddressSendsAnotherVerifyLink(): void
    {
        $input = $this->input();
        $first = $this->svc->submitPublic($input);
        $before = (string) $this->row((int) $first['id'])['verify_token'];

        $second = $this->svc->submitPublic($input);

        $this->assertTrue($second['ok']);
        $this->assertSame((int) $first['id'], (int) $second['id'], 'it must act on the same listing');

        $row = $this->row((int) $first['id']);
        $this->assertSame('pending', $row['status']);
        $this->assertNotNull($row['verify_token']);
        $this->assertNotSame($before, $row['verify_token'], 'a fresh verify token must be minted');
        $this->assertNull($row['manage_token'], 'a pending listing must not be answered with a manage link');
    }

    public function testResubmittingAPendingAddressDoesNotCreateASecondRow(): void
    {
        $input = $this->input();
        $this->svc->submitPublic($input);
        $this->svc->submitPublic($input);

        $this->assertSame(1, $this->countFor($input['email']));
    }

    /**
     * A retry is far more often "the email never arrived" than a correction, so
     * the stored row keeps what the first submission got right.
     */
    public function testResubmittingAPendingAddressDoesNotOverwriteTheStoredDetails(): void
    {
        $input = $this->input();
        $first = $this->svc->submitPublic($input);

        $this->svc->submitPublic(array_merge($input, ['display_name' => 'Retyped Name']));

        $this->assertSame('Duplicate Guard Co', $this->row((int) $first['id'])['display_name']);
    }

    // ------------------------------------------------- published: manage link

    public function testResubmittingAPublishedAddressStillIssuesAManageLink(): void
    {
        $input   = $this->input();
        $created = $this->svc->submitPublic($input);
        $this->listings->update((int) $created['id'], ['status' => 'published', 'verify_token' => null]);

        $this->svc->submitPublic($input);

        $row = $this->row((int) $created['id']);
        $this->assertNotNull($row['manage_token'], 'a published owner gets a manage link');
        $this->assertNull($row['verify_token'], 'and nothing that would re-run the publish transition');
    }

    // ------------------------------------------------- rejected: fresh start

    public function testResubmittingARejectedAddressReusesTheRowAsANewSignup(): void
    {
        $input   = $this->input();
        $created = $this->svc->submitPublic($input);
        $this->listings->update((int) $created['id'], ['status' => 'rejected', 'verify_token' => null]);

        $second = $this->svc->submitPublic(array_merge($input, ['display_name' => 'Second Attempt Co']));

        $this->assertTrue($second['ok']);
        $this->assertSame((int) $created['id'], (int) $second['id'], 'the row must be reused, not duplicated');
        $this->assertSame(1, $this->countFor($input['email']));

        $row = $this->row((int) $created['id']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Second Attempt Co', $row['display_name'], 'the new details must land');
        $this->assertNotNull($row['verify_token'], 'and it must be publishable again');
    }

    /**
     * The row keeps its slug across a resubmit. Passing the id to
     * ensure_unique_slug() is what stops it colliding with itself and gaining a
     * "-2" on every attempt.
     */
    public function testAResubmittedRejectedListingKeepsItsSlug(): void
    {
        $input   = $this->input();
        $created = $this->svc->submitPublic($input);
        $slug    = (string) $this->row((int) $created['id'])['slug'];
        $this->listings->update((int) $created['id'], ['status' => 'rejected']);

        $this->svc->submitPublic($input);

        $this->assertSame($slug, $this->row((int) $created['id'])['slug']);
    }

    // ------------------------------------------------ the enumeration guard

    /**
     * Unknown address, pending, published and rejected must be
     * indistinguishable from outside. The moment one branch says something more
     * specific, the form leaks who is in the directory.
     */
    public function testEveryBranchReturnsTheIdenticalMessage(): void
    {
        $fresh = $this->svc->submitPublic($this->input());

        $pendingInput = $this->input();
        $this->svc->submitPublic($pendingInput);
        $pending = $this->svc->submitPublic($pendingInput);

        $publishedInput = $this->input();
        $published      = $this->svc->submitPublic($publishedInput);
        $this->listings->update((int) $published['id'], ['status' => 'published']);
        $published = $this->svc->submitPublic($publishedInput);

        $rejectedInput = $this->input();
        $rejected      = $this->svc->submitPublic($rejectedInput);
        $this->listings->update((int) $rejected['id'], ['status' => 'rejected']);
        $rejected = $this->svc->submitPublic($rejectedInput);

        $messages = array_unique([
            $fresh['message'],
            $pending['message'],
            $published['message'],
            $rejected['message'],
        ]);

        $this->assertCount(1, $messages, 'every outcome must read identically: ' . implode(' | ', $messages));
    }

    // ------------------------------------------------------ the admin lever

    public function testResendVerificationMintsANewTokenForAPendingListing(): void
    {
        $created = $this->svc->submitPublic($this->input());
        $before  = (string) $this->row((int) $created['id'])['verify_token'];

        $this->assertTrue($this->svc->resendVerification((int) $created['id']));
        $this->assertNotSame($before, $this->row((int) $created['id'])['verify_token']);
    }

    public function testResendVerificationRefusesAnythingNotPending(): void
    {
        $published = $this->svc->submitPublic($this->input());
        $this->listings->update((int) $published['id'], ['status' => 'published']);
        $publishedToken = $this->row((int) $published['id'])['verify_token'];

        $rejected = $this->svc->submitPublic($this->input());
        $this->listings->update((int) $rejected['id'], ['status' => 'rejected']);
        $rejectedToken = $this->row((int) $rejected['id'])['verify_token'];

        $this->assertFalse($this->svc->resendVerification((int) $published['id']));
        $this->assertFalse($this->svc->resendVerification((int) $rejected['id']));

        $this->assertSame($publishedToken, $this->row((int) $published['id'])['verify_token']);
        $this->assertSame($rejectedToken, $this->row((int) $rejected['id'])['verify_token']);
    }

    public function testResendVerificationRefusesAnUnknownListing(): void
    {
        $this->assertFalse($this->svc->resendVerification(999999));
    }
}
