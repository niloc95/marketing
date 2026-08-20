<?php

use App\Libraries\RichText;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The editorial cap on the business description.
 *
 * The number lives in four places that have to agree — RichText::MAX_PLAIN_LENGTH,
 * the counter in public/assets/directory.js, and the two model validation rules —
 * and until now nothing tested any of them. Raising it from 2000 to 5000 was the
 * moment that became uncomfortable: the cap is enforced against the *plain text*
 * so that formatting does not eat into an owner's allowance, and that is exactly
 * the kind of rule that quietly stops working.
 *
 * These tests read the constant rather than hard-coding 5000, so the next change
 * moves one number and they follow. What they pin is the behaviour: the boundary
 * is inclusive, one over is refused with an error attached to the field, markup
 * is not counted, and nothing is silently truncated.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class DescriptionLimitTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;

    /** updateOwn() requires a real category, so every fixture listing gets one. */
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Attorneys',
            'slug'       => 'attorneys',
            'group_name' => 'Legal',
            'is_active'  => 1,
        ], true);
    }

    public function testTheCapIsFiveThousandPlainCharacters(): void
    {
        // Guards the four-way agreement described above. If this number moves,
        // directory.js and the two model rules move with it.
        $this->assertSame(5000, RichText::MAX_PLAIN_LENGTH);
    }

    // ------------------------------------------------------------ owner edit

    public function testAnOwnerMaySaveExactlyTheCap(): void
    {
        $id   = $this->listing();
        $text = str_repeat('a', RichText::MAX_PLAIN_LENGTH);

        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['description' => $text]));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(RichText::MAX_PLAIN_LENGTH, mb_strlen($this->listings->find($id)['description_text']));
    }

    public function testAnOwnerIsRefusedOneCharacterOver(): void
    {
        $id     = $this->listing();
        $stored = $this->listings->find($id)['description'];
        $text   = str_repeat('a', RichText::MAX_PLAIN_LENGTH + 1);

        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['description' => $text]));

        $this->assertFalse($result['ok']);
        // Attached to the field, so the form can highlight it — an unlabelled
        // "could not save" is the failure this check exists to avoid.
        $this->assertArrayHasKey('description', $result['errors']);
        // And nothing was written, rather than a truncated version.
        $this->assertSame($stored, $this->listings->find($id)['description']);
    }

    public function testMarkupDoesNotCountAgainstTheAllowance(): void
    {
        $id = $this->listing();

        // Every character of prose is inside a tag pair, so the HTML is several
        // times longer than the cap while the prose sits exactly on it.
        $prose = str_repeat('word ', RichText::MAX_PLAIN_LENGTH / 5 - 1) . 'word';
        $html  = '<p><strong>' . $prose . '</strong></p>';

        $this->assertGreaterThan(RichText::MAX_PLAIN_LENGTH, mb_strlen($html));

        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['description' => $html]));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertLessThanOrEqual(
            RichText::MAX_PLAIN_LENGTH,
            mb_strlen($this->listings->find($id)['description_text'])
        );
    }

    public function testALongDescriptionSurvivesTheColumnIntact(): void
    {
        $id = $this->listing();

        // The reason description became MEDIUMTEXT. Multibyte prose at the cap,
        // wrapped in markup: TEXT holds 65,535 *bytes*, and MySQL truncates
        // rather than complaining.
        $prose  = str_repeat('é', RichText::MAX_PLAIN_LENGTH);
        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post([
            'description' => '<p>' . $prose . '</p>',
        ]));

        $this->assertTrue($result['ok'], $result['message']);

        $row = $this->listings->find($id);
        $this->assertSame(RichText::MAX_PLAIN_LENGTH, mb_strlen($row['description_text']));
        $this->assertStringEndsWith('</p>', trim($row['description']));
    }

    // ----------------------------------------------------------- admin edit

    public function testAdminGetsTheSameCap(): void
    {
        $id = $this->listing();

        $over = (new DirectoryAdminService())->upsert($id, $this->post([
            'description' => str_repeat('a', RichText::MAX_PLAIN_LENGTH + 1),
        ]));
        $this->assertFalse($over['ok']);
        $this->assertArrayHasKey('description', $over['errors']);

        $at = (new DirectoryAdminService())->upsert($id, $this->post([
            'description' => str_repeat('a', RichText::MAX_PLAIN_LENGTH),
        ]));
        $this->assertTrue($at['ok'], $at['message']);
    }

    // -------------------------------------------------------------- fixtures

    private function listing(): int
    {
        return (int) $this->listings->insert([
            'type'             => 'practice',
            'display_name'     => 'Test Listing',
            'email'            => 'cap@example.test',
            'slug'             => 'test-listing',
            'status'           => 'published',
            'category_id'      => $this->categoryId,
            'description'      => '<p>Original.</p>',
            'description_text' => 'Original.',
        ], true);
    }

    /**
     * The minimum a valid owner submission carries. updateOwn() validates the
     * whole form, not just the fields being changed, so a bare description is
     * rejected for a missing category rather than for its length — which would
     * make these tests pass or fail for the wrong reason.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function post(array $fields): array
    {
        return $fields + [
            'display_name' => 'Test Listing',
            'category_id'  => $this->categoryId,
        ];
    }
}
