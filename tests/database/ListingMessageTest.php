<?php

use App\Controllers\Directory;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Services\ListingMessageService;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * "Email this business" (owner, 12 Nov 2026): a visitor writes from the
 * profile, we relay it with Reply-To set to them, they get a receipt, and the
 * business's address is never shown unless the owner switched email_public on.
 *
 * Requires the `tests` database group.
 *
 * @internal
 */
final class ListingMessageTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private int $listingId;

    /** @var list<array<string,mixed>> every email "sent", in order */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->sent = [];
        Events::removeAllListeners('email');
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });

        $categoryId = (int) (new DirectoryCategoryModel())->insert(['name' => 'Dentist', 'slug' => 'dentist', 'group_name' => 'Health & Medical', 'is_active' => 1], true);

        $this->listingId = (int) (new DirectoryListingModel())->insert([
            'type'           => 'facility',
            'display_name'   => 'Smile Co',
            'email'          => 'owner@smile.example.test',
            'category_id'    => $categoryId,
            'slug'           => 'smile-co',
            'status'         => 'published',
            'is_verified'    => 1,
            'address_line'   => '1 Main Road',
            'city'           => 'Johannesburg',
            'province'       => 'Gauteng',
            'country'        => 'South Africa',
            'verified_until' => date('Y-m-d', strtotime('+1 month')),
        ], true);
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        foreach (glob(WRITEPATH . 'uploads/msgtest_*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function testEveryProfileStartsPrivate(): void
    {
        $row = (new DirectoryListingModel())->find($this->listingId);

        $this->assertSame(0, (int) $row['email_public']);
        $this->assertContains('email_public', DirectoryListingModel::OWNER_EDITABLE);
    }

    public function testTheFormPageIsNoindexAndHoldsNoAddress(): void
    {
        $result = $this->get('directory/smile-co/message');

        $result->assertStatus(200);
        $html = html_entity_decode((string) $result->response()->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Write to Smile Co', $html);
        $this->assertStringContainsString('noindex', $html);
        $this->assertStringNotContainsString('owner@smile.example.test', $html);

        $this->get('directory/no-such-place/message')->assertStatus(404);
    }

    public function testAMessageReachesTheBusinessWithReplyToTheVisitorAndAReceipt(): void
    {
        $result = $this->post('directory/smile-co/message', $this->form());

        $result->assertRedirect();
        $this->assertCount(2, $this->sent);

        [$toBusiness, $receipt] = $this->sent;
        $this->assertContains('owner@smile.example.test', (array) $toBusiness['recipients']);
        $this->assertStringContainsString('thandi@example.test', (string) ($toBusiness['headers']['Reply-To'] ?? ''));
        $this->assertStringContainsString('Is Saturday 10:00 free for a check up?', (string) $toBusiness['body']);

        $this->assertContains('thandi@example.test', (array) $receipt['recipients']);
        $this->assertStringNotContainsString('Is Saturday 10:00 free', (string) $receipt['body'], 'the receipt never echoes the message');
        $this->assertStringContainsString('Smile Co', (string) $receipt['body']);

        $log = db_connect()->table('xs_directory_listing_messages')->get()->getResultArray();
        $this->assertCount(1, $log);
        $this->assertSame(ListingMessageService::STATUS_SENT, $log[0]['status']);
        $this->assertSame(hash('sha256', 'thandi@example.test'), $log[0]['sender_hash']);
        $this->assertStringNotContainsString('thandi', json_encode($log));
    }

    public function testInvalidInputComesBackWithErrorsAndSendsNothing(): void
    {
        $result = $this->post('directory/smile-co/message', $this->form(['message' => 'too short', 'consent' => '']));

        $result->assertRedirectTo(base_url('directory/smile-co/message'));
        $errors = session('errors');
        $this->assertArrayHasKey('message', $errors);
        $this->assertArrayHasKey('consent', $errors);
        $this->assertSame([], $this->sent);
    }

    public function testABotIsThankedButNothingIsSent(): void
    {
        // A stamp from one second ago: faster than anyone types.
        $this->post('directory/smile-co/message', $this->form(['message_form' => Directory::messageStamp($this->listingId, time() - 1)]))
            ->assertRedirect();
        $this->post('directory/smile-co/message', $this->form(['message_form' => 'forged']))->assertRedirect();
        $this->post('directory/smile-co/message', $this->form([HONEYPOT_FIELD => 'http://spam.example']))->assertRedirect();

        $this->assertSame([], $this->sent);
    }

    public function testTooManyLinksIsBlockedQuietly(): void
    {
        $this->post('directory/smile-co/message', $this->form([
            'message' => 'Cheap pills https://a.example https://b.example https://c.example buy now',
        ]))->assertRedirect();

        $this->assertSame([], $this->sent);
        $this->assertSame(ListingMessageService::STATUS_BLOCKED, db_connect()->table('xs_directory_listing_messages')->get()->getRowArray()['status']);
    }

    public function testOneIpIsLimitedToFiveAnHour(): void
    {
        $svc = new ListingMessageService();
        $row = (new DirectoryListingModel())->find($this->listingId);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(ListingMessageService::STATUS_SENT, $svc->send($row, null, $this->input('person' . $i . '@example.test'), '10.0.0.1'));
        }
        $this->assertSame(ListingMessageService::THROTTLED, $svc->send($row, null, $this->input('late@example.test'), '10.0.0.1'));
    }

    public function testABranchMessageGoesToTheBranchOnlyOnAPremiumProfile(): void
    {
        $branchId = (int) (new DirectoryPracticeLocationModel())->insert([
            'listing_id' => $this->listingId,
            'name'       => 'Rosebank rooms',
            'email'      => 'branch@smile.example.test',
            'city'       => 'Johannesburg',
            'province'   => 'Gauteng',
            'country'    => 'South Africa',
            'sort_order' => 0,
        ], true);

        $this->get('directory/smile-co/message?branch=' . $branchId)->assertStatus(200);
        $this->post('directory/smile-co/message', $this->form(['branch' => (string) $branchId]))->assertRedirect();
        $this->assertContains('branch@smile.example.test', (array) $this->sent[0]['recipients']);
        $this->get('directory/smile-co/message?branch=' . ($branchId + 99))->assertStatus(404);

        (new DirectoryListingModel())->update($this->listingId, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->get('directory/smile-co/message?branch=' . $branchId)->assertStatus(404);
    }

    public function testOldLogRowsArePurged(): void
    {
        $db = db_connect();
        $db->table('xs_directory_listing_messages')->insertBatch([
            ['listing_id' => $this->listingId, 'sender_hash' => str_repeat('a', 64), 'ip_hash' => str_repeat('b', 64), 'status' => 'sent', 'created_at' => date('Y-m-d H:i:s', strtotime('-91 days'))],
            ['listing_id' => $this->listingId, 'sender_hash' => str_repeat('a', 64), 'ip_hash' => str_repeat('b', 64), 'status' => 'sent', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))],
        ]);

        $this->assertSame(1, (new ListingMessageService())->purgeOld());
        $this->assertSame(1, $db->table('xs_directory_listing_messages')->countAllResults());
    }

    public function testTheOwnerSwitchIsOnTheDetailsPageAndSurvivesOtherPages(): void
    {
        $this->assertContains('email_public', config('ProfileSections')->sections['details']['fields']);

        $svc = new \App\Services\DirectoryListingMutationService();
        (new DirectoryListingModel())->update($this->listingId, ['email_public' => 1]);
        $row   = (new DirectoryListingModel())->find($this->listingId);
        $input = $svc->storedFormInput($row);

        // Another page's save posts the stored value back, so it stays on.
        $this->assertSame('1', $input['email_public']);
    }

    // ----------------------------------------------------------- attachments

    public function testAttachedFilesGoToTheBusinessAndNotToTheReceipt(): void
    {
        $svc     = new ListingMessageService();
        $checked = $svc->checkAttachments([
            $this->upload('Quote request.pdf', "%PDF-1.4\n" . str_repeat('x', 2000), 'application/pdf'),
            $this->upload('x-ray.png', "\x89PNG\r\n\x1A\n" . str_repeat('y', 2000), 'image/png'),
        ]);
        $this->assertSame('', $checked['error']);
        $this->assertCount(2, $checked['files']);

        $row = (new DirectoryListingModel())->find($this->listingId);
        $this->assertSame(ListingMessageService::STATUS_SENT, $svc->send($row, null, $this->input('sam@example.test'), '10.0.0.2', $checked['files']));

        [$toBusiness, $receipt] = $this->sent;
        $this->assertCount(2, $toBusiness['attachments']);
        $this->assertSame('Quote request.pdf', $toBusiness['attachments'][0]['name'][1]);
        $this->assertSame('application/pdf', $toBusiness['attachments'][0]['type']);
        $this->assertStringContainsString('Attached:', (string) $toBusiness['body']);
        $this->assertSame([], $receipt['attachments'], 'the shared mailer must not carry the files into the next email');
        $this->assertStringContainsString('with the 2 files you attached', (string) $receipt['body']);
    }

    public function testNoFileChosenIsNotAnError(): void
    {
        $empty = new UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE);

        $this->assertSame(['files' => [], 'error' => ''], (new ListingMessageService())->checkAttachments([$empty]));
    }

    public function testWrongTypesAndDisguisedFilesAreRefused(): void
    {
        $svc = new ListingMessageService();

        $this->assertStringContainsString('not a supported document', $svc->checkAttachments([$this->upload('setup.exe', 'MZ' . str_repeat('x', 100), 'application/x-dosexec')])['error']);
        // Legacy Word can carry macros, and we would be mailing it from our domain.
        $this->assertStringContainsString('not a supported document', $svc->checkAttachments([$this->upload('old.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat('x', 100), 'application/msword')])['error']);
        $this->assertStringContainsString('contents are something else', $svc->checkAttachments([$this->upload('fake.pdf', 'MZ not a pdf at all', 'application/pdf')])['error']);
    }

    public function testSizeAndCountLimits(): void
    {
        $svc = new ListingMessageService();
        $pdf = fn (string $name, int $bytes) => $this->upload($name, "%PDF-1.4\n" . str_repeat('x', $bytes), 'application/pdf');

        $this->assertStringContainsString('The limit is', $svc->checkAttachments([$pdf('big.pdf', ListingMessageService::MAX_FILE_BYTES)])['error']);
        $this->assertStringContainsString('at most 3', $svc->checkAttachments([$pdf('a.pdf', 10), $pdf('b.pdf', 10), $pdf('c.pdf', 10), $pdf('d.pdf', 10)])['error']);
        $four = 4 * 1024 * 1024;
        $this->assertStringContainsString('10 MB together', $svc->checkAttachments([$pdf('a.pdf', $four), $pdf('b.pdf', $four), $pdf('c.pdf', $four)])['error']);
    }

    public function testTheFileNameIsCleanedButKeepsItsCheckedExtension(): void
    {
        $checked = (new ListingMessageService())->checkAttachments([
            $this->upload("../../evil\r\nBcc: x<script>.pdf", "%PDF-1.4\n", 'application/pdf'),
        ]);

        $this->assertSame('', $checked['error']);
        $this->assertSame('evilBcc xscript.pdf', $checked['files'][0]['name']);
    }

    public function testTheFormTakesFiles(): void
    {
        $html = (string) $this->get('directory/smile-co/message')->response()->getBody();

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="attachments[]" multiple', $html);
    }

    /** A file behind an UploadedFile that believes it arrived over HTTP. */
    private function upload(string $name, string $bytes, string $mime): UploadedFile
    {
        $path = WRITEPATH . 'uploads/msgtest_' . bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);

        return new class ($path, $name, $mime, strlen($bytes), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }

            public function getMimeType(): string
            {
                return (string) $this->originalMimeType;
            }
        };
    }

    /** @return array<string,string> */
    private function form(array $over = []): array
    {
        return $over + [
            csrf_token()   => csrf_hash(),
            'message_form' => Directory::messageStamp($this->listingId, time() - 10),
            'name'         => 'Thandi Mokoena',
            'email'        => 'thandi@example.test',
            'phone'        => '082 555 0101',
            'message'      => 'Is Saturday 10:00 free for a check up? Thank you.',
            'consent'      => '1',
        ];
    }

    /** @return array{name:string,email:string,phone:string,message:string} */
    private function input(string $email): array
    {
        return ['name' => 'Sam', 'email' => $email, 'phone' => '', 'message' => 'Hello, do you have space next week please?'];
    }
}
