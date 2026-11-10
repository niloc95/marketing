<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\ServiceMenuService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;
use Tests\Support\FreshDatabase;

/**
 * The private preview of a profile: the admin sends it for one we built, the
 * verification email opens it for one the owner made, the owner sees the real
 * page while it is still pending, and approving publishes it exactly as the
 * verification link would. Also the owner's preview of unsaved edits
 * (manage/preview) and the admin's preview of any profile.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfilePreviewTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use ValidListingInput;

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
        // A profile we built: the owner asks us for changes.
        $page->assertSee('Ask for changes');
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

    // ------------------------------------------------- the verification email

    public function testTheVerificationEmailOpensThePreview(): void
    {
        // An owner who signed up themselves: the public form records terms.
        $id = $this->listing(['terms_accepted_at' => date('Y-m-d H:i:s')]);
        service('email')->archive = [];
        $this->asAdmin()->post('admin/resend-verify/' . $id, [csrf_token() => csrf_hash()])->assertRedirect();

        $body = html_entity_decode((string) (service('email')->archive['body'] ?? ''));
        $this->assertStringContainsString('Preview and publish my profile', $body);
        $this->assertStringNotContainsString('directory/verify/', $body);
        $this->assertSame(1, preg_match('#/preview/([A-Za-z0-9_-]{20,})#', $body, $m));

        $page = $this->get('preview/' . $m[1]);
        $page->assertOK();
        $page->assertSee('Approve and publish');
        // They filled in the form themselves, so they edit after approving.
        $page->assertDontSee('Ask for changes');
        $this->assertSame('pending', $this->listings->find($id)['status']);

        $this->post('preview/' . $m[1] . '/approve', [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame('published', $this->listings->find($id)['status']);
    }

    public function testAVerifyLinkFromAnOlderEmailStillPublishes(): void
    {
        $id = $this->listing(['terms_accepted_at' => date('Y-m-d H:i:s')]);
        service('email')->archive = [];
        $this->asAdmin()->post('admin/resend-verify/' . $id, [csrf_token() => csrf_hash()])->assertRedirect();
        preg_match('#/preview/([A-Za-z0-9_-]{20,})#', html_entity_decode((string) service('email')->archive['body']), $m);

        $this->get('directory/verify/' . $m[1])->assertRedirect();
        $this->assertSame('published', $this->listings->find($id)['status']);
    }

    // ------------------------------------------------ preview of unsaved edits

    public function testManagePreviewShowsUnsavedEditsAndWritesNothing(): void
    {
        $id = $this->listing(['status' => 'published']);
        (new ServiceMenuService())->sync($id, $this->categoryId, ['services_present' => '1', 'services' => [['name' => 'Check up', 'price_label' => 'R450']]]);
        $before = $this->listings->find($id);
        service('email')->archive = [];

        // Each page previews its own fields, with the rest as stored.
        $details = $this->withSession(\Tests\Support\OwnerSession::for($id))->post('business/profile/details/preview', $this->ownerForm([
            'display_name' => 'Smile Studio Rosebank',
        ]));
        $details->assertOK();
        $details->assertSee('Smile Studio Rosebank');
        $details->assertSee('Check up');

        $page = $this->withSession(\Tests\Support\OwnerSession::for($id))->post('business/profile/services/preview', $this->ownerForm([
            'services' => [['name' => 'Whitening', 'price_label' => 'Pay and claim', 'link_url' => 'example.co.za/whitening']],
        ]));

        $page->assertOK();
        $page->assertSee('This is a preview of your unsaved changes');
        $page->assertSee('Whitening');
        $page->assertSee('Pay and claim');
        $page->assertSee('href="https://example.co.za/whitening"');
        $page->assertDontSee('Check up');
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $page->response()->getHeaderLine('Cache-Control'));

        // Nothing written, nothing sent.
        $after = $this->listings->find($id);
        $this->assertSame($before['display_name'], $after['display_name']);
        $this->assertSame($before['updated_at'], $after['updated_at']);
        $this->assertSame(['Check up'], array_column((new ServiceMenuService())->servicesFor($id), 'name'));
        $this->assertSame([], service('email')->archive);
    }

    public function testManagePreviewOfAPendingProfileListsWhatSaveWouldRefuse(): void
    {
        $id   = $this->listing();
        $page = $this->withSession(\Tests\Support\OwnerSession::for($id))->post('business/profile/services/preview', $this->ownerForm([
            'services' => [['name' => 'Consultation', 'link_url' => 'javascript:alert(1)']],
        ]));

        $page->assertOK();
        $page->assertSee('These need fixing before you can save');
        $page->assertSee('That link does not look right');
        $page->assertDontSee('javascript:alert');
    }

    public function testManagePreviewNeedsTheOwnerAndOurOwnPages(): void
    {
        $id = $this->listing(['status' => 'published']);

        $this->post('business/profile/details/preview', $this->ownerForm())->assertRedirectTo(base_url('business/sign-in'));

        $this->withSession(\Tests\Support\OwnerSession::for($id))
            ->withHeaders(['Sec-Fetch-Site' => 'cross-site'])
            ->post('business/profile/details/preview', $this->ownerForm())
            ->assertStatus(403);
    }

    public function testTheManageFormOffersPreviewChanges(): void
    {
        $id   = $this->listing(['status' => 'published']);
        $html = $this->withSession(\Tests\Support\OwnerSession::for($id))->get('business/profile/details')->getBody();

        $this->assertMatchesRegularExpression('#formaction="[^"]*business/profile/details/preview" formtarget="_blank" formnovalidate data-preview-submit>Preview#', $html);
        $this->assertStringContainsString('>View profile</a>', $html);
    }

    // ------------------------------------------------------------ admin preview

    public function testAdminPreviewsAProfileWhateverItsStatus(): void
    {
        $pending = $this->listing();
        $hidden  = $this->listing(['status' => 'unpublished', 'slug' => 'hidden-dental', 'display_name' => 'Hidden Dental']);

        $page = $this->asAdmin()->get('admin/preview/' . $pending);
        $page->assertOK();
        $page->assertSee('Admin preview. Status: Pending');
        $page->assertSee('Smile Studio');
        $page->assertDontSee('Approve and publish');
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->asAdmin()->get('admin/preview/' . $hidden)->assertSee('Hidden Dental');
        $this->assertSame('pending', $this->listings->find($pending)['status']);
    }

    public function testAdminPreviewIsAdminOnly(): void
    {
        $id = $this->listing();
        $this->get('admin/preview/' . $id)->assertRedirect();
    }

    /**
     * The owner edit form as the browser sends it.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function ownerForm(array $fields = []): array
    {
        return $this->withRequiredSections($fields + [
            'display_name'   => 'Smile Studio',
            'category_id'    => $this->categoryId,
            'contact_person' => 'Thandi Mokoena',
        ]);
    }

    /** Send through the admin button and pull the raw token out of the email. */
    /**
     * The admin's own /admin/preview is behind Cloudflare Access, so the
     * customer's link is shown once after sending, for WhatsApp too.
     */
    public function testTheAdminIsShownTheCustomersLinkAfterSending(): void
    {
        $id = $this->listing();

        $edit = $this->asAdmin()->get('admin/edit/' . $id);
        $edit->assertSee('Send preview to customer');
        $edit->assertSee('Admin preview');

        $token = $this->sendPreview($id);

        $page = $this->withSession([
            'dir_admin' => true, AdminFilter::SEEN_KEY => time(),
            'preview_link' => site_url('preview/' . $token), '__ci_vars' => ['preview_link' => 'new'],
        ])->get('admin/edit/' . $id);
        $page->assertSee('Customer preview link');
        $page->assertSee('/preview/' . $token);
        $page->assertSee('Copy link');
    }

    public function testThePreviewLinkIsFlashedOnSend(): void
    {
        $id = $this->listing();
        $response = $this->asAdmin()->post('admin/send-preview/' . $id, [csrf_token() => csrf_hash()]);
        $response->assertRedirect();
        $response->assertSessionHas('preview_link');
        $link = (string) $_SESSION['preview_link'];
        $this->assertMatchesRegularExpression('#/preview/[A-Za-z0-9_-]{20,}$#', $link);
        $this->assertStringNotContainsString('/admin/', $link);
    }

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
