<?php

use App\Libraries\ListingText;
use App\Libraries\RichText;
use App\Models\DirectoryListingModel;
use App\Services\Description\DescriptionDraftService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\DescriptionTemplates;

/**
 * "Help me write this": the template draft of a business description.
 *
 * No database: the facts are what DescriptionHelper::draft() would collect
 * from the form.
 *
 * @internal
 */
final class DescriptionDraftServiceTest extends CIUnitTestCase
{
    private DescriptionDraftService $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new DescriptionDraftService(new DescriptionTemplates());
    }

    public function testAFullDraftUsesTheOwnersOwnAnswers(): void
    {
        $text = $this->plain([
            'type'              => 'practice',
            'name'              => 'Sandton Smiles',
            'category'          => 'Dentist',
            'city'              => 'Johannesburg',
            'suburb'            => 'Sandton',
            'customer_location' => 'visit',
            'services'          => ['Teeth Whitening', 'Check ups'],
            'tags'              => ['Braces'],
            'features'          => ['Medical aid accepted', 'Parking available'],
        ]);

        $this->assertStringStartsWith('Sandton Smiles is a dentist', $text);
        $this->assertStringContainsString('Sandton, Johannesburg', $text);
        $this->assertStringContainsString('teeth whitening and check ups', $text);
        // Areas of focus are not services, so they get their own sentence.
        $this->assertMatchesRegularExpression('/(areas of focus include|special interest in) braces/', $text);
        $this->assertStringContainsString('medical aid accepted and parking available', $text);
    }

    public function testNameAndCategoryAreEnoughForADraftLongEnoughToSave(): void
    {
        foreach (array_keys(DirectoryListingModel::TYPES) as $type) {
            $text = $this->plain(['type' => $type, 'name' => 'Ubuntu', 'category' => 'Children & Youth']);

            $this->assertGreaterThanOrEqual(ListingText::DESCRIPTION_MIN, mb_strlen($text), $type);
            $this->assertStringNotContainsString('{', $text, $type);
            $this->assertStringNotContainsString('  ', $text, $type);
        }
    }

    public function testACategoryThatIsAFieldOfWorkGetsANoun(): void
    {
        $intro = fn (string $category, string $type = 'practice'): string => explode('.', $this->plain(['type' => $type, 'name' => 'X', 'category' => $category]))[0];

        $this->assertSame('X is a dentist', $intro('Dentist'));
        $this->assertSame('X is a hair salon', $intro('Hair Salon'));
        $this->assertSame('X is a clothing and apparel business', $intro('Clothing & Apparel'));
        $this->assertSame('X is a cleaning services business', $intro('Cleaning Services'));
        $this->assertSame('X is a home decor business', $intro('Home Decor'));
        $this->assertSame('X is an estate agent', $intro('Estate Agent'));
        $this->assertSame('X is a heritage site and landmark', $intro('Heritage Site & Landmark', 'place'));
    }

    public function testArticlesFollowTheSound(): void
    {
        $intro = fn (string $category): string => explode('.', $this->plain(['name' => 'X', 'category' => $category]))[0];

        $this->assertSame('X is a university', $intro('University'));
        $this->assertSame('X is a urologist', $intro('Urologist'));
        $this->assertSame('X is an optometrist', $intro('Optometrist'));
        $this->assertSame('X is an IT support business', $intro('IT Support'));
        $this->assertSame('X is an NGO and nonprofit business', $intro('NGO & Nonprofit'));
        $this->assertSame('X is a DJ and entertainment business', $intro('DJ & Entertainment'));
    }

    public function testNothingToWriteFromGivesNothing(): void
    {
        $this->assertSame('', $this->writer->draft(['name' => 'Sandton Smiles']));
        $this->assertSame('', $this->writer->draft(['category' => 'Dentist']));
    }

    public function testCausesAreDescribedAsWhatTheyAre(): void
    {
        $text = $this->plain(['type' => 'foundation', 'name' => 'Ubuntu Trust', 'category' => 'Health & HIV Support', 'city' => 'Durban']);

        $this->assertStringContainsString('Ubuntu Trust is a foundation', $text);
        $this->assertStringContainsString('health and HIV support', $text);
        $this->assertStringContainsString('Durban', $text);
    }

    public function testServiceAreasOnlyWhenTheBusinessTravels(): void
    {
        $facts = ['name' => 'Fix It', 'category' => 'Plumber', 'service_areas' => ['Randburg', 'Fourways']];

        $this->assertStringNotContainsString('Randburg', $this->plain($facts + ['customer_location' => 'visit']));
        $this->assertStringContainsString('Randburg and Fourways', $this->plain($facts + ['customer_location' => 'travel']));
        $this->assertStringContainsString('Randburg and Fourways', $this->plain($facts + ['customer_location' => 'both']));
    }

    public function testNoDashesOrHyphensReachTheDraft(): void
    {
        $text = $this->plain([
            'name'              => 'Glow',
            'category'          => 'Hair Salon',
            'customer_location' => 'travel',
            'service_areas'     => ['Rosebank'],
            'services'          => ['Wash-and-blow', 'Cut — and style', 'Colour – full head'],
            'features'          => ['Walk-ins welcome', 'Online / video consultations'],
        ]);

        $this->assertDoesNotMatchRegularExpression('/[\x{2010}-\x{2015}-]/u', $text);
        $this->assertStringContainsString('walk ins welcome', $text);
        $this->assertStringContainsString('online or video consultations', $text);
    }

    public function testEveryTemplateLineIsFreeOfDashes(): void
    {
        $config = new DescriptionTemplates();
        $lines  = array_values($config->kinds);
        foreach ($config->templates as $slots) {
            foreach ($slots as $templates) {
                array_push($lines, ...$templates);
            }
        }

        foreach ($lines as $line) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2010}-\x{2015}-]/u', $line);
        }
    }

    public function testEveryFamilyEndsEachSlotWithAFallbackItCanAlwaysUse(): void
    {
        foreach ((new DescriptionTemplates())->templates as $family => $slots) {
            foreach (['intro', 'closing'] as $slot) {
                $last = end($slots[$slot]);
                preg_match_all('/\{(\w+)\}/', $last, $m);
                $this->assertEmpty(array_diff($m[1], ['name', 'a_category', 'category', 'a_kind']), "{$family}.{$slot}");
            }
        }
    }

    public function testTheSameBusinessAlwaysGetsTheSameDraft(): void
    {
        $facts = ['name' => 'Sandton Smiles', 'category' => 'Dentist', 'services' => ['Fillings']];

        $this->assertSame($this->writer->draft($facts), $this->writer->draft($facts));
    }

    public function testDifferentBusinessesDoNotAllReadTheSame(): void
    {
        $drafts = [];
        foreach (['Glow', 'Shine', 'Lux', 'Bliss', 'Halo', 'Aura', 'Muse', 'Nova'] as $name) {
            $drafts[] = str_replace($name, 'X', $this->plain(['name' => $name, 'category' => 'Hair Salon', 'services' => ['Cuts']]));
        }

        $this->assertGreaterThan(1, count(array_unique($drafts)));
    }

    public function testTheDraftStaysUnderTheCap(): void
    {
        $long = static fn (string $p): array => array_map(static fn (int $i): string => $p . ' ' . str_repeat('x', 50) . $i, range(1, 10));

        $text = $this->plain([
            'name'              => str_repeat('Long Name ', 20),
            'category'          => 'Plumber',
            'city'              => 'Johannesburg',
            'customer_location' => 'travel',
            'service_areas'     => $long('Area'),
            'services'          => $long('Service'),
            'features'          => $long('Feature'),
        ]);

        $this->assertLessThanOrEqual(RichText::MAX_PLAIN_LENGTH, mb_strlen($text));
    }

    public function testOwnerTextIsEscaped(): void
    {
        $html = $this->writer->draft(['name' => 'A&B <b>Plumbing</b>', 'category' => 'Plumber']);

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('A&amp;B', $html);
    }

    /** @param array<string,mixed> $facts */
    private function plain(array $facts): string
    {
        return RichText::toPlainText($this->writer->draft($facts));
    }
}
