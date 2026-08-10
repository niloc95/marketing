<?php

use App\Models\DirectorySettingModel;
use App\Services\DirectorySettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Directory as DirectoryConfig;

/**
 * The admin-editable settings store: what wins, what is rejected, and what
 * happens when the database is not there.
 *
 * The point of this feature is that changing the price is one edit in one
 * place, so the precedence rules are the thing worth pinning. A leftover .env
 * line silently beating a saved value would break the promise and look exactly
 * like a bug.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class DirectorySettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectorySettingModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new DirectorySettingModel();

        // The cache outlives a test, and the settings map is cached — so a
        // value saved by one test would otherwise be read by the next.
        (new DirectorySettings())->forget();
    }

    protected function tearDown(): void
    {
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    /** A config with known defaults and no reliance on the developer's .env. */
    private function settings(string $default = '29.99', bool $enabled = true): DirectorySettings
    {
        $config                        = new DirectoryConfig();
        $config->verifiedMonthlyAmount = $default;
        $config->verifiedBadgeEnabled  = $enabled;

        return new DirectorySettings($config);
    }

    // ------------------------------------------------------------ precedence

    public function testFallsBackToConfigWhenNothingIsStored(): void
    {
        $settings = $this->settings('29.99');

        $this->assertSame('29.99', $settings->badgePrice());
        $this->assertNotSame(
            DirectorySettings::SOURCE_DATABASE,
            $settings->priceSource(),
            'nothing has been saved, so the value cannot be coming from the database'
        );
    }

    public function testAStoredPriceBeatsConfigAndEnv(): void
    {
        $this->model->put(DirectorySettingModel::BADGE_PRICE, '49.50', 'admin@test');

        $settings = $this->settings('29.99');

        $this->assertSame('49.50', $settings->badgePrice());
        $this->assertSame(DirectorySettings::SOURCE_DATABASE, $settings->priceSource());
    }

    public function testAStoredToggleBeatsConfig(): void
    {
        $this->model->put(DirectorySettingModel::BADGE_ENABLED, '0', 'admin@test');

        // Config says on; the database says off, and the database wins.
        $this->assertFalse($this->settings('29.99', true)->badgeEnabled());
        $this->assertSame(DirectorySettings::SOURCE_DATABASE, $this->settings()->enabledSource());
    }

    public function testSavingReportsWhoChangedItAndWhen(): void
    {
        $this->settings()->save(['badge_price' => '35.00', 'badge_enabled' => '1'], 'admin@10.0.0.1');

        $change = $this->settings()->lastChange(DirectorySettingModel::BADGE_PRICE);
        $this->assertSame('admin@10.0.0.1', $change['by']);
        $this->assertNotEmpty($change['at']);
    }

    public function testNeverSetMeansNoChangeRecord(): void
    {
        $this->assertNull($this->settings()->lastChange(DirectorySettingModel::BADGE_PRICE));
    }

    // ------------------------------------------------------------ normalising

    /**
     * R29,99 is how the price is written here, and PHP reads '29,99' as 29.0.
     * The admin form has to apply the same rules the config getter does.
     */
    public function testSavedPricesAreNormalisedTheSameWayConfigValuesAre(): void
    {
        foreach ([
            '29,99'    => '29.99',
            'R29,99'   => '29.99',
            'R 29,99'  => '29.99',
            '29.99'    => '29.99',
            '35'       => '35.00',
            '1,299.00' => '1299.00',
        ] as $typed => $expected) {
            $settings = $this->settings();
            $result   = $settings->save(['badge_price' => (string) $typed, 'badge_enabled' => '1'], 'admin@test');

            $this->assertTrue($result['ok'], "'{$typed}' should be accepted: " . ($result['errors']['badge_price'] ?? ''));
            $this->assertSame($expected, $settings->badgePrice(), "'{$typed}' should store as {$expected}");
        }
    }

    // ------------------------------------------------------------- validation

    public function testRejectsPricesThatAreNotSellable(): void
    {
        foreach (['0', '0.00', '-5', 'abc', '', 'R', '   '] as $bad) {
            $settings = $this->settings();
            $result   = $settings->save(['badge_price' => $bad, 'badge_enabled' => '1'], 'admin@test');

            $this->assertFalse($result['ok'], "'{$bad}' must be refused");
            $this->assertArrayHasKey('badge_price', $result['errors']);
        }
    }

    /** A mistyped extra digit is far likelier than a real five-figure badge. */
    public function testRejectsAnAbsurdlyLargePrice(): void
    {
        $result = $this->settings()->save(['badge_price' => '999999', 'badge_enabled' => '1'], 'admin@test');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('typo', $result['errors']['badge_price']);
    }

    /**
     * Nothing is written when anything is invalid. A form that saved half its
     * fields and then reported an error would leave the operator unsure what
     * had actually taken effect.
     */
    public function testARejectedSaveChangesNothing(): void
    {
        $this->settings()->save(['badge_price' => '35.00', 'badge_enabled' => '1'], 'admin@test');

        $settings = $this->settings();
        $this->assertFalse($settings->save(['badge_price' => 'nonsense', 'badge_enabled' => ''], 'admin@test')['ok']);

        $this->assertSame('35.00', $settings->badgePrice(), 'the old price must survive');
        $this->assertTrue($settings->badgeEnabled(), 'and so must the old toggle');
    }

    // ------------------------------------------------------------------ cache

    /**
     * The map is cached, so a save that did not drop it would show the operator
     * their old value and look like the save had failed.
     */
    public function testSavingIsVisibleImmediatelyDespiteTheCache(): void
    {
        $settings = $this->settings();
        $settings->badgePrice();   // warm the cache

        $settings->save(['badge_price' => '77.00', 'badge_enabled' => '1'], 'admin@test');

        $this->assertSame('77.00', $settings->badgePrice(), 'the same instance must see its own write');
        $this->assertSame('77.00', $this->settings()->badgePrice(), 'and so must a fresh one');
    }

    // ----------------------------------------------------------- known names

    /**
     * The store only accepts names it knows, so a crafted POST cannot invent
     * settings rows to sit there forever.
     */
    public function testUnknownSettingNamesAreRefused(): void
    {
        $this->assertFalse($this->model->put('payfast_passphrase', 'nice try', 'admin@test'));
        $this->assertSame([], $this->model->where('name', 'payfast_passphrase')->findAll());
    }
}
