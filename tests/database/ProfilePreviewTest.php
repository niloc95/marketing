<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The private preview of a profile we set up for a customer: the admin sends
 * it, the owner sees the real page while it is still pending, and approving
 * publishes it exactly as the verification link would.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfilePreviewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Dentist', 'slug' => 'dentist', 'group_name' => 'Health & Medical', 'is_active' => 1,
        ], true);
    }

    public function testAdminSendsAPreviewAndTheOwnerSeesThePendingProfile(): void
    {
        $id    = $this->listing();
        $token = $this->sendPreview($id);

        $page = $this->get('preview/' . $token);
        $page->assertOK();
        $page->assertSee('Smile Studio');
        $page->assertSee('This is a private preview of your business profile');
        $page->assertSee('Approve and publish');
        $page->assertSee('noindex, nofollow');
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $page->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $page->response()->getHeaderLine('Cache-Control'));

        // Still not public.
        $this->assertSame('pending', $this->listings->find($id)['status']);
        $this->get('directory/smile-studio')->assertStatus(404);
    }

    public function testApprovingPublishesAndRetiresTheLink(): void
    {
        $id    = $this->listing();
        $token = $this->sendPreview($id);

        $this->post('preview/' . $token . '/approve', [csrf_token() => csrf_hash()])->assertRedirect();

        $row = $this->listings->find($id);
        $this->assertSame('published', $row['status']);
        $this->assertSame('1', (string) $row['is_verified']);
        $this->assertNull($row['verify_token']);

        $this->get('preview/' . $token)->assertStatus(410);
        $page = $this->get('directory/smile-studio');
        $page->assertOK();
        $page->assertDontSee('private preview');
    }

    public function testAskingForChangesMailsTheAdminAndKeepsTheLink(): void
    {
        $prev                         = $_ENV['directory.adminEmail'] ?? null;
        $_ENV['directory.adminEmail'] = 'admin@example.test';

        try {
            $id    = $this->listing();
            $token = $this->sendPreview($id);
            service('email')->archive = [];

            $this->post('preview/' . $token . '/changes', [csrf_token() => csrf_hash(), 'message' => 'Please fix our Saturday hours.'])
                ->assertRedirectTo(base_url('preview/' . $token));

            $mail = service('email')->archive;
            $this->assertStringContainsString('admin@example.test', strtolower(json_encode($mail['recipients'] ?? $mail)));
            $this->assertStringContainsString('Please fix our Saturday hours.', html_entity_decode((string) ($mail['body'] ?? '')));
            $this->assertSame('pending', $this->listings->find($id)['status']);
            $this->get('preview/' . $token)->assertOK();
        } finally {
            if ($prev === null) {
                unset($_ENV['directory.adminEmail']);
            } else {
                $_ENV['directory.adminEmail'] = $prev;
            }
        }
    }

    /**
     * The owner may read the preview for hours before answering, longer than
     * the CSRF cookie lives. The token in the path stands in for CSRF, so a
     * stale form token must not cost them their note or their approval.
     */
    public function testAStaleFormTokenStillReachesUs(): void
    {
        $prev                         = $_ENV['directory.adminEmail'] ?? null;
        $_ENV['directory.adminEmail'] = 'admin@example.test';

        try {
            $id    = $this->listing();
            $token = $this->sendPreview($id);
            service('email')->archive = [];

            $stale = csrf_token();
            $this->post('preview/' . $token . '/changes', [$stale => 'expired-token', 'message' => 'Please add our Sunday hours.'])
                ->assertRedirectTo(base_url('preview/' . $token));
            $this->assertStringContainsString('Please add our Sunday hours.', html_entity_decode((string) (service('email')->archive['body'] ?? '')));

            $this->post('preview/' . $token . '/approve', [$stale => 'expired-token'])->assertRedirect();
            $this->assertSame('published', $this->listings->find($id)['status']);
        } finally {
            if ($prev === null) {
                unset($_ENV['directory.adminEmail']);
            } else {
                $_ENV['directory.adminEmail'] = $prev;
            }
        }
    }

    public function testUnknownTokensAndPublishedProfilesGetNoPreview(): void
    {
        $this->get('preview/not-a-real-token-at-all')->assertStatus(410);

        $id = $this->listing(['status' => 'published']);
        service('email')->archive = [];
        $this->asAdmin()->post('admin/send-preview/' . $id, [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame([], service('email')->archive, 'a live profile is not sent a preview');
    }

    public function testAnExpiredPreviewIsGone(): void
    {
        $id    = $this->listing();
        $token = $this->sendPreview($id);
        $this->listings->update($id, ['verify_expires' => date('Y-m-d H:i:s', time() - 60)]);

        $this->get('preview/' . $token)->assertStatus(410);
        $this->post('preview/' . $token . '/approve', [csrf_token() => csrf_hash()])->assertStatus(410);
        $this->assertSame('pending', $this->listings->find($id)['status']);
    }

    public function testThePreviewIsNeverMeasured(): void
    {
        $prev                          = $_ENV['directory.analyticsId'] ?? null;
        $_ENV['directory.analyticsId'] = 'G-TEST123';

        try {
            $token = $this->sendPreview($this->listing());
            $this->get('preview/' . $token)->assertDontSee('googletagmanager');
            // The control: the same setting does load GA on a public page.
            $this->get('/')->assertSee('googletagmanager');
        } finally {
            if ($prev === null) {
                unset($_ENV['directory.analyticsId']);
            } else {
                $_ENV['directory.analyticsId'] = $prev;
            }
        }
    }

    /** Send through the admin button and pull the raw token out of the email. */
    private function sendPreview(int $id): string
    {
        service('email')->archive = [];
        $this->asAdmin()->post('admin/send-preview/' . $id, [csrf_token() => csrf_hash()])->assertRedirect();

        $body = html_entity_decode((string) (service('email')->archive['body'] ?? ''));
        $this->assertStringContainsString('Preview my business profile', $body);
        $this->assertMatchesRegularExpression('#/preview/([A-Za-z0-9_-]{20,})#', $body);
        preg_match('#/preview/([A-Za-z0-9_-]{20,})#', $body, $m);

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
            'type'           => 'practice',
            'display_name'   => 'Smile Studio',
            'contact_person' => 'Thandi Mokoena',
            'email'          => 'owner@example.test',
            'slug'           => 'smile-studio',
            'status'         => 'pending',
            'country'        => 'South Africa',
            'category_id'    => $this->categoryId,
            'description'      => '<p>A friendly family dental practice in the heart of Rosebank.</p>',
            'description_text' => 'A friendly family dental practice in the heart of Rosebank.',
        ], true);
    }
}
