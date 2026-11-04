<?php

use App\Libraries\ProfessionalRegistration as Reg;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Registration number, ORCID and ResearchGate: who gets the fields, what a
 * valid value is, and that nothing reaches the page or the JSON-LD unless the
 * owner switched it on for a regulated category.
 *
 * @internal
 */
final class ProfessionalRegistrationTest extends CIUnitTestCase
{
    // ---- who is regulated -------------------------------------------------

    public function testRegulatorComesFromSlugThenGroup(): void
    {
        $this->assertSame('hpcsa', Reg::regulatorFor('orthopaedic-surgeon', 'Health & Medical')['key']);
        $this->assertSame('sanc', Reg::regulatorFor('nurse', 'Health & Medical')['key']);
        $this->assertSame('lpc', Reg::regulatorFor('attorney', 'Legal & Financial')['key']);
        $this->assertSame('sacap', Reg::regulatorFor('architect', 'Professional Services')['key']);
        $this->assertSame('ecsa', Reg::regulatorFor('engineer', 'Professional Services')['key']);
    }

    public function testFacilitiesAndUnregulatedCategoriesGetNothing(): void
    {
        $this->assertNull(Reg::regulatorFor('hospital', 'Health & Medical'), 'a slug mapped to null opts out of its group');
        $this->assertNull(Reg::regulatorFor('nutritionist', 'Health & Medical'));
        $this->assertNull(Reg::regulatorFor('bookkeeper', 'Legal & Financial'), 'the group itself is not regulated');
        $this->assertNull(Reg::regulatorFor('photographer', 'Professional Services'));
        $this->assertNull(Reg::regulatorFor('plumber', 'Home & Trades'));
        $this->assertNull(Reg::regulatorFor(null, null));
    }

    public function testEveryMappedRegulatorExists(): void
    {
        $config = config('Registrations');
        foreach (array_merge($config->byGroup, array_filter($config->bySlug)) as $key) {
            $this->assertArrayHasKey($key, $config->regulators);
        }
        foreach ($config->regulators as $key => $r) {
            $this->assertStringStartsWith('https://', $r['register'], $key);
        }
    }

    // ---- ORCID ------------------------------------------------------------

    public function testOrcidChecksumAndForms(): void
    {
        // ORCID's own documented example iDs.
        $this->assertSame('0000-0002-1825-0097', Reg::normaliseOrcid('0000-0002-1825-0097'));
        $this->assertSame('0000-0002-1694-233X', Reg::normaliseOrcid('0000-0002-1694-233x'));
        $this->assertSame('0000-0002-1825-0097', Reg::normaliseOrcid('https://orcid.org/0000-0002-1825-0097'));
        $this->assertSame('0000-0002-1825-0097', Reg::normaliseOrcid('0000000218250097'));
        $this->assertSame('', Reg::normaliseOrcid('  '));

        $this->assertNull(Reg::normaliseOrcid('0000-0002-1825-0098'), 'wrong check digit');
        $this->assertNull(Reg::normaliseOrcid('0000-0002-1825'));
        $this->assertNull(Reg::normaliseOrcid('https://evil.example/0000-0002-1825-0097'));
    }

    // ---- ResearchGate -----------------------------------------------------

    public function testResearchGateOnlyAcceptsProfileLinks(): void
    {
        $this->assertSame('https://www.researchgate.net/profile/Josiah-Carberry', Reg::normaliseResearchGate('researchgate.net/profile/Josiah-Carberry'));
        $this->assertSame('https://www.researchgate.net/profile/Josiah-Carberry', Reg::normaliseResearchGate('https://www.researchgate.net/profile/Josiah-Carberry/?utm=x#top'));
        $this->assertSame('', Reg::normaliseResearchGate(''));

        $this->assertNull(Reg::normaliseResearchGate('https://researchgate.net.evil.example/profile/X'));
        $this->assertNull(Reg::normaliseResearchGate('https://www.researchgate.net/publication/123_Paper'));
        $this->assertNull(Reg::normaliseResearchGate('javascript:alert(1)'));
    }

