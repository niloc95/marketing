<?php

use App\Services\Search\RuleBasedInterpreter;
use App\Services\Search\SearchIntent;
use App\Services\Search\SearchVocabulary;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Reading a typed search into category, place and keywords.
 *
 * No database: the vocabulary is built by hand here, the way
 * DirectoryService::searchVocabulary() builds it from the tables.
 *
 * @internal
 */
final class RuleBasedInterpreterTest extends CIUnitTestCase
{
    private RuleBasedInterpreter $interpreter;

    protected function setUp(): void
    {
        parent::setUp();

        $cat = static fn (string $slug, string $name) => ['slug' => $slug, 'name' => $name];

        $this->interpreter = new RuleBasedInterpreter(new SearchVocabulary(
            categories: [
                'dentist'              => $cat('dentist', 'Dentist'),
                'plumber'              => $cat('plumber', 'Plumber'),
                'attorney'             => $cat('attorney', 'Attorney'),
                'general practitioner' => $cat('general-practitioner', 'General Practitioner'),
                'ophthalmologist'      => $cat('ophthalmologist', 'Ophthalmologist'),
                'pilates studio'       => $cat('pilates-studio', 'Pilates Studio'),
                'it support'           => $cat('it-support', 'IT Support'),
                'builder'              => $cat('builder', 'Builder'),
                'gym fitness centre'   => $cat('gym-fitness-centre', 'Gym & Fitness Centre'),
            ],
            groups: [
                'health medical' => ['slug' => 'health-medical', 'name' => 'Health & Medical'],
            ],
            places: [
                'sandton'      => ['name' => 'Sandton', 'kind' => 'city'],
                'cape town'    => ['name' => 'Cape Town', 'kind' => 'city'],
                'cape'         => ['name' => 'Cape', 'kind' => 'city'],
                'johannesburg' => ['name' => 'Johannesburg', 'kind' => 'city'],
                'rosebank'     => ['name' => 'Rosebank', 'kind' => 'suburb'],
                'central'      => ['name' => 'Central', 'kind' => 'suburb'],
            ],
            serviceWords: ['whitening' => true, 'geyser' => true],
        ), config('Search'));
    }

    private function read(string $q): SearchIntent
    {
        return $this->interpreter->interpret($q);
    }

    public function testTheWholeSentence(): void
    {
        $intent = $this->read('Find a dentist in Sandton that does teeth whitening');

        $this->assertSame('dentist', $intent->categorySlug);
        $this->assertSame('Dentist', $intent->categoryName);
        $this->assertSame('Sandton', $intent->place);
        $this->assertSame(['teeth', 'whitening'], $intent->serviceTerms);
        $this->assertSame([], $intent->terms);
        $this->assertSame('teeth whitening', $intent->keywords());
    }

    public function testAServiceWordIsAServiceWithoutAConnector(): void
    {
        $intent = $this->read('plumber geyser repair');

        $this->assertSame('plumber', $intent->categorySlug);
        $this->assertSame(['geyser'], $intent->serviceTerms);
        $this->assertSame(['repair'], $intent->terms);
    }

    public function testPluralsAndCase(): void
    {
        $this->assertSame('dentist', $this->read('DENTISTS')->categorySlug);
        $this->assertSame('pilates-studio', $this->read('pilates studios')->categorySlug);
    }

    public function testAmpersandsAndAndAreTheSame(): void
    {
        $this->assertSame('gym-fitness-centre', $this->read('gym and fitness centre')->categorySlug);
        $this->assertSame('gym-fitness-centre', $this->read('Gym & Fitness Centre')->categorySlug);
    }

    public function testSynonymsReachTheirCategory(): void
    {
        $this->assertSame('attorney', $this->read('lawyer specialising in property')->categorySlug);
        $this->assertSame(['property'], $this->read('lawyer specialising in property')->serviceTerms);
        $this->assertSame('it-support', $this->read('IT company that can help my small business')->categorySlug);
        $this->assertSame('general-practitioner', $this->read('GP in Sandton')->categorySlug);
    }

    public function testTheLongestPhraseWins(): void
    {
        // "eye doctor" is an ophthalmologist, not a GP and the word "eye".
        $intent = $this->read('eye doctor');
        $this->assertSame('ophthalmologist', $intent->categorySlug);
        $this->assertSame([], $intent->terms);

        // "cape town" is a place before "cape" is.
        $this->assertSame('Cape Town', $this->read('plumber cape town')->place);
    }

