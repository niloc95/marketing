<?php

use App\Filters\CsrfGuard;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\FormHealthService;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\ValidListingInput;

/**
 * A failed form lands on its own form, errors shown, typing kept, and form
 * health records what happened.
 *
 * From 14 to 27 Oct 2026 every failed signup was redirected to an /index.php/
 * URL that 404'd: about 18 businesses lost, and nothing noticed. This is the
 * test that would have caught it. Pinned, for each public form:
 *
 * 1. **A refused submission returns to its own form**, never into /index.php/,
 *    the form renders (200) with the typing, and an 'invalid' row records the
 *    field keys only.
 * 2. **A stale CSRF token is not a dead end**: the framework's bare back()
 *    used to drop everything typed. The person goes back to their form with
 *    their typing and a plain message, and a 'csrf' row is written.
 *
 * @internal
 */
final class FormFailureLandingTest extends CIUnitTestCase
{
    use ValidListingInput;
    use FreshDatabase;
    use FeatureTestTrait;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->deleteMatching('form-*');
        $this->category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Kitchens', 'slug' => 'kitchens', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    /**
     * form key => [POST path, session stamp, typed fields (one invalid), the
     * form it must land on, a typed field that must come back, the key the
     * form must name as wrong].
     *
     * @return iterable<string,array{string,string,array<string,string>,string,string,string}>
     */
    public static function forms(): iterable
    {
        yield 'signup' => ['add-profile', 'listing_form_rendered_at', ['plan' => 'free', 'email' => '', 'display_name' => 'Wood & Marble'], 'add-profile?plan=free', 'display_name', 'email'];
        yield 'contact' => ['contact', 'contact_form_rendered_at', ['name' => 'Thandi Mokoena', 'email' => 'not an email', 'message' => 'Hello there, a question.'], 'contact', 'name', 'email'];
        yield 'recommend' => ['recommend', 'recommend_form_rendered_at', ['business_name' => 'Mokoena Bakery', 'note' => ''], 'recommend', 'business_name', 'note'];
        yield 'jobs_post' => ['jobs/post', 'job_form_rendered_at', ['kind' => 'job', 'title' => 'Shop assistant', 'poster_email' => ''], 'jobs/post', 'title', 'poster_email'];
    }

    /**
     * @dataProvider forms
     * @param array<string,string> $typed
     */
    public function testARefusedSubmissionLandsOnItsOwnFormAndIsRecorded(string $path, string $stamp, array $typed, string $form, string $kept, string $wrong): void
    {
        $result = $this->send($path, $stamp, $typed + ($path === 'add-profile' ? $this->signup() : []));

        $result->assertRedirectTo(base_url($form));
        $this->assertStringNotContainsString('/index.php/', $result->response()->getHeaderLine('Location'));
        $this->assertArrayHasKey($wrong, (array) session()->getFlashdata('errors'));

        // The next request, as the browser's session cookie would carry it.
        $page = $this->withSession($_SESSION)->get($form);
        $page->assertStatus(200);
        $this->assertStringContainsString(esc($typed[$kept], 'attr'), (string) $page->response()->getBody(), 'the typing comes back');

        $rows = $this->events();
        $this->assertCount(1, $rows);
        $this->assertSame(config('Forms')->keyFor($path), $rows[0]['form']);
        $this->assertSame('invalid', $rows[0]['outcome']);
        $this->assertContains($wrong, explode(',', (string) $rows[0]['detail']));
        foreach ($typed as $value) {
            if ($value !== '' && strlen($value) > 4) {
                $this->assertStringNotContainsString($value, json_encode($rows), 'nothing typed is stored');
            }
        }
    }

    /**
     * @dataProvider forms
     * @param array<string,string> $typed
     */
    public function testAStaleTokenGoesBackToTheFormWithTheTyping(string $path, string $stamp, array $typed, string $form, string $kept): void
    {
        $result = $this->withSession([$stamp => time() - 60])->withHeaders(['User-Agent' => self::BROWSER])
            ->post($path, $typed + [csrf_token() => 'a token from a page open since this morning']);

        $result->assertRedirectTo(base_url($form));
        $this->assertSame(CsrfGuard::MESSAGE, session()->getFlashdata('error'));
        $old = (array) session()->getFlashdata('old');
        $this->assertSame($typed[$kept], $old[$kept] ?? null);
        $this->assertArrayNotHasKey(csrf_token(), $old, 'the stale token is not echoed back');

        $rows = $this->events();
        $this->assertSame(['csrf'], array_column($rows, 'outcome'));
    }

    public function testASentMessageIsRecordedAsSaved(): void
    {
        $prev                         = $_ENV['directory.adminEmail'] ?? null;
        $_ENV['directory.adminEmail'] = 'admin@example.test';

        try {
            $this->send('contact', 'contact_form_rendered_at', ['name' => 'Thandi', 'email' => 'thandi@example.test', 'subject' => '', 'message' => 'A question about the platform.'])
                ->assertRedirectTo(base_url('contact'));
        } finally {
            if ($prev === null) {
                unset($_ENV['directory.adminEmail']);
            } else {
                $_ENV['directory.adminEmail'] = $prev;
            }
        }

        $this->assertSame(['ok'], array_column($this->events(), 'outcome'));
    }

    public function testABotTrapIsCountedAsABotNotAPerson(): void
    {
        $this->send('contact', 'contact_form_rendered_at', ['name' => 'x', 'email' => 'x@example.test', 'message' => 'buy now', HONEYPOT_FIELD => 'filled']);

        $this->assertSame(['bot'], array_column($this->events(), 'outcome'));
    }

    public function testAThrottledSubmissionIsCountedAsThrottled(): void
    {
        $this->assertSame([], $this->events());
        for ($i = 0; $i < 4; $i++) {
            $this->send('contact', 'contact_form_rendered_at', ['name' => 'Thandi', 'email' => 'thandi@example.test', 'subject' => '', 'message' => 'A question about the platform.']);
        }

        $this->assertContains('throttled', array_column($this->events(), 'outcome'));
    }

    /** @param array<string,mixed> $post */
    private function send(string $path, string $stamp, array $post)
    {
        return $this->withSession([$stamp => time() - 60])->withHeaders(['User-Agent' => self::BROWSER])
            ->post($path, $post + [csrf_token() => csrf_hash()]);
    }

    /** @return array<string,string> */
    private function signup(): array
    {
        return [
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
        ];
    }

    /** @return list<array<string,mixed>> */
    private function events(): array
    {
        return db_connect()->table(FormHealthService::TABLE)->where('source', 'server')->orderBy('id')->get()->getResultArray();
    }
}
