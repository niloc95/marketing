<?php

use App\Libraries\RichText;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingSectionModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use App\Services\ListingSectionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * Extra description cards (owner, 19 Nov 2026): Verified and Plus profiles add
 * up to five headed rich text cards of 3,000 plain characters under the
 * description, which is card 1 and capped the same.
 *
 * @internal
 */
final class ListingSectionsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private ListingSectionService $service;
    private DirectoryListingSectionModel $sections;
    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = new ListingSectionService();
        $this->sections   = new DirectoryListingSectionModel();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Orthopaedic Surgeons', 'slug' => 'orthopaedic-surgeons', 'group_name' => 'Health & Medical', 'is_active' => 1,
        ], true);
    }

    // ------------------------------------------------------------- the rules

    public function testSixCardsInAllAndEachHoldsTheDescriptionsCap(): void
    {
        $this->assertSame(5, ListingSectionService::MAX_EXTRA);
        $this->assertSame(3000, ListingSectionService::MAX_PLAIN_LENGTH);
        $this->assertSame(RichText::MAX_PLAIN_LENGTH, ListingSectionService::MAX_PLAIN_LENGTH);
    }

    public function testCardsAreSavedInOrderAndCleaned(): void
    {
        $id = $this->listing('ortho', true);

        $result = $this->service->syncFromForm($id, [
            ['heading' => 'Procedures', 'body' => '<p>Spinal fusion <script>alert(1)</script></p>'],
            ['heading' => '', 'body' => ''],
            ['heading' => 'Conditions we treat', 'body' => 'Back pain'],
        ]);

        $this->assertSame([], $result['errors']);
        $rows = $this->sections->forListing($id);
        $this->assertSame(['Procedures', 'Conditions we treat'], array_column($rows, 'heading'));
        $this->assertStringNotContainsString('script', $rows[0]['body']);
        $this->assertSame('Back pain', $rows[1]['body_text']);
    }

    public function testACardNeedsBothAHeadingAndText(): void
    {
        $id = $this->listing('ortho', true);

        $errors = $this->service->syncFromForm($id, [
            ['heading' => '', 'body' => 'Text without a heading'],
            ['heading' => 'Heading without text', 'body' => '<p> </p>'],
        ])['errors'];

        $this->assertArrayHasKey('sections.0.heading', $errors);
        $this->assertArrayHasKey('sections.1.body', $errors);
        $this->assertSame([], $this->sections->forListing($id));
    }

    public function testTheCapIsCountedOnPlainTextAndTheBoundaryIsInclusive(): void
    {
        $id   = $this->listing('ortho', true);
        $full = str_repeat('a', ListingSectionService::MAX_PLAIN_LENGTH);

        $ok = $this->service->syncFromForm($id, [['heading' => 'Long', 'body' => '<p><strong>' . $full . '</strong></p>']]);
        $this->assertSame([], $ok['errors']);

        $over = $this->service->syncFromForm($id, [['heading' => 'Long', 'body' => $full . 'a']]);
        $this->assertArrayHasKey('sections.0.body', $over['errors']);
    }

    public function testNoMoreThanFiveCards(): void
    {
        $id   = $this->listing('ortho', true);
        $rows = array_map(static fn (int $n): array => ['heading' => 'Card ' . $n, 'body' => 'Text ' . $n], range(1, 6));

        $errors = $this->service->syncFromForm($id, $rows)['errors'];

        $this->assertArrayHasKey('sections', $errors);
        $this->assertSame([], $this->sections->forListing($id));
    }

    public function testAnIdFromAnotherProfileIsNeverUpdated(): void
    {
        $mine   = $this->listing('mine', true);
        $theirs = $this->listing('theirs', true);
        $this->service->syncFromForm($theirs, [['heading' => 'Theirs', 'body' => 'Their text']]);
        $victim = $this->sections->forListing($theirs)[0];

        $this->service->syncFromForm($mine, [['id' => $victim['id'], 'heading' => 'Hijacked', 'body' => 'x']]);

        $this->assertSame('Theirs', $this->sections->find($victim['id'])['heading']);
        $this->assertCount(1, $this->sections->forListing($mine));
    }

    public function testRemovedAndMissingCardsAreDeleted(): void
    {
        $id = $this->listing('ortho', true);
        $this->service->syncFromForm($id, [['heading' => 'Stays', 'body' => 'a'], ['heading' => 'Goes', 'body' => 'b']]);
        [$stays, $goes] = $this->sections->forListing($id);

        $this->service->syncFromForm($id, [
            ['id' => $stays['id'], 'heading' => 'Stays', 'body' => 'a'],
            ['id' => $goes['id'], 'heading' => 'Goes', 'body' => 'b', '_remove' => '1'],
        ]);

        $this->assertSame(['Stays'], array_column($this->sections->forListing($id), 'heading'));
    }

    // -------------------------------------------------------------- the gate

    public function testAFreeProfilesPostedCardsAreIgnored(): void
    {
        $id      = $this->listing('free', false);
        $listing = $this->listings->find($id);

        $result = (new DirectoryListingMutationService())->syncChildRows($id, $listing, [
            ListingSectionService::MARKER => '1',
            'sections' => [['heading' => 'Sneaked in', 'body' => 'Text']],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $this->sections->forListing($id));
    }

    public function testWithoutTheMarkerStoredCardsAreLeftAlone(): void
    {
        $id = $this->listing('ortho', true);
        $this->service->syncFromForm($id, [['heading' => 'Keep me', 'body' => 'Text']]);

        (new DirectoryListingMutationService())->syncChildRows($id, $this->listings->find($id), ['display_name' => 'Other page']);

        $this->assertCount(1, $this->sections->forListing($id));
    }

    public function testTheMarkerAloneClearsEveryCard(): void
    {
        $id = $this->listing('ortho', true);
        $this->service->syncFromForm($id, [['heading' => 'Old', 'body' => 'Text']]);

        (new DirectoryListingMutationService())->syncChildRows($id, $this->listings->find($id), [ListingSectionService::MARKER => '1']);

        $this->assertSame([], $this->sections->forListing($id));
    }

    // ----------------------------------------------------------- the profile

    public function testCardsRenderAsTheirOwnPanelsAfterTheDescription(): void
    {
        $id = $this->listing('ortho', true);
        $this->service->syncFromForm($id, [['heading' => 'Procedures we perform', 'body' => '<p>Knee replacement</p>']]);

        $html = (string) $this->get('directory/ortho')->getBody();

        $about = strpos($html, 'Back and joint care.');
        $card  = strpos($html, 'Procedures we perform');
        $this->assertNotFalse($about);
        $this->assertNotFalse($card);
        $this->assertGreaterThan($about, $card);
        $this->assertStringContainsString('Knee replacement', $html);
    }

    public function testALapsedPlanHidesTheCardsButKeepsThem(): void
    {
        $id = $this->listing('ortho', true);
        $this->service->syncFromForm($id, [['heading' => 'Procedures we perform', 'body' => 'Knee replacement']]);
        $this->listings->update($id, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);

        $html = (string) $this->get('directory/ortho')->getBody();

        $this->assertStringNotContainsString('Procedures we perform', $html);
        $this->assertCount(1, $this->sections->forListing($id));
    }

    // ------------------------------------------------------------ the form

    public function testAVerifiedOwnerGetsTheCardsOnTheDetailsPage(): void
    {
        $id = $this->listing('ortho', true);
        $this->service->syncFromForm($id, [['heading' => 'Procedures we perform', 'body' => 'Knee']]);

        $html = (string) $this->withSession(\Tests\Support\OwnerSession::for($id))->get('business/profile/details')->getBody();

        $this->assertStringContainsString('name="sections_present"', $html);
        $this->assertStringContainsString('value="Procedures we perform"', $html);
        $this->assertStringContainsString('name="sections[__i__][heading]"', $html);
    }

    public function testAFreeOwnerSeesOneLineAboutThePlan(): void
    {
        $id = $this->listing('free', false);

        $html = (string) $this->withSession(\Tests\Support\OwnerSession::for($id))->get('business/profile/details')->getBody();

        $this->assertStringNotContainsString('name="sections_present"', $html);
        $this->assertStringContainsString('more cards, each with its own heading', $html);
    }

    // -------------------------------------------------------------- Discover

    public function testDiscoverFindsAProfileByItsCardsOnlyWhileThePlanIsLive(): void
    {
        $id = $this->listing('ortho', true);
        $this->listing('other', true);
        $this->service->syncFromForm($id, [['heading' => 'Procedures', 'body' => 'Arthroscopy of the shoulder']]);

        $find = static fn (): array => array_column((new DirectoryService())->browse(['q' => 'arthroscopy'])['items'], 'slug');

        $this->assertSame(['ortho'], $find());

        $this->listings->update($id, ['verified_until' => null]);
        $this->assertSame([], $find());
    }

    // ------------------------------------------------------------- fixtures

    private function listing(string $slug, bool $verified): int
    {
        return (int) $this->listings->insert([
            'type'             => 'practice',
            'display_name'     => ucfirst($slug) . ' Practice',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'is_verified'      => 1,
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>Back and joint care.</p>',
            'description_text' => 'Back and joint care.',
            'verified_until'   => $verified ? date('Y-m-d', strtotime('+30 days')) : null,
        ], true);
    }
}