    public function testPlaceAliases(): void
    {
        $this->assertSame('Johannesburg', $this->read('plumber jhb')->place);
        $this->assertSame('Johannesburg', $this->read('plumber in Joburg')->place);
        // An alias counts even where no listing is yet: "no plumbers in
        // Pretoria" is a truer answer than ignoring the word.
        $this->assertSame('Pretoria', $this->read('plumber pta')->place);
    }

    public function testProvinces(): void
    {
        $this->assertSame('KwaZulu-Natal', $this->read('attorneys KZN')->province);
        $this->assertSame('KwaZulu-Natal', $this->read('attorneys in kwazulu-natal')->province);
        $this->assertSame('Western Cape', $this->read('dentist WC')->province);
        // Two letters only in capitals: "ec" in a sentence is not a province.
        $this->assertNull($this->read('dentist wc')->province);
    }

    public function testAOneWordSuburbNeedsAPreposition(): void
    {
        // "central heating" is a search for central heating...
        $intent = $this->read('central heating');
        $this->assertNull($intent->place);
        $this->assertSame(['central', 'heating'], $intent->terms);

        // ...but "in Rosebank" is a place.
        $this->assertSame('Rosebank', $this->read('dentist in Rosebank')->place);
        $this->assertNull($this->read('dentist rosebank')->place);
    }

    public function testNearMe(): void
    {
        $intent = $this->read('dentist near me');
        $this->assertTrue($intent->nearMe);
        $this->assertSame('dentist', $intent->categorySlug);
        $this->assertSame('', $intent->keywords());

        $this->assertTrue($this->read('plumber nearby')->nearMe);
        $this->assertFalse($this->read('plumber')->nearMe);
    }

    public function testMainCategory(): void
    {
        $intent = $this->read('health and medical in Sandton');
        $this->assertSame('health-medical', $intent->groupSlug);
        $this->assertNull($intent->categorySlug);
    }

    public function testUnknownWordsPassThrough(): void
    {
        $intent = $this->read('Smile Makers');

        $this->assertFalse($intent->hasFilters());
        $this->assertSame(['smile', 'makers'], $intent->terms);
    }

    public function testEmptyOrFillerOnlyGivesNothing(): void
    {
        foreach (['', '   ', 'find me a good', '!!!'] as $q) {
            $intent = $this->read($q);
            $this->assertFalse($intent->hasFilters(), $q);
            $this->assertSame('', $intent->keywords(), $q);
        }
    }

    public function testASecondCategoryStaysAKeyword(): void
    {
        $intent = $this->read('builder plumber');

        $this->assertSame('builder', $intent->categorySlug);
        $this->assertSame(['plumber'], $intent->terms);
    }

    public function testASynonymForAMissingCategoryIsIgnored(): void
    {
        // "vet" points at a slug this vocabulary does not have.
        $intent = $this->read('vet');

        $this->assertNull($intent->categorySlug);
        $this->assertSame(['vet'], $intent->terms);
    }

    /**
     * Each chip's "remove" link re-reads to exactly what is left.
     */
    public function testToQueryRoundTrips(): void
    {
        $intent = $this->read('Find a dentist in Sandton that does teeth whitening');

        $this->assertSame('Dentist in Sandton offering teeth whitening', $intent->toQuery());

        $noPlace = $this->read($intent->toQuery('place'));
        $this->assertSame('dentist', $noPlace->categorySlug);
        $this->assertNull($noPlace->place);
        $this->assertSame('teeth whitening', $noPlace->keywords());

        $noCategory = $this->read($intent->toQuery('category'));
        $this->assertNull($noCategory->categorySlug);
        $this->assertSame('Sandton', $noCategory->place);

        $noKeywords = $this->read($intent->toQuery('keywords'));
        $this->assertSame('', $noKeywords->keywords());
        $this->assertSame('dentist', $noKeywords->categorySlug);
        $this->assertSame('Sandton', $noKeywords->place);
    }

    public function testEveryConfiguredSynonymIsNormalised(): void
    {
        // A synonym written with capitals or "&" would never match anything.
        foreach (array_keys(config('Search')->categorySynonyms) as $phrase) {
            $this->assertSame(RuleBasedInterpreter::normalise($phrase), $phrase, $phrase);
        }
        foreach (array_keys(config('Search')->placeAliases) as $phrase) {
            $this->assertSame(RuleBasedInterpreter::normalise($phrase), $phrase, $phrase);
        }
    }
}