    // ---- problems and columns ---------------------------------------------

    public function testNothingIsJudgedOrWrittenWithoutTheMarker(): void
    {
        $input = ['reg_number' => '!!', 'orcid' => 'nope', 'registration_visible' => '1'];
        $this->assertSame([], Reg::problems($input, Reg::regulatorFor('attorney', null)));
        $this->assertSame([], Reg::columns($input));
    }

    public function testHpcsaNumberFormat(): void
    {
        $hpcsa = Reg::regulatorFor('general-practitioner', 'Health & Medical');
        $post  = static fn (string $n): array => [Reg::MARKER => '1', 'reg_number' => $n];

        $this->assertSame([], Reg::problems($post('mp 0123456'), $hpcsa));
        $this->assertSame([], Reg::problems($post('DP0012345'), $hpcsa));
        $this->assertArrayHasKey('reg_number', Reg::problems($post('0123456'), $hpcsa));
        $this->assertArrayHasKey('reg_number', Reg::problems($post('MP<script>'), $hpcsa));
        // A lenient register still refuses markup.
        $this->assertArrayHasKey('reg_number', Reg::problems($post('<b>1</b>'), Reg::regulatorFor('attorney', null)));
        $this->assertSame([], Reg::problems($post('12345'), Reg::regulatorFor('attorney', null)));
    }

    public function testColumnsNormaliseAndTheSwitchNeedsALiteralOne(): void
    {
        $cols = Reg::columns([
            Reg::MARKER            => '1',
            'reg_number'           => '  mp   0123456 ',
            'orcid'                => 'https://orcid.org/0000-0002-1825-0097',
            'researchgate_url'     => 'researchgate.net/profile/Josiah-Carberry',
            'registration_visible' => '1',
        ]);
        $this->assertSame('MP 0123456', $cols['reg_number']);
        $this->assertSame('0000-0002-1825-0097', $cols['orcid']);
        $this->assertSame('https://www.researchgate.net/profile/Josiah-Carberry', $cols['researchgate_url']);
        $this->assertSame(1, $cols['registration_visible']);

        // The hidden 0 the form posts ahead of the checkbox.
        $this->assertSame(0, Reg::columns([Reg::MARKER => '1', 'registration_visible' => '0'])['registration_visible']);
        $this->assertSame(0, Reg::columns([Reg::MARKER => '1', 'registration_visible' => 'yes'])['registration_visible']);
        $this->assertNull(Reg::columns([Reg::MARKER => '1', 'reg_number' => ''])['reg_number']);
    }

    public function testChangingTheNumberClearsTheAdminCheck(): void
    {
        $stored = ['reg_number' => 'MP0123456', 'reg_checked_at' => '2026-10-28 10:00:00'];

        $same = Reg::columns([Reg::MARKER => '1', 'reg_number' => 'mp0123456'], $stored);
        $this->assertArrayNotHasKey('reg_checked_at', $same);

        $changed = Reg::columns([Reg::MARKER => '1', 'reg_number' => 'MP0999999'], $stored);
        $this->assertArrayHasKey('reg_checked_at', $changed);
        $this->assertNull($changed['reg_checked_at']);
    }

    // ---- per person (since 4 Nov 2026) ----------------------------------------

    /** @param array<string,mixed> $over */
    private function person(array $over = []): array
    {
        return $over + [
            'name'             => 'Dr Test Surgeon',
            'reg_number'       => 'MP0123456',
            'orcid'            => '0000-0002-1825-0097',
            'researchgate_url' => 'https://www.researchgate.net/profile/Josiah-Carberry',
        ];
    }

