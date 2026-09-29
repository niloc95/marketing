<?php

use App\Filters\SignupChannel;
use App\Libraries\TokenHash;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectorySettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Verified Business first: the three signup modes and the places that push the badge.
 *
 * Pinned:
 *
 * 1. **Links we control open the verified-only form.** That means
 *    /add-listing/verified, or any /add-listing visit with a ?via= / invite
 *    source. It has no Free card and no "keep my listing free" link, and it
 *    still says a South African listing is free (the Terms promise it).
 * 2. **A direct /add-listing shows both options**, with Verified first and
 *    preselected. ?plan=free picks Free.
 * 3. **With the badge off, every mode is the plain free form.**
 * 4. **A verified-only submit with no documents still saves the listing**, and
 *    signup_source records the channel, never the posted value.
 * 5. **Confirming a listing lands on the badge panel** only when no
 *    application exists yet.
 *
 * @internal
 */
final class SignupModesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->badge(true);
    }

    // ----------------------------------------------------------------- modes

    public function testTheVerifiedOnlyFormHasNoFreeOption(): void
    {
        // Whitespace-normalised: the small print wraps across lines in the template.
        $html = (string) preg_replace('/\s+/', ' ', $this->page('add-listing/verified'));

        $this->assertStringNotContainsString('data-plan-card="free"', $html);
        $this->assertStringNotContainsString('keep my listing free', $html);
        $this->assertStringNotContainsString('data-plan-cards', $html, 'the plan picker stays off');
        $this->assertStringContainsString('data-plan-card="verified"', $html);
        $this->assertStringContainsString('name="plan" value="verified"', $html);
        $this->assertStringContainsString('value="verified-only"', $html);
        $this->assertStringContainsString('A South African listing is free either way', $html, 'the Terms promise must stay on the page');
    }

    public function testADirectVisitSeesVerifiedFirstAndPicked(): void
    {
        $html = $this->page('add-listing');

        $verified = strpos($html, 'data-plan-card="verified"');
        $free     = strpos($html, 'data-plan-card="free"');
        $this->assertNotFalse($free, 'a direct visitor can still choose Free');
        $this->assertLessThan($free, $verified, 'Verified comes first');
        $this->assertStringContainsString('name="plan" value="verified"', $html);
        $this->assertStringContainsString('add-listing?plan=free', $html);

        $this->assertStringContainsString('name="plan" value="free"', $this->page('add-listing?plan=free'));
    }

    public function testACampaignSourceMakesEveryAddListingVerifiedOnly(): void
    {
        $this->get('/?via=whatsapp');
        $this->assertSame('whatsapp', $_SESSION[SignupChannel::SESSION_KEY] ?? null, 'the filter remembers ?via=');

        $this->withSession([SignupChannel::SESSION_KEY => 'whatsapp']);
        $this->assertStringNotContainsString('data-plan-card="free"', $this->page('add-listing'));
        $this->assertStringNotContainsString('data-plan-card="free"', $this->page('add-listing?plan=free'));
    }

    public function testAMalformedViaIsIgnored(): void
    {
        $this->get('/?via=' . rawurlencode('<script>'));

        $this->assertArrayNotHasKey(SignupChannel::SESSION_KEY, $_SESSION ?? []);
    }

    public function testWithTheBadgeOffEveryModeIsThePlainFreeForm(): void
    {
        $this->badge(false);

        foreach (['add-listing', 'add-listing/verified'] as $uri) {
            $html = $this->page($uri);
            $this->assertStringNotContainsString('data-plan-card', $html, $uri);
            $this->assertStringContainsString('name="form_mode" value="both"', $html, $uri);
        }

        $home = $this->page('/');
        $this->assertStringNotContainsString('add-listing/verified', $home, 'no button advertises a badge we cannot sell');
        $this->assertStringContainsString('List your business', $home);
    }

    public function testTheSiteButtonsGoToTheVerifiedOnlyForm(): void
    {
        $home = $this->page('/');

        $this->assertStringContainsString('href="' . base_url('add-listing/verified') . '" class="btn btn-accent nav-cta">Get verified', $home);
        $this->assertStringNotContainsString('List your business <strong>free</strong>', $home);
    }

    // ------------------------------------------------------ saving and source

    public function testAVerifiedOnlySubmitWithoutDocumentsStillSavesWithItsSource(): void
    {
        $category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);

        $result = $this->withSession([SignupChannel::SESSION_KEY => 'whatsapp', 'listing_form_rendered_at' => time() - 60])
            ->post('add-listing', [
                // A complete South African signup, as in BookingUrlTest::signup():
                // address, the private "Your details" pair, the consent answers,
                // and coordinates so nothing is geocoded over the network.
                'type'             => 'practice',
                'display_name'     => 'Drip Doctors',
                'email'            => 'owner@dripdoctors.test',
                'category_id'      => (string) $category,
                'title'            => 'Mr',
                'contact_person'   => 'Test Owner',
                'address_line'     => '1 Adderley Street',
                'city'             => 'Cape Town',
                'postal_code'      => '8001',
                'province'         => 'Western Cape',
                'country'          => 'South Africa',
                'latitude'         => '-33.9249',
                'longitude'        => '18.4241',
                'consent'          => '1',
                'marketing_opt_in' => '0',
                'plan'           => 'verified',
                'form_mode'      => 'verified-only',
                'signup_source'  => 'forged-by-the-form',
                csrf_token()     => csrf_hash(),
            ]);

        $result->assertRedirect();
        $row = (new DirectoryListingModel())->where('email', 'owner@dripdoctors.test')->first();
        $this->assertNotNull($row, 'no documents never costs anyone their listing: ' . json_encode(session()->getFlashdata('errors')));
        $this->assertSame('whatsapp', $row['signup_source'], 'the session decides the source, not the form');
    }

    // ---------------------------------------------------- after publishing

    public function testConfirmingLandsOnTheBadgePanelOnlyWithoutAnApplication(): void
    {
        [$id, $token] = $this->pendingListing('first@example.test', 'first-co');
        $this->get('directory/verify/' . $token)->assertRedirectTo(base_url('manage/edit') . '#get-verified');

        [$id2, $token2] = $this->pendingListing('second@example.test', 'second-co');
        (new DirectoryVerificationModel())->insert([
            'listing_id' => $id2, 'plan' => DirectoryVerificationModel::PLAN_BADGE,
            'state' => DirectoryVerificationModel::STATE_SUBMITTED, 'amount' => '29.99',
        ]);
        $this->get('directory/verify/' . $token2)->assertRedirectTo(base_url('directory/second-co'));
    }

    // --------------------------------------------------------------- helpers

    private function badge(bool $on): void
    {
        $saved = (new DirectorySettings())->save(['badge_price' => '29.99', 'badge_enabled' => $on ? '1' : ''], 'test');
        $this->assertTrue($saved['ok'], json_encode($saved));
    }

    private function page(string $uri): string
    {
        return (string) $this->get($uri)->response()->getBody();
    }

    /** @return array{0:int,1:string} */
    private function pendingListing(string $email, string $slug): array
    {
        $raw = TokenHash::mint();
        $id  = (int) (new DirectoryListingModel())->insert([
            'type' => 'practice', 'display_name' => ucfirst($slug), 'email' => $email, 'slug' => $slug,
            'status' => 'pending', 'is_verified' => 0, 'city' => 'Durban', 'province' => 'KwaZulu-Natal', 'country' => 'South Africa',
            'verify_token' => TokenHash::hash($raw), 'verify_expires' => date('Y-m-d H:i:s', time() + 3600),
        ], true);

        return [$id, $raw];
    }
}
