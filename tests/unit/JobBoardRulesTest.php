<?php

use App\Services\JobBoardService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The Jobs board rules that need no database: the scam-phrase flag and the
 * Google for Jobs markup.
 *
 * The markup matters more than it looks. Google issues manual actions for
 * JobPosting data that is missing required fields or sits on a post that is
 * not a real vacancy, and one manual action removes every job the site hosts
 * from Google for Jobs, not just the bad one.
 *
 * @internal
 */
final class JobBoardRulesTest extends CIUnitTestCase
{
    private JobBoardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new JobBoardService();
    }

    // ---------------------------------------------------------- scam flags

    public function testFeeWordingIsFlagged(): void
    {
        $hits = $this->svc->scamFlags('Earn big! A once-off Registration Fee of R350 secures your spot. WhatsApp only.');

        $this->assertContains('registration fee', $hits);
        $this->assertContains('whatsapp only', $hits);
    }

    public function testAnOrdinaryAdvertIsNotFlagged(): void
    {
        $this->assertSame([], $this->svc->scamFlags(
            'Qualified electrician needed in Durban. Must have a valid wireman\'s licence and own transport.'
        ));
    }

    public function testPhrasesMatchWholeWordsOnly(): void
    {
        // "cryptography" is a skill, not a crypto scheme.
        $this->assertSame([], $this->svc->scamFlags('Senior developer with applied cryptography experience.'));
    }

    // ------------------------------------------------------ JobPosting schema

    public function testALiveJobCarriesEveryRequiredProperty(): void
    {
        $schema = $this->svc->jobPostingSchema($this->job());

        $this->assertSame('JobPosting', $schema['@type']);
        foreach (['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation', 'validThrough'] as $key) {
            $this->assertArrayHasKey($key, $schema, $key . ' is required or strongly recommended by Google');
        }
        $this->assertSame('FULL_TIME', $schema['employmentType']);
        $this->assertSame('ZA', $schema['jobLocation']['address']['addressCountry']);
        $this->assertSame(['@type' => 'QuantitativeValue', 'unitText' => 'MONTH', 'minValue' => 15000, 'maxValue' => 18000], $schema['baseSalary']['value']);
    }

    public function testTheDescriptionIsEscaped(): void
    {
        $schema = $this->svc->jobPostingSchema($this->job(['description' => "Line one\n<script>x</script>"]));

        $this->assertStringNotContainsString('<script>', $schema['description']);
        $this->assertStringContainsString('<br', $schema['description']);
    }

    public function testARemoteJobSaysSo(): void
    {
        $schema = $this->svc->jobPostingSchema($this->job(['is_remote' => 1, 'province' => null, 'city' => null]));

        $this->assertSame('TELECOMMUTE', $schema['jobLocationType']);
        $this->assertArrayHasKey('applicantLocationRequirements', $schema);
        $this->assertArrayNotHasKey('jobLocation', $schema);
    }

    public function testAServiceRequestHasNoJobPostingMarkup(): void
    {
        $this->assertSame([], $this->svc->jobPostingSchema($this->job(['kind' => 'service'])));
    }

    public function testAPostPastItsClosingDateHasNoMarkup(): void
    {
        $this->assertSame([], $this->svc->jobPostingSchema($this->job(['valid_through' => date('Y-m-d', strtotime('-1 day'))])));
        $this->assertSame([], $this->svc->jobPostingSchema($this->job(['status' => 'pending'])));
    }

    public function testSalaryText(): void
    {
        $this->assertSame('R15 000 – R18 000 per month', $this->svc->salaryText($this->job()));
        $this->assertSame('From R200 per hour', $this->svc->salaryText($this->job(['salary_max' => null, 'salary_min' => 200, 'salary_period' => 'hour'])));
        $this->assertSame('', $this->svc->salaryText($this->job(['salary_min' => null, 'salary_max' => null])));
    }

    /** @param array<string,mixed> $overrides */
    private function job(array $overrides = []): array
    {
        return $overrides + [
            'id'              => 7,
            'kind'            => 'job',
            'slug'            => 'electrician',
            'status'          => 'published',
            'title'           => 'Electrician',
            'description'     => 'Wiring and fault finding.',
            'company_name'    => 'Sparks (Pty) Ltd',
            'province'        => 'KwaZulu-Natal',
            'city'            => 'Durban',
            'is_remote'       => 0,
            'employment_type' => 'full_time',
            'salary_min'      => 15000,
            'salary_max'      => 18000,
            'salary_period'   => 'month',
            'published_at'    => date('Y-m-d H:i:s'),
            'valid_through'   => date('Y-m-d', strtotime('+10 days')),
        ];
    }
}
