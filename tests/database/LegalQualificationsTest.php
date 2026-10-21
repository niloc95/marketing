<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Qualifications on a profile, in the Terms and the privacy policy.
 *
 * Pinned: qualifications are self declared and the badge does not cover them
 * (Terms section 21, Privacy section 6); team members are named in the privacy
 * policy with their right to be removed; and a published profile has no per
 * field visibility, as Privacy section 15 says.
 *
 * @internal
 */
final class LegalQualificationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private function text(string $uri): string
    {
        $result = $this->get($uri);
        $result->assertStatus(200);

        return preg_replace('/\s+/', ' ', html_entity_decode((string) $result->response()->getBody()));
    }

    public function testTheTermsSayQualificationsAreSelfDeclared(): void
    {
        $terms = $this->text('terms');

        $this->assertStringContainsString('<h2 id="qualifications">21. Qualifications you display</h2>', $terms);
        $this->assertStringContainsString('We do not check them, and the Verified Business badge does not cover them', $terms);
        $this->assertStringContainsString('<h2>22. Contact</h2>', $terms);
        // Section 20 keeps its number; /media links to it by name.
        $this->assertStringContainsString('<h2 id="brand">20.', $terms);
    }

    public function testThePrivacyPolicyCoversTeamMembersAndVisibility(): void
    {
        $privacy = $this->text('privacy');

        $this->assertStringContainsString('Team members (Verified Business profiles)', $privacy);
        $this->assertStringContainsString('We will remove them without needing the business to agree', $privacy);
        $this->assertStringContainsString('We do not ask for certificates and do not check qualifications', $privacy);
        $this->assertStringContainsString('there is no setting that hides one field while showing the rest', $privacy);
        $this->assertStringContainsString('href="' . base_url('terms#qualifications') . '"', $privacy);
    }
}
