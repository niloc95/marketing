<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Config\Sites;
use Tests\Support\ValidListingInput;
use Tests\Support\WithEnv;

/**
 * A signup on the wrong site is handed to the right one, with what was typed.
 *
 * A profile lives on the site its business country belongs to, whoever adds
 * it and from wherever. So a Cape Town business added on .app, or a London
 * one added on co.za, is not saved where it was typed: the browser is sent to
 * the other site's form, which fills itself in and asks for a submit there.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class CrossHostSignupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ValidListingInput;
    use WithEnv;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private const APP_HOST = 'local.webscheduler.app';

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('sites.app.enabled', 'true');
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food & Drink', 'is_active' => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        parent::tearDown();
    }

    public function testASouthAfricanBusinessAddedOnAppGoesToCoZa(): void
    {
        $result = $this->submitOn(self::APP_HOST, $this->signup());

        $result->assertStatus(303);
        $url = (string) $result->getRedirectUrl();
        $this->assertSame(config(Sites::class)->host(Sites::ZA), parse_url($url, PHP_URL_HOST));
        $this->assertSame(0, (new DirectoryListingModel())->countAllResults(), 'nothing is saved on the wrong site');

        // The other site's form fills itself in, once.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        Services::resetSingle('site');
        $form = $this->withHeaders(['Host' => 'example.com'])->get('add-profile?handover=' . $q['handover']);
        $form->assertOK();
        $this->assertStringContainsString('value="Handover Bakery"', (string) $form->getBody());
        $this->assertStringContainsString('Businesses in South Africa are listed here', (string) $form->getBody());

        Services::resetSingle('site');
        $again = $this->withHeaders(['Host' => 'example.com'])->get('add-profile?handover=' . $q['handover']);
        $this->assertStringNotContainsString('value="Handover Bakery"', (string) $again->getBody());
    }

    public function testABritishBusinessAddedOnCoZaGoesToApp(): void
    {
        $result = $this->submitOn('example.com', $this->signup([
            'country' => 'United Kingdom', 'province' => '', 'region' => 'England',
            'city' => 'London', 'postal_code' => 'SW1A 2AA', 'latitude' => '51.5034', 'longitude' => '-0.1276',
        ]));

        $result->assertStatus(303);
        $this->assertSame(self::APP_HOST, parse_url((string) $result->getRedirectUrl(), PHP_URL_HOST));
    }

    /** @param array<string,mixed> $input */
    private function submitOn(string $host, array $input)
    {
        Services::resetSingle('site');

        return $this->withHeaders(['Host' => $host])
            ->withSession(['listing_form_rendered_at' => time() - 60])
            ->post('add-profile', $input + [csrf_token() => csrf_hash()]);
    }

    /** @param array<string,mixed> $overrides */
    private function signup(array $overrides = []): array
    {
        return $this->withRequiredSections(array_merge([
            'display_name'     => 'Handover Bakery',
            'email'            => 'handover-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'      => $this->categoryId,
            'title'            => 'Ms',
            'contact_person'   => 'Test Owner',
            'consent'          => 1,
            'marketing_opt_in' => '0',
            'country'          => 'South Africa',
            'address_line'     => '1 Adderley Street',
            'city'             => 'Cape Town',
            'postal_code'      => '8001',
            'province'         => 'Western Cape',
            'latitude'         => '-33.9249',
            'longitude'        => '18.4241',
        ], $overrides));
    }
}
