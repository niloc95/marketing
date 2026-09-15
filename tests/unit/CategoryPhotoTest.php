<?php

use App\Database\Seeds\DirectoryCategoriesSeeder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The photographs behind the homepage category tiles.
 *
 * category_photo() is a map of names to committed files, and both ways it can
 * go wrong are silent in the browser: a name with no file draws a broken image
 * under the tile's text, and a new group with no entry quietly falls back to the
 * plain icon tile. Both are caught here instead.
 *
 * @internal
 */
final class CategoryPhotoTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_ui');
    }

    /** @return list<string> Every group_name the seeder creates, read from its source. */
    private function seededGroups(): array
    {
        $source = (string) file_get_contents((new ReflectionClass(DirectoryCategoriesSeeder::class))->getFileName());
        preg_match_all("/^\\s*'([^']+)'\\s*=>\\s*\\[\\s*$/m", $source, $m);

        return array_values(array_unique($m[1]));
    }

    public function testEverySeededGroupHasAPhoto(): void
    {
        $groups = $this->seededGroups();
        $this->assertNotEmpty($groups, 'could not read the groups out of DirectoryCategoriesSeeder');

        foreach ($groups as $group) {
            $this->assertNotNull(
                category_photo(['slug' => 'no-such-category', 'group_name' => $group]),
                "group '{$group}' has no tile photo",
            );
        }
    }

    public function testACategoryOfItsOwnWinsOverItsGroup(): void
    {
        $dentist = category_photo(['slug' => 'dentist', 'group_name' => 'Health & Medical']);
        $gp      = category_photo(['slug' => 'general-practitioner', 'group_name' => 'Health & Medical']);

        $this->assertSame('assets/categories/dentist-800.webp', $dentist['src']);
        $this->assertSame('assets/categories/group-health-medical-800.webp', $gp['src']);
    }

    public function testOneGridDoesNotRepeatAGroupPhotoWhileTheGroupHasAnother(): void
    {
        $photos = category_photos([
            ['slug' => 'nutritionist', 'group_name' => 'Health & Medical'],
            ['slug' => 'spa', 'group_name' => 'Beauty & Wellness'],
            ['slug' => 'general-practitioner', 'group_name' => 'Health & Medical'],
            ['slug' => 'widget-polisher', 'group_name' => 'Widgets'],
            ['slug' => 'dentist', 'group_name' => 'Health & Medical'],
        ]);

        $this->assertSame('assets/categories/group-health-medical-800.webp', $photos[0]['src']);
        $this->assertSame('assets/categories/group-health-medical-2-800.webp', $photos[2]['src']);
        $this->assertNull($photos[3]);
        $this->assertSame('assets/categories/dentist-800.webp', $photos[4]['src'], 'an override is unaffected');

        // Once a group runs out, it repeats rather than dropping the photo.
        $third = category_photos(array_fill(0, 3, ['slug' => 'x', 'group_name' => 'Health & Medical']))[2];
        $this->assertSame('assets/categories/group-health-medical-800.webp', $third['src']);
    }

    public function testAnUnknownGroupHasNoPhoto(): void
    {
        $this->assertNull(category_photo(['slug' => 'widget-polisher', 'group_name' => 'Widgets']));
        $this->assertNull(category_photo([]));
    }

    public function testEveryMappedFileIsOnDisk(): void
    {
        $dir   = FCPATH . 'assets/categories/';
        $names = array_map(
            static fn (string $f): string => basename($f, '-800.webp'),
            glob($dir . '*-800.webp') ?: [],
        );

        // Walk the map by probing it: each file on disk is either a slug override
        // or one of a group's photos, and each lookup must point at files that
        // exist. A group's photos are reached by marking each one taken in turn.
        $resolved = [];
        foreach ($this->seededGroups() as $group) {
            $taken = [];
            while (($photo = category_photo(['group_name' => $group], $taken)) !== null
                && ! in_array($photo['src'], $taken, true)) {
                $resolved[] = $photo;
                $taken[]    = $photo['src'];
            }
        }
        foreach ($names as $name) {
            if (! str_starts_with($name, 'group-')) {
                $resolved[] = category_photo(['slug' => $name]);
            }
        }

        foreach ($resolved as $photo) {
            $this->assertNotNull($photo);
            $this->assertFileExists(FCPATH . $photo['src']);
            $this->assertFileExists(FCPATH . $photo['src_sm']);
            $this->assertMatchesRegularExpression('#^https://www\.pexels\.com/photo/\d+/$#', $photo['credit_url']);
        }

        // And nothing on disk is orphaned from the map.
        $used = array_map(static fn (array $p): string => basename($p['src'], '-800.webp'), $resolved);
        $this->assertSame([], array_values(array_diff($names, $used)), 'photos on disk that no category uses');
    }

    public function testATileWithAPhotoDrawsItBehindTheText(): void
    {
        $html = view('directory/_category_tile', ['c' => [
            'name' => 'Dentist', 'slug' => 'dentist', 'group_name' => 'Health & Medical', 'listing_count' => 3,
        ]]);

        $this->assertStringContainsString('cat-tile-photo', $html);
        $this->assertStringContainsString('dentist-400.webp 400w', $html);
        $this->assertStringContainsString('alt=""', $html, 'the photo is decorative; the name is the link text');
        $this->assertStringContainsString('3 profiles', $html);
    }

    public function testATileWithoutAPhotoKeepsTheIconTile(): void
    {
        $html = view('directory/_category_tile', ['c' => [
            'name' => 'Widget Polisher', 'slug' => 'widget-polisher', 'group_name' => 'Widgets', 'listing_count' => 1,
        ]]);

        $this->assertStringNotContainsString('cat-tile-photo', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('cat-tile-icon cat-tint-slate', $html);
        $this->assertStringContainsString('1 profile<', $html);
    }
}
