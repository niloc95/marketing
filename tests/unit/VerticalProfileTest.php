<?php

use App\Database\Seeds\DirectoryCategoriesSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Verticals;

/**
 * The per-category wording in Config\Verticals, and the colour it is paired with.
 *
 * Every way this can go wrong is invisible in the browser, which is why it is
 * checked here: a group missing from $byGroup quietly renders the generic
 * headings it always had, a $byCategory key with a typo is a silent no-op, a
 * misspelt panel in 'order' throws only on the one profile page that reaches it,
 * and a tint with no matching CSS rule renders navy rather than failing the
 * build. Same reasoning, and the same seeder-parsing trick, as CategoryPhotoTest.
 *
 * @internal
 */
final class VerticalProfileTest extends CIUnitTestCase
{
    private Verticals $verticals;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['directory_ui', 'slug']);
        $this->verticals = config('Verticals');
    }

    /** The seeder's source, with its comments stripped so quoted text in them cannot match. */
    private function seederSource(): string
    {
        $source = (string) file_get_contents((new ReflectionClass(DirectoryCategoriesSeeder::class))->getFileName());

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }

    /** @return list<string> Every group_name the seeder creates. */
    private function seededGroups(): array
    {
        preg_match_all("/^\\s*'([^']+)'\\s*=>\\s*\\[\\s*$/m", $this->seederSource(), $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> Every category slug the seeder creates. */
    private function seededSlugs(): array
    {
        preg_match_all(
            "/^\\s*'[^']+'\\s*=>\\s*\\[(.*?)^\\s*\\],\\s*$/ms",
            $this->seederSource(),
            $blocks,
        );

        $slugs = [];
        foreach ($blocks[1] as $body) {
            preg_match_all("/'([^']+)'/", $body, $names);
            foreach ($names[1] as $name) {
                $slugs[] = slugify($name);
            }
        }

        return array_values(array_unique($slugs));
    }

    public function testEverySeededGroupHasItsOwnWording(): void
    {
        $groups = $this->seededGroups();
        $this->assertNotEmpty($groups, 'could not read the groups out of DirectoryCategoriesSeeder');

        foreach ($groups as $group) {
            $this->assertArrayHasKey(
                $group,
                $this->verticals->byGroup,
                "group '{$group}' has no entry in Config\\Verticals — it will render the generic wording",
            );
        }
    }

    public function testEveryCategoryOverrideNamesARealCategory(): void
    {
        $slugs = $this->seededSlugs();
        $this->assertNotEmpty($slugs, 'could not read the category names out of DirectoryCategoriesSeeder');

        foreach (array_keys($this->verticals->byCategory) as $slug) {
            $this->assertContains(
                $slug,
                $slugs,
                "\$byCategory['{$slug}'] matches no seeded category, so it can never apply",
            );
        }
    }

    public function testALookupAlwaysReturnsTheCompleteKeySet(): void
    {
        $expected = array_keys($this->verticals->defaults);
        $headings = array_keys($this->verticals->defaults['headings']);

        $cases = [
            'a mapped group'              => ['Health & Medical', 'general-practitioner'],
            'a mapped group + override'   => ['Restaurants & Food', 'restaurant'],
            'an unmapped group'           => ['Widgets', 'widget-polisher'],
            'no category at all'          => [null, null],
        ];

        foreach ($cases as $label => [$group, $slug]) {
            $v = $this->verticals->forCategory($group, $slug);

            $this->assertSame($expected, array_keys($v), "{$label}: key set differs");
            $this->assertSame($headings, array_keys($v['headings']), "{$label}: heading set differs");
            foreach (['noun', 'nounPlural', 'cta'] as $key) {
                $this->assertNotSame('', trim((string) $v[$key]), "{$label}: {$key} is empty");
            }
        }
    }

    public function testAnUnmappedCategoryGetsTheGenericWording(): void
    {
        $this->assertSame($this->verticals->defaults, $this->verticals->forCategory(null, null));
        $this->assertSame($this->verticals->defaults, $this->verticals->forCategory('Widgets', 'widget-polisher'));
    }

    /**
     * The merge bug worth a test of its own: a category override naming one
     * heading must not take its group's other headings down with it.
     */
    public function testACategoryOverrideKeepsTheHeadingsItDoesNotName(): void
    {
        $v = $this->verticals->forCategory('Restaurants & Food', 'restaurant');

        $this->assertSame('Menu', $v['headings']['services'], "the category's own heading wins");
        $this->assertSame('Cuisine', $v['headings']['tags']);
        $this->assertSame('Opening hours', $v['headings']['hours'], "the group's heading survives");
        $this->assertSame('Reserve a table', $v['cta']);
        // Named by neither layer, so it falls all the way through to the default.
        $this->assertSame('Features & amenities', $v['headings']['features']);
    }

    public function testAGroupOverrideStillReachesTheDefaults(): void
    {
        $v = $this->verticals->forCategory('Health & Medical');

        $this->assertSame('Qualifications & registrations', $v['headings']['credentials']);
        $this->assertSame('Consulting hours', $v['headings']['hours']);
        $this->assertSame('Business description', $v['headings']['description'], 'unnamed headings fall through');
        $this->assertSame('credentials', $v['order'][0], 'a practice leads with its qualifications');
    }

    /**
     * Every 'order' must be a permutation of the panel keys — no duplicate (which
     * renders a panel twice), none missing (which hides one), and nothing named
     * that has no partial (which throws on the first profile page to reach it).
     */
    public function testEveryOrderIsACompleteSetOfRealPanels(): void
    {
        $panels = $this->verticals->defaults['order'];
        sort($panels);

        foreach ($panels as $panel) {
            $this->assertFileExists(
                APPPATH . 'Views/directory/_panel_' . $panel . '.php',
                "panel '{$panel}' has no partial",
            );
        }

        foreach ($this->verticals->byGroup as $group => $spec) {
            $order = $spec['order'] ?? $panels;
            $this->assertSame(
                count($order),
                count(array_unique($order)),
                "group '{$group}' names a panel twice",
            );
            $sorted = $order;
            sort($sorted);
            $this->assertSame($panels, $sorted, "group '{$group}' does not name every panel exactly once");
        }

        // An override may reorder too; if it does, the same rule applies.
        foreach ($this->verticals->byCategory as $slug => $spec) {
            if (! isset($spec['order'])) {
                continue;
            }
            $sorted = $spec['order'];
            sort($sorted);
            $this->assertSame($panels, $sorted, "category '{$slug}' does not name every panel exactly once");
        }
    }

    /**
     * The gap the CSS comment above the tints warns about: a tint class with no
     * matching rule is not a build error, it just renders navy. That includes the
     * hero gradient vars this feature added, which is the half most likely to be
     * forgotten when a sixteenth group arrives.
     */
    public function testEveryGroupTintHasItsCssRule(): void
    {
        $css = (string) file_get_contents(ROOTPATH . 'resources/directory.css');

        $tints = ['cat-tint-slate']; // the documented fallback, given to no group
        foreach ($this->seededGroups() as $group) {
            $tints[] = category_group_tint($group);
        }

        foreach (array_unique($tints) as $tint) {
            $this->assertMatchesRegularExpression(
                '/\.' . preg_quote($tint, '/') . '\s*\{[^}]*--tint-fg:/',
                $css,
                "{$tint} has no rule in directory.css, so it renders navy",
            );
            $this->assertMatchesRegularExpression(
                '/\.' . preg_quote($tint, '/') . '\s*\{[^}]*--tint-hero-from:/',
                $css,
                "{$tint} sets no hero gradient, so its category heroes render navy",
            );
        }
    }

    public function testTheHelperAgreesWithTheConfig(): void
    {
        $this->assertSame(
            $this->verticals->forCategory('Legal & Financial', 'attorney'),
            vertical_profile('Legal & Financial', 'attorney'),
        );
    }

    /**
     * show.php renders all eight panels unconditionally and lets each one decide,
     * so a panel that forgets to return early would draw an empty bordered card on
     * every profile that lacks its field.
     */
    public function testAPanelWithNothingToShowDrawsNothing(): void
    {
        $v = $this->verticals->forCategory('Health & Medical', 'general-practitioner');

        foreach ($this->verticals->defaults['order'] as $panel) {
            $html = view('directory/_panel_' . $panel, ['l' => [], 'v' => $v], ['saveData' => false]);
            // CI4 wraps every rendered view in DEBUG-VIEW comments when
            // debug is on, which it is under the test environment — so the
            // comparison is against the markup, not the whole string.
            $this->assertSame(
                '',
                trim((string) preg_replace('/<!--.*?-->/s', '', $html)),
                "_panel_{$panel} drew an empty panel",
            );
        }
    }

    /**
     * The regression that matters most: category_id is nullable and ON DELETE SET
     * NULL, so a listing can genuinely have no category. It must render exactly as
     * it did before any of this existed — generic headings, no half-applied theme.
     */
    public function testAListingWithNoCategoryFallsBackCompletely(): void
    {
        $this->assertSame('cat-tint-slate', category_group_tint(null), 'the documented grey fallback');
        $this->assertNull(category_photo(['group_name' => null]), 'and no hero photograph');

        $v = vertical_profile(null, null);
        $html = view('directory/_panel_credentials', [
            'l' => ['credentials' => 'BSc (Hons)'],
            'v' => $v,
        ], ['saveData' => false]);

        $this->assertStringContainsString('<h3>Credentials</h3>', $html, 'the wording it always had');
        $this->assertStringContainsString('BSc (Hons)', $html);
    }

    /**
     * The bug LocationFormRenderTest caught when the branch and team headings
     * were first made configurable, kept from coming back.
     *
     * CI4's renderer keeps view data between render() calls, so a partial with an
     * optional $heading inherits whichever 'heading' was passed last rather than
     * falling back to its own default — and the contact, map and hours panels all
     * pass one. Hence $sectionHeading and $teamHeading. Renaming either back to
     * 'heading' fails here.
     */
    public function testABranchHeadingDoesNotInheritAnotherPanelsHeading(): void
    {
        helper('directory_hours');

        // Leave a 'heading' behind, exactly as the hours panel does on a profile.
        view('directory/_hours_panel', [
            'hours'   => hours_decode('{"mon":{"open":"08:00","close":"17:00"}}'),
            'heading' => 'Trading hours',
            'class'   => '',
        ]);

        $html = view('directory/_location_panel', ['locations' => [['city' => 'Durban']]]);

        $this->assertStringContainsString('Other locations', $html, 'the partial fell back to its own default');
        $this->assertStringNotContainsString('Trading hours', $html, "it inherited the hours panel's heading");

        view('directory/_contact_panel', ['row' => ['phone' => '011 555 0100'], 'heading' => 'Contact', 'showWeb' => false]);

        $team = view('directory/_team_panel', ['members' => [['name' => 'Dr A. Nkosi']]]);
        $this->assertStringContainsString('Our team', $team);
        $this->assertStringNotContainsString('<h3>Contact</h3>', $team);
    }

    public function testAPanelIsNamedByItsVertical(): void
    {
        $cases = [
            ['Health & Medical', 'general-practitioner', 'Qualifications &amp; registrations'],
            ['Legal & Financial', 'attorney', 'Admissions &amp; accreditation'],
            ['Home & Trades', 'plumber', 'Certifications &amp; registrations'],
        ];

        foreach ($cases as [$group, $slug, $expected]) {
            $html = view('directory/_panel_credentials', [
                'l' => ['credentials' => 'x'],
                'v' => vertical_profile($group, $slug),
            ], ['saveData' => false]);

            $this->assertStringContainsString('<h3>' . $expected . '</h3>', $html, $slug);
        }

        // And the one that made this whole thing worth doing: a restaurant's
        // service list is a menu.
        $menu = view('directory/_panel_services', [
            'l' => ['services' => [['name' => 'Bunny chow', 'price_label' => 'R85']]],
            'v' => vertical_profile('Restaurants & Food', 'restaurant'),
        ], ['saveData' => false]);
        $this->assertStringContainsString('<h3>Menu</h3>', $menu);
    }
}
