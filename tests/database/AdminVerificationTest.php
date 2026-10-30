<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationModel;
use App\Models\DirectoryVerificationSubmissionModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryService;
use App\Services\DirectorySettings;
use App\Services\TeamMemberService;
use App\Services\VerificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * An admin verifying a profile from its edit page (VerificationService::
 * grantByAdmin()), for a customer who emailed the documents in: approve and
 * let the owner pay, or activate now, once the customer has approved the
 * preview. A profile built as Verified (admin_plan, asBuilt()) keeps its team,
 * branches and video before any badge, and shows them only in the previews.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminVerificationTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private VerificationService $svc;
    private DirectoryVerificationModel $verifications;
    private DirectoryListingModel $listings;
    private string $fixturePrefix = 'fixture_';

    protected function setUp(): void
    {
        parent::setUp();
        (new DirectorySettings())->forget();
        (new DirectorySettings())->save(['badge_price' => '29.99', 'badge_enabled' => '1'], 'admin@test');
        $this->fixturePrefix = 'fixture_' . bin2hex(random_bytes(4)) . '_';
        $this->svc           = new VerificationService();
        $this->verifications = new DirectoryVerificationModel();
        $this->listings      = new DirectoryListingModel();
        service('email')->archive = [];
    }

    protected function tearDown(): void
    {
        $root = DirectoryVerificationDocumentModel::storageRoot();
        foreach (glob($root . '/*/' . $this->fixturePrefix . '*') ?: [] as $path) {
            @unlink($path);
        }
        (new DirectorySettings())->forget();

        parent::tearDown();
    }

    public function testBothDocumentsApproveAndEmailAConfirmedOwnerToPay(): void
    {
        $id = $this->listing(['status' => 'published', 'is_verified' => 1]);

        $result = $this->svc->grantByAdmin($id, $this->documentsOnDisk($id), '', 'owner_pays', 1, 'admin@test');

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->verifications->forListing($id);
        $this->assertSame(DirectoryVerificationModel::STATE_APPROVED, $row['state']);
        $this->assertSame('admin@test', $row['reviewed_by']);
        $this->assertCount(2, (new DirectoryVerificationDocumentModel())->forVerification((int) $row['id']));
        $this->assertSame(
            DirectoryVerificationSubmissionModel::OUTCOME_APPROVED,
            (new DirectoryVerificationSubmissionModel())->latestForVerification((int) $row['id'])['outcome']
        );
        $this->assertStringContainsString('approved', (string) (service('email')->archive['subject'] ?? ''));
        // Approved is not paid: no badge yet.
        $this->assertNull($this->listings->find($id)['verified_until']);
    }

    public function testANoteAloneIsEnoughAndIsKeptWithTheDecision(): void
    {
        $id = $this->listing(['status' => 'published', 'is_verified' => 1]);

        $result = $this->svc->grantByAdmin($id, [], 'CIPC number checked by phone', 'owner_pays', 1, 'admin@test');

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->verifications->forListing($id);
        $this->assertSame(
            'CIPC number checked by phone',
            (new DirectoryVerificationSubmissionModel())->latestForVerification((int) $row['id'])['admin_note']
        );
    }

    public function testNoDocumentsAndNoNoteIsRefusedAndLeavesNothing(): void
    {
        $id   = $this->listing(['status' => 'published', 'is_verified' => 1]);
        $docs = $this->documentsOnDisk($id);
        unset($docs['owner_id']);

        $result = $this->svc->grantByAdmin($id, $docs, '  ', 'owner_pays', 1, 'admin@test');

        $this->assertFalse($result['ok']);
        $this->assertNull($this->verifications->forListing($id));
        // The one document that was passed in is removed with the refusal.
        $this->assertFileDoesNotExist(DirectoryVerificationDocumentModel::storageRoot() . '/' . $id . '/' . $docs['company_registration']['name']);
    }

    public function testActivateGrantsTheBadgeNow(): void
    {
        $id = $this->listing(['status' => 'published', 'is_verified' => 1]);

        $result = $this->svc->grantByAdmin($id, [], 'Paid by EFT', 'activate', 3, 'admin@test');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $this->verifications->forListing($id)['state']);
        $until = (string) $this->listings->find($id)['verified_until'];
        $this->assertGreaterThan(date('Y-m-d', strtotime('+2 months')), $until);
        $this->assertTrue(listing_is_verified_business($this->listings->find($id)));
        $this->assertStringContainsString('is live', (string) (service('email')->archive['subject'] ?? ''));
    }

    public function testAVerifiedBuildKeepsTeamBranchesAndVideoBeforeAnyBadge(): void
    {
        $admin   = new DirectoryAdminService();
        $created = $admin->upsert(null, [
            'display_name' => 'Smile Studio',
            'email'        => 'build@example.test',
            'status'       => 'pending',
            'country'      => 'South Africa',
            'admin_plan'   => 'verified',
            'team'         => [['name' => 'Dr Naledi Dube']],
        ]);
        $this->assertTrue($created['ok'], $created['message'] ?? '');
        $id  = (int) $created['id'];
        $row = $this->listings->find($id);

        $this->assertSame('verified', $row['admin_plan']);
        $this->assertNull($row['verified_until'], 'the choice is not the badge');
        $this->assertCount(1, (new TeamMemberService())->forListing($id));

        // The build and the previews show the team; the real row does not.
        $this->assertCount(1, (new DirectoryService())->profileFor($admin->asBuilt($row))['team']);
        $this->assertSame([], (new DirectoryService())->profileFor($row)['team']);
    }

    public function testAFreeBuildDropsTheTeam(): void
    {
        $created = (new DirectoryAdminService())->upsert(null, [
            'display_name' => 'Smile Studio',
            'email'        => 'free@example.test',
            'status'       => 'pending',
            'country'      => 'South Africa',
            'admin_plan'   => 'free',
            'team'         => [['name' => 'Dr Naledi Dube']],
        ]);

        $this->assertTrue($created['ok']);
        $this->assertSame([], (new TeamMemberService())->forListing((int) $created['id']));
    }

    public function testTheButtonsWaitForTheCustomerToApproveThePreview(): void
    {
        $id = $this->listing(['admin_plan' => 'verified']);

        $refused = $this->svc->grantByAdmin($id, [], 'Documents by email', 'owner_pays', 1, 'admin@test');
        $this->assertFalse($refused['ok']);
        $this->assertNull($this->verifications->forListing($id));
        $this->asAdmin()->get('admin/edit/' . $id)->assertSee('Waiting for the customer to approve the preview');

        // The customer's preview shows the Verified build.
        $token = $this->sendPreview($id);
        $this->get('preview/' . $token)->assertSee('This is your Verified Business profile');

        $this->post('preview/' . $token . '/approve', [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame('published', $this->listings->find($id)['status']);

        service('email')->archive = [];
        $this->assertTrue($this->svc->grantByAdmin($id, [], 'Documents by email', 'owner_pays', 1, 'admin@test')['ok']);
        $this->assertStringContainsString('approved', (string) (service('email')->archive['subject'] ?? ''));
    }

    public function testANewProfileOffersTheChoiceWithTheSectionsHidden(): void
    {
        $page = $this->asAdmin()->get('admin/new');
        $page->assertOK();
        $page->assertSee('Profile type');
        $page->assertSee('data-admin-plan-extras class="hidden"', null);
    }

    public function testTheOwnerCannotSetTheProfileType(): void
    {
        $this->assertNotContains('admin_plan', DirectoryListingModel::OWNER_EDITABLE);
    }

    public function testRefusedForActiveAndSubmittedRowsAndAbroad(): void
    {
        $active = $this->listing(['status' => 'published', 'is_verified' => 1]);
        $this->svc->grantByAdmin($active, [], 'EFT', 'activate', 1, 'admin@test');
        $this->assertFalse($this->svc->grantByAdmin($active, [], 'again', 'activate', 1, 'admin@test')['ok']);

        $applied = $this->listing(['status' => 'published', 'is_verified' => 1, 'slug' => 'applied-co', 'email' => 'applied@example.test']);
        $this->svc->submitApplication($applied, $this->documentsOnDisk($applied));
        $this->assertFalse($this->svc->grantByAdmin($applied, [], 'skip', 'owner_pays', 1, 'admin@test')['ok']);
        $this->assertSame(DirectoryVerificationModel::STATE_SUBMITTED, $this->verifications->forListing($applied)['state']);

        $abroad = $this->listing(['status' => 'published', 'is_verified' => 1, 'slug' => 'abroad-co', 'email' => 'abroad@example.test', 'country' => 'Kenya']);
        $this->assertFalse($this->svc->grantByAdmin($abroad, [], 'note', 'owner_pays', 1, 'admin@test')['ok']);
        $this->assertNull($this->verifications->forListing($abroad));
    }

    public function testRefusedWhileTheBadgeIsSwitchedOff(): void
    {
        (new DirectorySettings())->save(['badge_price' => '29.99', 'badge_enabled' => '0'], 'admin@test');
        $id = $this->listing(['status' => 'published', 'is_verified' => 1]);

        $this->assertFalse((new VerificationService())->grantByAdmin($id, [], 'note', 'owner_pays', 1, 'admin@test')['ok']);
    }

    public function testTheAdminEditPageVerifiesWithANote(): void
    {
        $free = $this->listing(['status' => 'published', 'is_verified' => 1, 'slug' => 'free-co', 'email' => 'free@example.test']);
        $page = $this->asAdmin()->get('admin/edit/' . $free);
        $page->assertSee('Profile type');
        $page->assertDontSee('Verified Business badge.');

        $id = $this->listing(['status' => 'published', 'is_verified' => 1, 'admin_plan' => 'verified']);

        $page = $this->asAdmin()->get('admin/edit/' . $id);
        $page->assertOK();
        $page->assertSee('Verified Business badge.');
        $page->assertSee('Approve, owner pays');

        $this->asAdmin()->post('admin/edit/' . $id . '/verify-badge', [
            csrf_token() => csrf_hash(),
            'note'       => 'Documents received by email',
            'mode'       => 'activate',
            'months'     => '1',
        ])->assertRedirectTo(base_url('admin/edit/' . $id) . '#badge');

        $this->assertSame(DirectoryVerificationModel::STATE_ACTIVE, $this->verifications->forListing($id)['state']);
        $this->asAdmin()->get('admin/edit/' . $id)->assertSee('Active until');
        $this->asAdmin()->get('admin/verifications?state=active')->assertSee('Admin note: Documents received by email');
    }

    private function sendPreview(int $id): string
    {
        service('email')->archive = [];
        $this->asAdmin()->post('admin/send-preview/' . $id, [csrf_token() => csrf_hash()])->assertRedirect();
        preg_match('#/preview/([A-Za-z0-9_-]{20,})#', html_entity_decode((string) (service('email')->archive['body'] ?? '')), $m);
        $this->assertNotEmpty($m[1] ?? '', 'preview email sent');

        return $m[1];
    }

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Smile Studio',
            'contact_person'   => 'Thandi Mokoena',
            'email'            => 'owner@example.test',
            'slug'             => 'smile-studio',
            'status'           => 'pending',
            'country'          => 'South Africa',
            'description'      => '<p>A friendly family dental practice in the heart of Rosebank.</p>',
            'description_text' => 'A friendly family dental practice in the heart of Rosebank.',
        ], true);
    }

    /** Two documents written to private storage, as the upload trait leaves them. */
    private function documentsOnDisk(int $listingId): array
    {
        $dir = DirectoryVerificationDocumentModel::storageRoot() . '/' . $listingId;
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $docs = [];
        foreach ([
            DirectoryVerificationDocumentModel::KIND_REGISTRATION => [$this->fixturePrefix . 'reg.pdf', 'application/pdf'],
            DirectoryVerificationDocumentModel::KIND_OWNER_ID     => [$this->fixturePrefix . 'id.png', 'image/png'],
        ] as $kind => [$name, $mime]) {
            file_put_contents($dir . '/' . $name, $kind);
            $docs[$kind] = [
                'name'          => $name,
                'mime'          => $mime,
                'bytes'         => strlen($kind),
                'sha256'        => hash('sha256', $kind),
                'original_name' => $name,
            ];
        }

        return $docs;
    }
}
