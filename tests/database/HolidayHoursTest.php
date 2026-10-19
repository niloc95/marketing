<?php

use App\Libraries\PublicHolidays;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use App\Services\DirectorySettings;
use App\Services\HolidayHoursService;
use App\Services\ListingQualityService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * Public holiday hours through the real write paths, search, the profile
 * page, the quality score and the nightly reminder. PublicHolidaysTest covers
 * the calendar and the rules underneath.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class HolidayHoursTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $categoryId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_hours');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert(
            ['name' => 'Hardware Stores', 'slug' => 'hardware-stores', 'group_name' => 'Home Services', 'is_active' => 1],
            true
        );
        $this->sent = [];
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        parent::tearDown();
    }

    // ------------------------------------------------------------- saving

    public function testAnOwnerSaveStoresTheAnswerAndTheSearchColumns(): void
    {
        $id     = $this->listing();
        $result = $this->owner($id, ['mode' => 'open', 'open' => '09:00', 'close' => '13:00', 'except' => ['christmas' => ['mode' => 'closed']]]);
        $this->assertTrue($result['ok'] ?? false, json_encode($result));

        $row = $this->listings->find($id);
        $this->assertSame('open', $row['holiday_hours']);
        $this->assertSame('closed', hours_holiday_rule($row['trading_hours'])['except']['christmas']['mode']);
        $this->assertNotSame('', (string) $row['holiday_open_dates']);
        $this->assertCount(7, hours_decode($row['trading_hours']), 'the week is untouched');

        // Clearing the answer clears the columns.
        $this->owner($id, ['mode' => '']);
        $row = $this->listings->find($id);
        $this->assertNull($row['holiday_hours']);
        $this->assertNull($row['holiday_open_dates']);
    }

    public function testTheOwnerFormOffersTheAnswer(): void
    {
        $id   = $this->listing(['trading_hours' => hours_encode(['holidays' => ['mode' => 'closed']])]);
        $html = (string) $this->withSession([App\Controllers\Manage::SESSION_KEY => $id])->get('manage/edit')->response()->getBody();

        $this->assertMatchesRegularExpression('/<option value="closed" selected>Closed<\/option>/', $html);
        $this->assertStringContainsString('name="hours[holidays][except][christmas][mode]"', $html);
        $this->assertStringContainsString('Different on some holidays?', $html);
    }

    // ------------------------------------------------------------- search

    public function testTheFilterFindsOnlyBusinessesThatSaidTheyAreOpen(): void
    {
        $open    = $this->listing(['slug' => 'open-one', 'trading_hours' => hours_encode(['holidays' => ['mode' => 'open', 'open' => '09:00', 'close' => '13:00', 'except' => ['christmas' => ['mode' => 'closed']]]])]);
        $usual   = $this->listing(['slug' => 'usual-one', 'trading_hours' => hours_encode($this->weekdays() + ['holidays' => ['mode' => 'usual']])]);
        $closed  = $this->listing(['slug' => 'closed-one', 'trading_hours' => hours_encode(['holidays' => ['mode' => 'closed']])]);
        $silent  = $this->listing(['slug' => 'silent-one', 'trading_hours' => hours_encode($this->weekdays())]);

        $ids = static fn (array $r): array => array_map('intval', array_column($r['items'], 'id'));
        $svc = new DirectoryService();

        $any = $ids($svc->browse(['holidays' => '1', 'today' => '2026-10-19']));
        sort($any);
        $this->assertSame([$open, $usual], $any, 'a normal day: anyone open on holidays');

        // Christmas 2026 is a Friday: the usual-hours shop is open, the other
        // has an exception, and nobody silent is claimed.
        $this->refreshDates('2026-10-19');
        $this->assertSame([$usual], $ids($svc->browse(['holidays' => '1', 'today' => '2026-12-25'])));
        unset($closed, $silent);
    }

    public function testTheResultsPageOffersTheChip(): void
    {
        $this->listing(['trading_hours' => hours_encode(['holidays' => ['mode' => 'open']])]);

        $html = (string) $this->get('directory')->response()->getBody();
        $this->assertStringContainsString('holidays=1', $html);

        $html = (string) $this->get('directory?holidays=1')->response()->getBody();
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('noindex', $html, 'a filtered view is not indexed');
    }

    // ------------------------------------------------------------ profile

    public function testTheProfileShowsTheAnswerAndTheExceptions(): void
    {
        $this->listing(['slug' => 'shop', 'trading_hours' => hours_encode($this->weekdays() + [
            'holidays' => ['mode' => 'open', 'open' => '09:00', 'close' => '13:00', 'note' => 'Skeleton staff', 'except' => ['christmas' => ['mode' => 'closed']]],
        ])]);

        $text = html_entity_decode(strip_tags((string) $this->get('directory/shop')->response()->getBody()));
        $this->assertStringContainsString('Public holidays', $text);
        $this->assertStringContainsString('09:00–13:00', $text);
        $this->assertStringContainsString('Skeleton staff', $text);
        $this->assertStringContainsString('Christmas Day: Closed', $text);
    }

    public function testAnUnansweredProfileSaysToCallAhead(): void
    {
        $this->listing(['slug' => 'quiet', 'trading_hours' => hours_encode($this->weekdays())]);

        $text = strip_tags((string) $this->get('directory/quiet')->response()->getBody());
        $this->assertStringContainsString('Not given. Hours may differ, so call ahead', $text);
        $this->assertStringNotContainsString('—', $text);
    }

    // ------------------------------------------------------------ quality

    public function testAnsweringCountsAsADetail(): void
    {
        $quality = new ListingQualityService();
        $counts  = ['photos' => 0, 'services' => 0, 'attributes' => 0, 'tags' => 0];
        $item    = static fn (array $r): array => array_values(array_filter($r['items'], static fn ($i) => $i['key'] === 'features'))[0];

        $without = $quality->evaluate($this->listings->find($this->listing()), $counts);
        $with    = $quality->evaluate($this->listings->find($this->listing([
            'trading_hours' => hours_encode($this->weekdays() + ['holidays' => ['mode' => 'closed']]),
        ])), $counts);

        $this->assertSame($item($without)['earned'] + ListingQualityService::PTS_ATTRIBUTE, $item($with)['earned']);
        $this->assertSame($without['max'], $with['max'], 'no new line: the total stays 100');
    }

    // ----------------------------------------------------------- reminder

    public function testTheReminderGoesToOptedInOwnersWhoHaveNotAnswered(): void
    {
        $svc  = new HolidayHoursService(new PublicHolidays([]));
        $due  = $this->listing(['slug' => 'due', 'display_name' => 'Due Hardware', 'email' => 'due@example.test']);
        $this->listing(['slug' => 'answered', 'trading_hours' => hours_encode(['holidays' => ['mode' => 'closed']])]);
        $this->listing(['slug' => 'opted-out', 'marketing_opt_in' => 0]);
        $this->listing(['slug' => 'appointment', 'by_appointment' => 1]);
        $this->listing(['slug' => 'lately', 'holiday_reminded_at' => '2026-11-20 07:05:00']);

        $this->assertSame([], $svc->due('2026-10-19'), 'nothing close enough');

        // 8 December: the Day of Reconciliation is 8 days away.
        $this->assertSame([$due], array_map('intval', array_column($svc->due('2026-12-08'), 'id')));
        $this->assertSame('sent', $svc->remind($this->listings->find($due), '2026-12-08'));

        $this->assertCount(1, $this->sent);
        $this->assertContains('due@example.test', (array) $this->sent[0]['recipients']);
        $this->assertStringContainsString('Day of Reconciliation', (string) $this->sent[0]['subject']);
        $body = html_entity_decode((string) $this->sent[0]['body'], ENT_QUOTES);
        $this->assertStringContainsString('Wednesday 16 December', $body);
        $this->assertStringContainsString('Christmas Day, Friday 25 December', $body, 'the holidays right after it');
        $this->assertStringContainsString('Stop emails about my profile', $body);
        $this->assertStringNotContainsString('—', $body);

        $this->assertNotNull($this->listings->find($due)['holiday_reminded_at']);
        $this->assertSame([], $svc->due('2026-12-08'), 'once, not every night');
    }

    public function testTheAdminListRefusesUnreadableLines(): void
    {
        $settings = new DirectorySettings();

        $this->assertFalse($settings->putExtraHolidays("2026-11-04 Local government elections\nnext Tuesday", 'test')['ok']);
        $this->assertSame([], $settings->extraHolidays(), 'nothing half saved');

        $this->assertTrue($settings->putExtraHolidays('2026-11-04 Local government elections', 'test')['ok']);
        $this->assertSame('2026-11-04', $settings->extraHolidays()[0]['date']);
        $this->assertSame(PublicHolidays::EXTRA, (new PublicHolidays())->on('2026-11-04')['key']);
    }

    // ------------------------------------------------------------ helpers

    /** Monday to Saturday 08:00 to 17:00, Sunday closed, as the form posts it. */
    private function weekdays(): array
    {
        $out = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            $out[$day] = ['open' => '08:00', 'close' => '17:00'];
        }

        return $out + ['sun' => ['closed' => '1']];
    }

    private function refreshDates(string $today): void
    {
        (new HolidayHoursService(new PublicHolidays([])))->refresh($today);
    }

    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Builders Corner',
            'email'            => 'shop-' . bin2hex(random_bytes(3)) . '@example.test',
            'slug'             => 'shop-' . bin2hex(random_bytes(3)),
            'status'           => 'published',
            'is_verified'      => 1,
            'marketing_opt_in' => 1,
            'by_appointment'   => 0,
            'category_id'      => $this->categoryId,
            'city'             => 'Durban',
            'province'         => 'KwaZulu-Natal',
            'description'      => '<p>Tools, timber and paint for the whole of Durban.</p>',
            'description_text' => 'Tools, timber and paint for the whole of Durban.',
            'trading_hours'    => hours_encode($this->weekdays()),
        ], true);
    }

    /** An owner save as the form sends it, with $holidays as hours[holidays]. */
    private function owner(int $id, array $holidays): array
    {
        $post          = $this->withRequiredSections();
        $post['hours'] = $post['hours'] + ['holidays' => $holidays];

        return (new DirectoryListingMutationService())->updateOwn($id, $post + [
            'display_name'   => 'Builders Corner',
            'category_id'    => $this->categoryId,
            'contact_person' => 'Test Owner',
        ]);
    }
}
