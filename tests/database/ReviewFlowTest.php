<?php

use App\Controllers\Manage;
use App\Controllers\Reviews;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryReviewModel;
use App\Services\DirectoryService;
use App\Services\ReviewService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Customer reviews end to end, against the real schema.
 *
 * Pinned:
 *
 * 1. **Nothing is public until the email is confirmed AND an admin approves.**
 * 2. **One review per person per business**, and the owner cannot review
 *    themselves; both answer exactly like a successful submit.
 * 3. **The listing's totals count published reviews only**, through approve,
 *    reject, hide and reports.
 * 4. **Three distinct reports send a review back to the queue.**
 * 5. **Only the owner can reply**, from their own manage session.
 * 6. **The JSON-LD matches the page**: no aggregateRating without reviews.
 * 7. **Ratings never change the order of search results.**
 * 8. **Retention**: unconfirmed reviews are deleted; declined ones lose the
 *    reviewer's name and email.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ReviewFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private ReviewService $svc;
    private DirectoryReviewModel $reviews;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc     = new ReviewService();
        $this->reviews = new DirectoryReviewModel();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);
    }

    // ---------------------------------------------------------- the two gates

    public function testAReviewWaitsForEmailThenForAnAdmin(): void
    {
        $listing = $this->listing();

        $result = $this->svc->submit($listing, $this->input(), '10.0.0.1');
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        $row = $this->reviews->where('listing_id', $listing['id'])->first();
        $this->assertSame(DirectoryReviewModel::STATUS_UNVERIFIED, $row['status']);

        $body = (string) (service('email')->archive['body'] ?? '');
        $this->assertSame(1, preg_match('#reviews/confirm/([a-f0-9]{64})#', $body, $m), 'the confirm link is in the email');
        $this->assertNotSame($m[1], $row['verify_token'], 'only the hash is stored');
        $this->assertStringNotContainsString('Great job fixing', $this->profileHtml($listing));

        $confirmed = $this->svc->confirm($m[1]);
        $this->assertSame(DirectoryReviewModel::STATUS_PENDING, $confirmed['status']);
        $this->assertNull($this->svc->confirm($m[1]), 'the link works once');
        $this->assertStringNotContainsString('Great job fixing', $this->profileHtml($listing));

        $this->assertTrue($this->svc->approve((int) $row['id']));
        $fresh = (new DirectoryListingModel())->find((int) $listing['id']);
        $this->assertSame(1, (int) $fresh['review_count']);
        $this->assertSame('4.0', (string) $fresh['rating_avg']);

        $html = $this->profileHtml($listing);
        $this->assertStringContainsString('Great job fixing', $html);
        $this->assertStringContainsString('Thandi M.', $html, 'first name and initial');
        $this->assertStringNotContainsString('Mokoena', $html, 'never the full surname');
        $this->assertStringNotContainsString('thandi@example.test', $html, 'never the email');
    }

    public function testValidationRefusesLinksPhoneNumbersAndMissingFields(): void
    {
        $listing = $this->listing();

        $empty = $this->svc->submit($listing, [], '10.0.0.1');
        foreach (['rating', 'reviewer_name', 'reviewer_email', 'body', 'genuine'] as $field) {
            $this->assertArrayHasKey($field, $empty['errors'], $field);
        }

        $link = $this->svc->submit($listing, $this->input(['body' => 'Lovely work, see my blog at www.example.com for the full story please.']), '10.0.0.1');
        $this->assertArrayHasKey('body', $link['errors']);

        $phone = $this->svc->submit($listing, $this->input(['body' => 'Terrible service, call me on 082 555 1234 and I will tell you all about it.']), '10.0.0.1');
        $this->assertArrayHasKey('body', $phone['errors']);

        $this->assertSame(0, $this->reviews->countAllResults());
    }

    public function testOneReviewPerPersonAndNoSelfReviewsLookLikeSuccess(): void
    {
        $listing = $this->listing();
        $this->publishedReview($listing, ['email_hash' => hash('sha256', 'thandi@example.test')]);

        $again = $this->svc->submit($listing, $this->input(), '10.0.0.1');
        $this->assertTrue($again['ok'], 'a second review answers like the first');

        $self = $this->svc->submit($listing, $this->input(['reviewer_email' => $listing['email']]), '10.0.0.1');
        $this->assertTrue($self['ok'], 'the owner\'s email answers like anyone\'s');

        $this->assertSame(1, $this->reviews->where('listing_id', $listing['id'])->countAllResults(), 'nothing new stored');
    }

    public function testAnUnconfirmedReviewCanBeWrittenAgain(): void
    {
        $listing = $this->listing();
        $this->svc->submit($listing, $this->input(), '10.0.0.1');
        $this->svc->submit($listing, $this->input(['body' => 'Second try: they came on time and fixed the leak properly.']), '10.0.0.1');

        $rows = $this->reviews->where('listing_id', $listing['id'])->findAll();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Second try', $rows[0]['body']);
    }

    // ------------------------------------------------------------- totals

    public function testTotalsCountPublishedReviewsOnly(): void
    {
        $listing = $this->listing();
        $five    = $this->publishedReview($listing, ['rating' => 5]);
        $this->publishedReview($listing, ['rating' => 2]);
        $pending = $this->pendingReview($listing, ['rating' => 1]);
        $this->svc->recalculate((int) $listing['id']);

        $this->assertTotals($listing, 2, '3.5');

        $this->assertTrue($this->svc->reject($pending, 'not a customer'));
        $this->assertTotals($listing, 2, '3.5');

        $this->assertTrue($this->svc->hide($five));
        $this->assertTotals($listing, 1, '2.0');
        $this->assertFalse($this->svc->reject($five, ''), 'a hidden review cannot then be rejected');
    }

    public function testThreeDistinctReportsSendAReviewBackToTheQueue(): void
    {
        $listing = $this->listing();
        $id      = $this->publishedReview($listing);
        $this->svc->recalculate((int) $listing['id']);

        $this->svc->report($id, '10.0.0.1', 'fake');
        $this->svc->report($id, '10.0.0.1', 'same person again');
        $this->svc->report($id, '', 'never a customer', (int) $listing['id']);
        $this->svc->report($id, '', 'owner again', (int) $listing['id']);
        $this->assertSame(DirectoryReviewModel::STATUS_PUBLISHED, $this->reviews->find($id)['status'], 'the owner and each visitor count once');

        $this->svc->report($id, '10.0.0.2', 'spam');
        $review = $this->reviews->find($id);
        $this->assertSame(DirectoryReviewModel::STATUS_PENDING, $review['status']);
        $this->assertSame(3, (int) $review['report_count']);
        $this->assertTotals($listing, 0, null);

        $this->assertTrue($this->svc->approve($id), 'an admin can put it back');
        $this->assertTotals($listing, 1, '4.0');
    }

    public function testAnOwnerCannotReportAnotherBusinessesReview(): void
    {
        $listing = $this->listing();
        $other   = $this->listing();
        $id      = $this->publishedReview($listing);

        $this->assertFalse($this->svc->report($id, '', 'competitor', (int) $other['id']));
        $this->assertSame(0, (int) $this->reviews->find($id)['report_count']);
    }

    // -------------------------------------------------------------- replies

    public function testOnlyTheOwnerCanReplyFromTheirSession(): void
    {
        $listing = $this->listing();
        $other   = $this->listing();
        $id      = $this->publishedReview($listing);

        $this->withSession([Manage::SESSION_KEY => (int) $other['id']])
            ->post('manage/reviews/' . $id . '/reply', ['reply' => 'Not my review'] + $this->csrf());
        $this->assertNull($this->reviews->find($id)['owner_reply']);

        $this->withSession([Manage::SESSION_KEY => (int) $listing['id']])
            ->post('manage/reviews/' . $id . '/reply', ['reply' => 'Thank you, Thandi! See you next time.'] + $this->csrf());
        $this->assertSame('Thank you, Thandi! See you next time.', $this->reviews->find($id)['owner_reply']);
        $this->svc->recalculate((int) $listing['id']);
        $this->assertStringContainsString('See you next time', $this->profileHtml($listing));

        $this->assertSame('removed', $this->svc->reply($listing, $id, '   '));
        $this->assertNull($this->reviews->find($id)['owner_reply']);
    }

    // ------------------------------------------------------- HTTP and schema

    public function testTheFormPostsThroughTheBotChecks(): void
    {
        $listing = $this->listing();
        $stamp   = Reviews::formStamp((int) $listing['id'], time() - 10);

        $this->post('reviews/' . $listing['slug'], $this->input(['review_form' => $stamp]) + $this->csrf())
            ->assertRedirect();
        $this->assertSame(1, $this->reviews->where('listing_id', $listing['id'])->countAllResults());

        // Forged stamp: same friendly answer, nothing stored.
        $this->post('reviews/' . $listing['slug'], $this->input([
            'reviewer_email' => 'bot@example.test',
            'review_form'    => (time() - 10) . '.' . str_repeat('a', 64),
        ]) + $this->csrf())->assertRedirect();
        $this->assertSame(1, $this->reviews->where('listing_id', $listing['id'])->countAllResults());
    }

    public function testSchemaHasNoRatingWithoutReviewsAndMatchesThePageWithThem(): void
    {
        $listing = $this->listing();
        $this->assertStringNotContainsString('aggregateRating', $this->profileHtml($listing));

        $this->publishedReview($listing, ['rating' => 5]);
        $this->publishedReview($listing, ['rating' => 4]);
        $this->svc->recalculate((int) $listing['id']);

        $html = $this->profileHtml($listing);
        $this->assertStringContainsString('"aggregateRating":{"@type":"AggregateRating","ratingValue":"4.5","reviewCount":2', $html);
        $this->assertSame(2, substr_count($html, '"@type":"Review"'));
        $this->assertStringContainsString('aria-label="' . esc('4.5 out of 5 stars', 'attr') . '"', $html, 'the visible rating says the same');
    }

    public function testRatingsNeverChangeTheSearchOrder(): void
    {
        $first  = $this->listing(['quality_score' => 80, 'display_name' => 'Alpha Plumbing']);
        $second = $this->listing(['quality_score' => 40, 'display_name' => 'Beta Plumbing']);
        foreach ([5, 5, 5] as $i => $stars) {
            $this->publishedReview($second, ['rating' => $stars, 'email_hash' => hash('sha256', 'r' . $i)]);
        }
        $this->svc->recalculate((int) $second['id']);

        $items = (new DirectoryService())->browse(['category' => 'plumber'])['items'];
        $this->assertSame([(int) $first['id'], (int) $second['id']], array_map(static fn ($l) => (int) $l['id'], $items));
    }

    // ------------------------------------------------------------ retention

    public function testPruneDeletesUnconfirmedAndWipesDeclined(): void
    {
        $listing = $this->listing();
        $old     = date('Y-m-d H:i:s', strtotime('-40 days'));

        $this->reviews->insert($this->row($listing, ['status' => DirectoryReviewModel::STATUS_UNVERIFIED, 'email_hash' => hash('sha256', 'a')]));
        $staleId = (int) $this->reviews->getInsertID();
        $this->db->table('xs_directory_reviews')->where('id', $staleId)->update(['created_at' => $old]);

        $rejected = (int) $this->reviews->insert($this->row($listing, [
            'status' => DirectoryReviewModel::STATUS_REJECTED, 'decided_at' => $old, 'email_hash' => hash('sha256', 'b'),
        ]), true);
        $live = $this->publishedReview($listing, ['email_hash' => hash('sha256', 'c')]);

        $result = $this->svc->prune();
        $this->assertSame(['deleted' => 1, 'wiped' => 1], $result);

        $this->assertNull($this->reviews->withDeleted()->find($staleId));
        $wiped = $this->reviews->find($rejected);
        $this->assertNull($wiped['reviewer_email']);
        $this->assertNull($wiped['reviewer_name']);
        $this->assertNotEmpty($wiped['email_hash'], 'the hash stays, so the one-review rule holds');
        $this->assertNotNull($this->reviews->find($live)['reviewer_email'], 'live reviews keep their details');
    }

    // --------------------------------------------------------------- helpers

    /** @return array<string,string> */
    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    private function profileHtml(array $listing): string
    {
        return (string) $this->get('directory/' . $listing['slug'])->response()->getBody();
    }

    private function assertTotals(array $listing, int $count, ?string $avg): void
    {
        $fresh = (new DirectoryListingModel())->find((int) $listing['id']);
        $this->assertSame($count, (int) $fresh['review_count'], 'review_count');
        $this->assertSame($avg, $fresh['rating_avg'] === null ? null : (string) $fresh['rating_avg'], 'rating_avg');
    }

    /** @param array<string,mixed> $overrides */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'rating'         => '4',
            'reviewer_name'  => 'Thandi Mokoena',
            'reviewer_email' => 'thandi@example.test',
            'body'           => 'Great job fixing our geyser. On time, tidy, and the price matched the quote.',
            'genuine'        => '1',
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function row(array $listing, array $overrides = []): array
    {
        return $overrides + [
            'listing_id'     => (int) $listing['id'],
            'rating'         => 4,
            'body'           => 'Solid work and fair prices, would use again for the next job.',
            'reviewer_name'  => 'Sipho Dlamini',
            'reviewer_email' => 'sipho-' . bin2hex(random_bytes(3)) . '@example.test',
            'email_hash'     => hash('sha256', bin2hex(random_bytes(8))),
            'status'         => DirectoryReviewModel::STATUS_PUBLISHED,
        ];
    }

    private function publishedReview(array $listing, array $overrides = []): int
    {
        return (int) $this->reviews->insert($this->row($listing, $overrides + [
            'published_at' => date('Y-m-d H:i:s'),
        ]), true);
    }

    private function pendingReview(array $listing, array $overrides = []): int
    {
        return (int) $this->reviews->insert($this->row($listing, $overrides + [
            'status' => DirectoryReviewModel::STATUS_PENDING,
        ]), true);
    }

    /** @return array<string,mixed> */
    private function listing(array $overrides = []): array
    {
        $listings = new DirectoryListingModel();
        $suffix   = bin2hex(random_bytes(3));
        $id       = (int) $listings->insert($overrides + [
            'type'         => 'practice',
            'display_name' => 'Pipe Co ' . $suffix,
            'email'        => 'owner-' . $suffix . '@example.test',
            'slug'         => 'pipe-co-' . $suffix,
            'status'       => 'published',
            'is_verified'  => 1,
            'category_id'  => $this->categoryId,
            'city'         => 'Durban',
            'province'     => 'KwaZulu-Natal',
            'published_at' => date('Y-m-d H:i:s'),
        ], true);

        return $listings->find($id);
    }
}
