<?php

use App\Filters\SignupChannel;
use App\Libraries\TokenHash;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectorySettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;
use Tests\Support\FreshDatabase;

/**
 * Verified Business first: the signup form's two pages and the places that push the badge.
 *
 * Pinned:
 *
 * 1. **Free is on every signup page.** /add-profile/verified (where "Get
 *    Verified" links point) preselects Verified with the Free card beside it.
 *    The verified-only form with no Free card was retired on 2 Nov 2026.
 * 2. **A ?via= or invite source never changes the form**: the Free card stays,
 *    ?plan=free still picks Free, and the source is only recorded.
 * 3. **With the badge off, every page is the plain free form.**
 * 4. **A submit from the verified page with no documents still saves the
 *    listing**, and signup_source records the channel, never the posted value.
 * 5. **Confirming a listing lands on the badge panel** only when no
 *    application exists yet.
 *
 * @internal
 */
final class SignupModesTest extends CIUnitTestCase
{
    use ValidListingInput;
    use FreshDatabase;
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->badge(true);
    }

    protected function tearDown(): void
    {
        // Settings are cached for a day, and the table is refreshed between
        // tests but the cache is not: without this, a "badge off" test here
        // would leave the badge off for every test class after it.
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- modes

    public function testTheVerifiedPagePicksVerifiedAndKeepsFreeBesideIt(): void
    {
        // Whitespace-normalised: the small print wraps across lines in the template.
        $html = (string) preg_replace('/\s+/', ' ', $this->page('add-profile/verified?via=marketing-site'));

        $this->assertStringContainsString('data-plan-cards', $html, 'the plan picker is on');
        $this->assertStringContainsString('data-plan-card="free"', $html, 'Free is one click away');
        $this->assertStringContainsString('keep my profile free', $html);
        $this->assertLessThan(strpos($html, 'data-plan-card="free"'), strpos($html, 'data-plan-card="verified"'), 'Verified comes first');
        $this->assertStringContainsString('name="plan" value="verified"', $html);
        $this->assertStringContainsString('name="form_mode" value="verified-page"', $html);
        $this->assertStringContainsString('A South African business profile is free either way', $html);
    }

    /**
     * The form in five numbered section cards, in order, with the step
     * tracker in the sticky score bar linking to each (listing_form_steps()).
     */
    public function testTheFormIsFiveNumberedSectionsWithATracker(): void
    {
        $html = $this->page('add-profile');

        $at = -1;
        foreach (listing_form_steps() as $i => $step) {
            $pos = strpos($html, 'id="form-step-' . $i . '"');
            $this->assertNotFalse($pos, 'section ' . $i);
            $this->assertGreaterThan($at, $pos, 'sections in order');
            $at = $pos;
            $this->assertStringContainsString('class="form-step ' . $step['class'] . '"', $html);
            $this->assertStringContainsString('href="#form-step-' . $i . '"', $html, 'the tracker links to it');
        }
        $this->assertSame(5, substr_count($html, 'class="form-step-num"'));
        $this->assertStringContainsString('<span class="sr-only">Step 3 of 5: </span>Tell people about you', $html, 'the step is announced, not only coloured');
    }

    public function testADirectVisitSeesVerifiedFirstAndPicked(): void
    {
        $html = $this->page('add-profile');

        $verified = strpos($html, 'data-plan-card="verified"');
        $free     = strpos($html, 'data-plan-card="free"');
        $this->assertNotFalse($free, 'a direct visitor can still choose Free');
        $this->assertLessThan($free, $verified, 'Verified comes first');
        $this->assertStringContainsString('name="plan" value="verified"', $html);
        $this->assertStringContainsString('add-profile?plan=free', $html);

        $this->assertStringContainsString('name="plan" value="free"', $this->page('add-profile?plan=free'));
    }

    public function testACampaignSourceIsRememberedButNeverHidesFree(): void
    {
        $this->get('/?via=whatsapp');
        $this->assertSame('whatsapp', $_SESSION[SignupChannel::SESSION_KEY] ?? null, 'the filter remembers ?via=');

        // The bug this replaced: one tagged link and Free was gone for the visit.
        $this->withSession([SignupChannel::SESSION_KEY => 'marketing-site']);
        $this->assertStringContainsString('data-plan-card="free"', $this->page('add-profile'));
        $this->assertStringContainsString('data-plan-card="free"', $this->page('add-profile/verified'));
        $this->assertStringContainsString('name="plan" value="free"', $this->page('add-profile?plan=free'));
    }

    public function testAMalformedViaIsIgnored(): void
    {
        $this->get('/?via=' . rawurlencode('<script>'));

        $this->assertArrayNotHasKey(SignupChannel::SESSION_KEY, $_SESSION ?? []);
    }

    public function testWithTheBadgeOffEveryModeIsThePlainFreeForm(): void
    {
        $this->badge(false);

        foreach (['add-profile' => 'both', 'add-profile/verified' => 'verified-page'] as $uri => $mode) {
            $html = $this->page($uri);
            $this->assertStringNotContainsString('data-plan-card', $html, $uri);
            $this->assertStringContainsString('name="form_mode" value="' . $mode . '"', $html, $uri);
        }

        $home = $this->page('/');
        $this->assertStringNotContainsString('add-profile/verified', $home, 'no button advertises a badge we cannot sell');
        $this->assertStringContainsString('Create your FREE business profile', $home);
    }

    public function testTheSiteButtonsKeepTheFreeListingForADirectVisitor(): void
    {
        $home = $this->page('/');

        // "Add your business" wording, pointing at /add-profile, which decides the
        // mode: a visitor who came straight to the site still gets the Free card.
        $this->assertMatchesRegularExpression('#href="' . preg_quote(base_url('add-profile'), '#') . '" class="btn btn-accent nav-cta">\s*<strong class="sm:hidden">Add business</strong>#', $home);
        $this->assertStringContainsString('<strong class="hidden sm:inline">Add your business</strong>', $home);
        $this->assertStringNotContainsString('<h3>Get started</h3>', $home, 'the footer leads with the badge while it is on sale');

        $direct = $this->page('add-profile');
        $this->assertStringContainsString('data-plan-card="free"', $direct, 'the Free Listing stays available to a direct visitor');
        $this->assertLessThan(strpos($direct, 'data-plan-card="free"'), strpos($direct, 'data-plan-card="verified"'));

        // The same button, for someone who came in on a campaign link.
        $this->withSession([SignupChannel::SESSION_KEY => 'flyer']);
        $this->assertStringContainsString('data-plan-card="free"', $this->page('add-profile'));
    }

    // ------------------------------------------------------ saving and source

    public function testAVerifiedPageSubmitWithoutDocumentsStillSavesWithItsSource(): void
    {
        $category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);

        // A photo is compulsory now. The controller only asks whether one
        // arrived intact (HandlesListingUploads::hasPhoto()), so a gallery
        // entry with no upload error is enough; processing it afterwards
        // fails harmlessly, because it was never really uploaded.
        service('superglobals')->setFilesArray(['gallery' => [
            'name'     => ['shop.jpg'],
            'type'     => ['image/jpeg'],
            'tmp_name' => [tempnam(sys_get_temp_dir(), 'img')],
            'error'    => [UPLOAD_ERR_OK],
            'size'     => [1],
        ]]);

        $result = $this->withSession([SignupChannel::SESSION_KEY => 'whatsapp', 'listing_form_rendered_at' => time() - 60])
            ->post('add-profile', $this->withRequiredSections([
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
                'form_mode'      => 'verified-page',
                'signup_source'  => 'forged-by-the-form',
                csrf_token()     => csrf_hash(),
            ]));
        service('superglobals')->setFilesArray([]);

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
