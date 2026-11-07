<?php

use App\Models\Bookings\BookingCustomerModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\BookingSearch;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\TeamService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The booking search ("Find" in /bookings, BookingSearch): names either way
 * round and by prefix, accents and capitals, a phone number in any form
 * (and abroad), references, typos, the ranking, the member's scope, and two
 * businesses that share nothing. Through the three places that use it:
 * CustomerService::list() (the picker and the Customers list) and
 * AppointmentService::findMatching() (the calendar's Find box).
 *
 * Fixed clock: 1 Jan 2030, 08:00 in Johannesburg. 7 Jan 2030 is a Monday.
 *
 * @internal
 */
final class BookingSearchTest extends CIUnitTestCase
{
    use FreshDatabase;
    use WithEnv;

    private const MONDAY = '2030-01-07';

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $otherListingId;

    private int $sarah;

    private int $james;

    private int $serviceId;

    /** @var array<string,int> customer ids by first name */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Medical Practice', 'slug' => 'medical-practice', 'group_name' => 'Health', 'is_active' => 1,
        ], true);
        $this->listingId      = $this->business('birnam-park-practice');
        $this->otherListingId = $this->business('sunninghill-rooms');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $this->james = (int) $team->saveProvider(null, ['name' => 'James Okafor', 'bookable' => 1], null)['id'];
        foreach ([$this->sarah, $this->james] as $id) {
            $team->saveHours($id, array_fill_keys([1, 2, 3, 4, 5], ['active' => 1, 'start' => '08:00', 'end' => '17:00']), null);
        }
        $result = (new CatalogueService($this->listingId))->saveService(null, [
            'name' => 'Diabetes Management', 'duration_min' => 30, 'price' => '', 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->sarah, $this->james],
        ], null);
        $this->assertSame([], $result['errors']);
        $this->serviceId = (int) $result['id'];

        foreach ([
            ['Naledi', 'Mabaso', '082 555 0121'],
            ['Kevin', 'Pillay', '+27 82 555 0127'],
            ['Rajesh', 'Naidoo', '0825550107'],
            ['Pieter', 'van der Merwe', '0825550101'],
            ['René', 'Müller', '0825550199'],
            ['Wei', 'Ng', '0825550150'],
            ['George', "O'Brien", '0825550151'],
            ['Thandi', 'Smith-Jones', '0825550152'],
        ] as [$first, $last, $phone]) {
            $saved = (new CustomerService($this->listingId))->save(null, [
                'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => strtolower(BookingSearch::fold($first)) . '@example.test',
            ], null);
            $this->assertSame([], $saved['errors']);
            $this->people[$first] = (int) $saved['id'];
        }
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    public function testNamesEitherWayRoundAndByPrefix(): void
    {
        $this->assertSame(['Naledi'], $this->find('nal mab'));
        $this->assertSame(['Naledi'], $this->find('mabaso naledi'));
        $this->assertSame(['Naledi'], $this->find('NALEDI'));
        $this->assertSame(['Pieter'], $this->find('merwe'), 'Any word of the surname');
        $this->assertSame(['Pieter'], $this->find('vandermerwe'), 'Written as one word');
        $this->assertSame(['Thandi'], $this->find('jones'), 'Either half of a double barrelled name');
        $this->assertSame([], $this->find('nal nal'), 'Each name word answers one query word');
    }

    public function testShortSurnamesAndPlaceNamesAreNames(): void
    {
        $this->assertSame(['Wei'], $this->find('ng'), 'Discover drops two letter words; a surname is a surname');
        $this->assertSame(['George'], $this->find('george'), 'Discover drops place names');
        $this->assertSame(['George'], $this->find('obrien'), 'Apostrophes vanish');
        $this->assertSame(['George'], $this->find("o'brien"));
    }

    public function testAccentsAndCapitalsDoNotMatter(): void
    {
        $this->assertSame(['René'], $this->find('rene muller'));
        $this->assertSame(['René'], $this->find('MÜLLER'));
        $this->assertSame('muller', BookingSearch::fold('Müller'));
    }

    public function testAPhoneNumberInAnyForm(): void
    {
        foreach (['0825550121', '082 555 0121', '+27 82 555 0121', '27825550121', '+27 (0)82 555 0121', '082-555-0121'] as $typed) {
            $this->assertSame(['Naledi'], $this->find($typed), $typed);
        }
        $this->assertSame(['Kevin'], $this->find('0825550127'), 'Stored with +27, typed with 0');
        $this->assertContains('Naledi', $this->find('082 555 01'), 'Part of a number');
        $this->assertSame([], $this->find('083 555 0121'));
    }

    public function testAbroadTheBusinessesOwnCountryCodeStandsInForTheZero(): void
    {
        $uk = new BookingSearch($this->listingId, 'GB');
        $this->assertSame('7700900123', $uk->nationalDigits('+44 7700 900123'));
        $this->assertSame('7700900123', $uk->nationalDigits('07700 900123'));
        $this->assertSame('27825550121', $uk->nationalDigits('+27 82 555 0121'), 'Another country keeps its code');
        $this->assertNull($uk->nationalDigits('naledi'));
    }

    public function testAReferenceInAnyForm(): void
    {
        $this->book('09:00', 'Kevin');
        $a  = $this->book('10:00', 'Naledi');
        $id = (int) $a['id'];
        // A later appointment of the same customer: the named one still comes first.
        $this->book('15:00', 'Naledi');

        foreach ([$a['reference'], strtolower($a['reference']), 'BOOK ' . $id, '#' . $id, (string) $id, sprintf('%06d', $id)] as $typed) {
            $this->assertSame([$id], array_slice(array_column($this->appointments()->findMatching($typed), 'id'), 0, 1), $typed);
            $this->assertSame('Naledi', $this->find($typed)[0] ?? null, 'The customer of that appointment: ' . $typed);
        }
    }

    public function testTyposFindThePracticesOwnNames(): void
    {
        $this->assertSame(['Kevin'], $this->find('pilay'));
        $this->assertSame(['Rajesh'], $this->find('naidu'), 'Spelled as it sounds');
        $this->assertSame(['Naledi'], $this->find('naledi mabasso'));
        $this->assertSame([], $this->find('pyl'), 'Three letters are too few for a typo');
        $this->assertSame(['Kevin'], $this->find('kevn'));
    }

    public function testExactThenPrefixThenFuzzyAndRecentFirstWithinARank(): void
    {
        $customers = new CustomerService($this->listingId);
        $pilay     = (int) $customers->save(null, ['first_name' => 'Ashwin', 'last_name' => 'Pilay', 'email' => 'ashwin@example.test'], null)['id'];
        $pilayton  = (int) $customers->save(null, ['first_name' => 'Megan', 'last_name' => 'Pilayton', 'email' => 'megan@example.test'], null)['id'];

        $this->assertSame([$pilay, $pilayton, $this->people['Kevin']], array_column((new BookingSearch($this->listingId))->customers('pilay', null), 'id'));
        $this->assertSame([BookingSearch::EXACT, BookingSearch::PREFIX, BookingSearch::FUZZY], array_column((new BookingSearch($this->listingId))->customers('pilay', null), 'rank'));

        // Two of the same name: the one seen most recently first.
        $second = (int) $customers->save(null, ['first_name' => 'Naledi', 'last_name' => 'Dube', 'email' => 'naledi.dube@example.test'], null)['id'];
        $this->book('09:00', 'Naledi');
        $this->assertSame([$this->people['Naledi'], $second], array_column((new BookingSearch($this->listingId))->customers('naledi', null), 'id'));
    }

    public function testTheCalendarFindsByCustomerOrService(): void
    {
        $a = $this->book('09:00', 'Kevin');
        $b = $this->book('10:00', 'Naledi', $this->james);

        $this->assertSame([(int) $a['id']], array_column($this->appointments()->findMatching('pilay'), 'id'));
        $this->assertSame([(int) $b['id'], (int) $a['id']], array_column($this->appointments()->findMatching('diabetes'), 'id'), 'Newest first');
        $this->assertSame([(int) $b['id']], array_column($this->appointments()->findMatching('+27 82 555 0121'), 'id'));
    }

    public function testAMemberFindsOnlyTheirPeoplesCustomers(): void
    {
        $this->book('09:00', 'Kevin');
        $b = $this->book('10:00', 'Naledi', $this->james);

        $this->assertSame([], $this->find('pilay', [$this->james]), 'Typos too');
        $this->assertSame([], $this->find('0825550127', [$this->james]));
        $this->assertSame(['Naledi'], $this->find('nal', [$this->james]));
        $this->assertSame([], $this->find('nal', []));
        $this->assertSame([(int) $b['id']], array_column($this->appointments([$this->james])->findMatching('diabetes'), 'id'));
        $this->assertSame([], $this->appointments([$this->james])->findMatching('pillay'));
    }

    public function testTwoBusinessesShareNothing(): void
    {
        $a = $this->book('09:00', 'Kevin');
        (new CustomerService($this->otherListingId))->save(null, ['first_name' => 'Kevin', 'last_name' => 'Pillai', 'email' => 'k@example.test'], null);

        $theirs = new BookingSearch($this->otherListingId);
        $this->assertSame([], $theirs->appointments($a['reference'], null), 'Another business\'s reference');
        $this->assertSame([], $theirs->customers('0825550127', null));
        $this->assertCount(1, $theirs->customers('pilay', null), 'Their own Kevin only');
        $this->assertNotContains($this->people['Kevin'], array_column($theirs->customers('kevin', null), 'id'));
    }

    public function testCustomersPastTheKeepingPeriodAreNeverFound(): void
    {
        BookingCustomerModel::forBusiness($this->listingId)->update($this->people['Kevin'], ['purged_at' => '2029-12-01 00:00:00']);

        $this->assertSame([], $this->find('pillay'));
        $this->assertSame([], $this->find('pilay'));
    }

    public function testTheCustomersListPagesRankedResults(): void
    {
        $customers = new CustomerService($this->listingId);
        for ($n = 1; $n <= 5; $n++) {
            $customers->save(null, ['first_name' => 'Sam', 'last_name' => 'Patel' . str_repeat('a', $n), 'email' => 'sam' . $n . '@example.test'], null);
        }
        $page = $customers->list('sam', null, 2, 2);

        $this->assertSame([5, 2, 3], [$page['total'], $page['page'], $page['pages']]);
        $this->assertCount(2, $page['rows']);
        $this->assertSame('Sam', $page['rows'][0]['first_name']);
        $this->assertSame(13, $customers->list('', null)['total'], 'No query: everyone, as before');
    }

    // Helpers

    /**
     * First names found through CustomerService::list(), as the picker and
     * the Customers list ask.
     *
     * @param list<int>|null $scope
     *
     * @return list<string>
     */
    private function find(string $q, ?array $scope = null): array
    {
        return array_column((new CustomerService($this->listingId))->list($q, $scope)['rows'], 'first_name');
    }

    /** @param list<int>|null $scope */
    private function appointments(?array $scope = null): AppointmentService
    {
        return new AppointmentService($this->listings->find($this->listingId), $scope, null, null, $this->now());
    }

    /** @return array{id:?int,hash:?string,reference:?string,errors:array<string,string>} */
    private function book(string $time, string $person, ?int $providerId = null): array
    {
        $result = $this->appointments()->create([
            'provider_id' => $providerId ?? $this->sarah, 'service_id' => $this->serviceId, 'date' => self::MONDAY, 'time' => $time,
            'customer_id' => $this->people[$person],
        ]);
        $this->assertSame([], $result['errors']);

        return $result;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 08:00', new DateTimeZone('Africa/Johannesburg'));
    }

    private function business(string $slug): int
    {
        $id = (int) $this->listings->insert([
            'type'             => 'practice',
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'contact_person'   => 'Bernice Green',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>An endocrinology practice in Birnam Park, Johannesburg, seeing patients by appointment.</p>',
            'description_text' => 'An endocrinology practice in Birnam Park, Johannesburg, seeing patients by appointment.',
        ], true);
        (new BookingSetupService())->turnOn($this->listings->find($id));

        return $id;
    }
}