    public function testAPersonShowsTheirRegistrationForARegulatedCategoryOnly(): void
    {
        $hpcsa = Reg::regulatorFor('orthopaedic-surgeon', 'Health & Medical');
        $shown = Reg::forPerson($this->person(), $hpcsa);
        $this->assertSame('HPCSA', $shown['short']);
        $this->assertSame('MP0123456', $shown['number']);
        $this->assertFalse($shown['checked']);
        $this->assertSame('https://orcid.org/0000-0002-1825-0097', $shown['orcid']);

        $this->assertNull(Reg::forPerson($this->person(), Reg::regulatorFor('plumber', 'Home & Trades')));
        $this->assertNull(Reg::forPerson($this->person(['reg_number' => null, 'orcid' => null, 'researchgate_url' => null]), $hpcsa));
        $this->assertTrue(Reg::forPerson($this->person(['reg_checked_at' => '2026-10-28 10:00:00']), $hpcsa)['checked']);
    }

    public function testAttorneysGetTheNumberButNoResearchLinks(): void
    {
        $shown = Reg::forPerson($this->person(['reg_number' => '12345']), Reg::regulatorFor('attorney', null));
        $this->assertSame('LPC', $shown['short']);
        $this->assertNull($shown['orcid']);
        $this->assertNull($shown['researchgate']);
    }

    public function testPersonColumnsNeedTheMarkerAndOnlyAnAdminSetsTheCheck(): void
    {
        $hpcsa  = Reg::regulatorFor('orthopaedic-surgeon', 'Health & Medical');
        $stored = ['reg_number' => 'MP0123456', 'reg_checked_at' => '2026-10-28 10:00:00'];

        $this->assertSame(['columns' => [], 'errors' => []], Reg::personColumns(['reg_number' => 'MP0999999'], $stored, $hpcsa));

        $same = Reg::personColumns([Reg::MARKER => '1', 'reg_number' => 'mp0123456'], $stored, $hpcsa);
        $this->assertSame('2026-10-28 10:00:00', $same['columns']['reg_checked_at'], 'unchanged number keeps the check');
        $this->assertArrayNotHasKey('registration_visible', $same['columns']);

        $changed = Reg::personColumns([Reg::MARKER => '1', 'reg_number' => 'MP0999999'], $stored, $hpcsa);
        $this->assertNull($changed['columns']['reg_checked_at'], 'a new number was never checked');

        $ticked = Reg::personColumns([Reg::MARKER => '1', 'reg_number' => 'MP0999999'], ['reg_number' => 'MP0999999'], $hpcsa, true);
        $this->assertNotNull($ticked['columns']['reg_checked_at']);
        $this->assertNull(Reg::personColumns([Reg::MARKER => '1', 'reg_number' => ''], $stored, $hpcsa, true)['columns']['reg_checked_at'], 'no number, nothing to check');

        $bad = Reg::personColumns([Reg::MARKER => '1', 'reg_number' => '0123456'], null, $hpcsa);
        $this->assertArrayHasKey('reg_number', $bad['errors']);
        $this->assertSame([], $bad['columns']);
    }

    public function testSchemaPutsTheRegistrationOnThePersonNotTheBusiness(): void
    {
        $url  = 'https://example.test/directory/test-practice';
        $node = schema_local_business([
            'display_name' => 'Test Practice',
            'type'         => 'practice',
            'category'     => ['slug' => 'orthopaedic-surgeon', 'group_name' => 'Health & Medical'],
            'team'         => [$this->person(['slug' => 'dr-test-surgeon'])],
        ], $url);

        $this->assertArrayNotHasKey('identifier', $node);
        $person = $node['employee'][0] ?? null;
        $this->assertIsArray($person, json_encode($node));
        $this->assertSame(['@type' => 'PropertyValue', 'propertyID' => 'HPCSA', 'value' => 'MP0123456'], $person['identifier']);
        $this->assertContains('https://orcid.org/0000-0002-1825-0097', $person['sameAs']);
    }
}
