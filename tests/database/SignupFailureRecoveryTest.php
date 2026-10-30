<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;
use Tests\Support\FreshDatabase;

/**
 * A signup that fails comes back to its own form, errors shown, typing kept.
 *
 * On 26 Oct 2026 Wood & Marble submitted /add-profile twice. Both times the
 * submission was rejected and redirect()->back() sent them to an /index.php/
 * URL that FrontControllerPath 404s, so they never saw why and their typing
 * was gone. Pinned:
 *
 * 1. **Each form mode returns to its own form**, named outright, whatever
 *    previous_url() says (here it is the old /index.php/ form on purpose).
 * 2. **The form shows the errors and refills the fields**, and nothing is saved.
 * 3. **A map pin in the sea says so**: their likely second failure.
 * 4. **The throttle message lands on the form too**, typing kept.
 * 5. **Tokens and traps are never echoed back** into the form.
 *
 * @internal
 */
final class SignupFailureRecoveryTest extends CIUnitTestCase
{
    use ValidListingInput;
    use FreshDatabase;
    use FeatureTestTrait;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Kitchens', 'slug' => 'kitchens', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    /** @return iterable<string,array{array<string,string>,string}> */
    public static function formModes(): iterable
    {
        yield 'free'          => [['plan' => 'free'], 'add-profile?plan=free'];
        yield 'verified'      => [['plan' => 'verified'], 'add-profile'];
        yield 'verified only' => [['plan' => 'verified', 'form_mode' => 'verified-only'], 'add-profile/verified'];
    }

    /**
     * @dataProvider formModes
     * @param array<string,string> $mode
     */
    public function testAFailedSignupReturnsToItsOwnForm(array $mode, string $form): void
    {
        $this->submit(['email' => ''] + $mode)
            ->assertRedirectTo(base_url($form));

        $errors = session()->getFlashdata('errors');
        $this->assertArrayHasKey('email', $errors);
        $this->assertSame('Wood & Marble', session()->getFlashdata('old')['display_name'] ?? null);
        $this->assertSame(0, (new DirectoryListingModel())->countAllResults());
    }

    public function testTheFormShowsTheErrorsAndKeepsTheTyping(): void
    {
        $this->submit(['email' => '', 'plan' => 'free']);

        // The flash the redirect carries, handed to the next request as a
        // browser's session cookie would.
        $html = (string) $this->withSession($_SESSION)->get('add-profile?plan=free')->response()->getBody();

        $this->assertStringContainsString('A valid email is required', $html);
        $this->assertStringContainsString(esc('Wood & Marble', 'attr'), $html, 'the name typed is filled back in');
        $this->assertStringContainsString(esc('68 Richards Drive', 'attr'), $html);
    }

    public function testAPinInTheSeaIsExplained(): void
    {
        $this->submit(['latitude' => '-32.2586848', 'longitude' => '0.9228516'])
            ->assertRedirectTo(base_url('add-profile'));

        $this->assertStringContainsString('not in South Africa', session()->getFlashdata('errors')['address_line'] ?? '');
    }

    /** The other side: their real address in Midrand is never refused. */
    public function testAPinAtTheBusinessPasses(): void
    {
        $this->submit(['email' => '', 'latitude' => '-25.9990', 'longitude' => '28.1260']);

        $this->assertArrayNotHasKey('address_line', session()->getFlashdata('errors'));
    }

    public function testTheThrottleMessageLandsOnTheFormWithTheTypingKept(): void
    {
        Services::injectMock('throttler', new class () {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                return false;
            }

            public function getTokenTime(): int
            {
                return 60;
            }
        });

        $this->submit(['plan' => 'free'])
            ->assertRedirectTo(base_url('add-profile?plan=free'));

        $this->assertStringContainsString('Too many submissions', (string) session()->getFlashdata('error'));
        $this->assertSame('Wood & Marble', session()->getFlashdata('old')['display_name'] ?? null);
    }

    public function testTokensAndTrapsAreNeverEchoedBack(): void
    {
        $this->submit(['email' => '', 'signup_source' => 'forged', 'logo_path' => 'x.webp']);

        $old = session()->getFlashdata('old');
        foreach ([csrf_token(), 'signup_source', 'logo_path', '_has_photo', HONEYPOT_FIELD] as $field) {
            $this->assertArrayNotHasKey($field, $old, $field);
        }
    }

    /**
     * Wood & Marble's own form, near enough, with no photo (so it never
     * saves) and a stale /index.php/ previous_url() like the one they had.
     *
     * @param array<string,string> $overrides
     */
    private function submit(array $overrides = [])
    {
        return $this->withSession([
            'listing_form_rendered_at' => time() - 60,
            '_ci_previous_url'         => base_url('index.php/directory?q=Wood+%26+Marble'),
        ])->post('add-profile', $this->withRequiredSections($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Wood & Marble',
            'email'            => 'owner@woodandmarble.test',
            'category_id'      => (string) $this->category,
            'contact_person'   => 'Test Owner',
            'address_line'     => '68 Richards Drive',
            'suburb'           => 'Halfway House',
            'city'             => 'Midrand',
            'postal_code'      => '1685',
            'province'         => 'Gauteng',
            'country'          => 'South Africa',
            'latitude'         => '-25.9990',
            'longitude'        => '28.1260',
            'consent'          => '1',
            'marketing_opt_in' => '0',
            'plan'             => 'verified',
            csrf_token()       => csrf_hash(),
        ]));
    }
}
