<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use App\Services\ListingVideoService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ValidListingInput;

/**
 * The profile video is part of the paid badge: the owner form only draws the
 * field for a verified listing, but the form is not the control. These go
 * through the real save path, with the provider lookups faked.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ListingVideoTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private int $categoryId;

    /** @var list<string> FCPATH-relative files to remove in tearDown */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Law Firms', 'slug' => 'law-firms',
            'group_name' => 'Professional', 'is_active' => 1,
        ], true);

        // Same reasoning as ForgedTeamPhotoPathTest.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $rel) {
            @unlink(rtrim(FCPATH, '/') . '/' . $rel);
        }
        Services::resetSingle('listingVideo');
        parent::tearDown();
    }

    public function testVerifiedOwnerSavesAVideo(): void
    {
        $id = $this->listing(true);
        $this->save($id, 'https://youtu.be/dQw4w9WgXcQ');

        $row = (new DirectoryListingModel())->find($id);
        $this->assertSame('youtube', $row['video_provider']);
        $this->assertSame('dQw4w9WgXcQ', $row['video_id']);
        // The testing service's lookups "fail": saved anyway, without extras.
        $this->assertNull($row['video_thumb_path']);
    }

    public function testUnverifiedOwnersPostIsIgnored(): void
    {
        $id = $this->listing(false);
        $this->save($id, 'https://youtu.be/dQw4w9WgXcQ');

        $row = (new DirectoryListingModel())->find($id);
        $this->assertNotNull($row['display_name'], 'Guard: the listing exists.');
        $this->assertNull($row['video_id']);
    }

    public function testABadLinkIsAFieldErrorAndSavesNothing(): void
    {
        $id = $this->listing(true);
        $this->save($id, 'https://example.com/my-video');

        $this->assertSame('Paste a link to a YouTube or Vimeo video.', session('errors')['video_url'] ?? null);
        $this->assertNull((new DirectoryListingModel())->find($id)['video_id']);
    }

    public function testAPrivateVideoIsRefused(): void
    {
        Services::injectMock('listingVideo', new ListingVideoService(static fn (string $url): ?array => ['status' => 404, 'body' => 'Not Found']));
        $id = $this->listing(true);
        $this->save($id, 'https://vimeo.com/76979871');

        $this->assertStringContainsString('private', session('errors')['video_url'] ?? '');
        $this->assertNull((new DirectoryListingModel())->find($id)['video_id']);
    }

    public function testTitleAndThumbnailAreCopiedAndReplacedCleanly(): void
    {
        $fetched = [];
        Services::injectMock('listingVideo', new ListingVideoService(static function (string $url) use (&$fetched): ?array {
            $fetched[] = $url;
            if (str_contains($url, '/oembed')) {
                return ['status' => 200, 'body' => json_encode(['title' => 'Our practice', 'thumbnail_url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg'])];
            }
            if (str_ends_with($url, '/maxresdefault.jpg')) {
                return ['status' => 404, 'body' => ''];
            }
            $image = imagecreatetruecolor(320, 180);
            ob_start();
            imagejpeg($image);

            return ['status' => 200, 'body' => (string) ob_get_clean()];
        }));

        $id = $this->listing(true);
        $this->save($id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $row = (new DirectoryListingModel())->find($id);
        $this->files[] = (string) $row['video_thumb_path'];
        $this->assertSame('Our practice', $row['video_title']);
        $this->assertStringStartsWith(ListingVideoService::THUMB_DIR . '/', (string) $row['video_thumb_path']);
        $this->assertFileExists(FCPATH . $row['video_thumb_path']);
        // Falls back from maxresdefault to mqdefault, and only ever to YouTube's
        // image host, never the oEmbed's own thumbnail_url.
        $this->assertContains('https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg', $fetched);
        $this->assertNotContains('https://i.ytimg.com/vi/x/hqdefault.jpg', $fetched);

        // A different video replaces the thumbnail and deletes the old file.
        $first = (string) $row['video_thumb_path'];
        $this->save($id, 'https://youtu.be/aaaaaaaaaaa');
        $row = (new DirectoryListingModel())->find($id);
        $this->files[] = (string) $row['video_thumb_path'];
        $this->assertSame('aaaaaaaaaaa', $row['video_id']);
        $this->assertFileDoesNotExist(FCPATH . $first);

        // An empty box removes the video and its file.
        $second = (string) $row['video_thumb_path'];
        $this->save($id, '');
        $row = (new DirectoryListingModel())->find($id);
        $this->assertNull($row['video_id']);
        $this->assertNull($row['video_thumb_path']);
        $this->assertFileDoesNotExist(FCPATH . $second);
    }

    public function testALapsedBadgeHidesTheVideoAndKeepsIt(): void
    {
        $id   = $this->listing(true, ['video_provider' => 'vimeo', 'video_id' => '76979871', 'video_hash' => '8a1b2c3d4e']);
        $slug = (new DirectoryListingModel())->find($id)['slug'];

        $video = (new DirectoryService())->getProfile($slug)['video'];
        $this->assertStringStartsWith('https://player.vimeo.com/video/76979871?', $video['embed'] ?? '');
        $this->assertStringContainsString('h=8a1b2c3d4e', $video['embed']);

        (new DirectoryListingModel())->update($id, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertNull((new DirectoryService())->getProfile($slug)['video']);
        $this->assertSame('76979871', (new DirectoryListingModel())->find($id)['video_id']);
    }

    public function testPlaybackChoicesSaveWithoutALookupAndShapeThePlayers(): void
    {
        $fetched = 0;
        Services::injectMock('listingVideo', new ListingVideoService(static function (string $url) use (&$fetched): ?array {
            $fetched++;

            return null;
        }));
        $id   = $this->listing(true, ['video_provider' => 'youtube', 'video_id' => 'dQw4w9WgXcQ']);
        $slug = (new DirectoryListingModel())->find($id)['slug'];

        // Off by default: nothing plays in the tile, and the lightbox starts muted.
        $video = (new DirectoryService())->getProfile($slug)['video'];
        $this->assertSame('', $video['tile']);
        $this->assertStringContainsString('mute=1', $video['embed']);

        // Same link, both boxes ticked: saved, and no lookup for a known video.
        $this->save($id, 'https://youtu.be/dQw4w9WgXcQ', ['video_settings' => '1', 'video_autoplay' => '1', 'video_sound' => '1']);
        $row = (new DirectoryListingModel())->find($id);
        $this->assertSame(['1', '1'], [(string) $row['video_autoplay'], (string) $row['video_sound']]);
        $this->assertSame(0, $fetched);

        $video = (new DirectoryService())->getProfile($slug)['video'];
        $this->assertStringContainsString('mute=0', $video['embed']);
        // The tile always plays silently, whatever the owner chose for sound.
        $this->assertStringContainsString('mute=1', $video['tile']);
        $this->assertStringContainsString('loop=1', $video['tile']);

        // Boxes unticked post nothing; the marker makes that "off".
        $this->save($id, 'https://youtu.be/dQw4w9WgXcQ', ['video_settings' => '1']);
        $row = (new DirectoryListingModel())->find($id);
        $this->assertSame(['0', '0'], [(string) $row['video_autoplay'], (string) $row['video_sound']]);
    }

    public function testRemovingTheVideoResetsItsChoices(): void
    {
        $id = $this->listing(true, ['video_provider' => 'youtube', 'video_id' => 'dQw4w9WgXcQ', 'video_autoplay' => 1, 'video_sound' => 1]);
        $this->save($id, '', ['video_settings' => '1', 'video_autoplay' => '1']);

        $row = (new DirectoryListingModel())->find($id);
        $this->assertNull($row['video_id']);
        $this->assertSame(['0', '0'], [(string) $row['video_autoplay'], (string) $row['video_sound']]);
    }

    // -------------------------------------------------------------- fixtures

    /** @param array<string,string> $extra */
    private function save(int $id, string $videoUrl, array $extra = []): void
    {
        $this->withSession([Manage::SESSION_KEY => $id])->post('manage/edit', $this->withRequiredSections([
            'display_name'   => 'Hands & Co Attorneys',
            'contact_person' => 'Jane Hands',
            'category_id'    => $this->categoryId,
            'video_url'      => $videoUrl,
        ] + $extra));
    }

    /** @param array<string,mixed> $overrides */
    private function listing(bool $verified, array $overrides = []): int
    {
        return (int) (new DirectoryListingModel())->insert(array_merge([
            'display_name'   => 'Hands & Co Attorneys',
            'slug'           => 'hands-co-' . bin2hex(random_bytes(4)),
            'email'          => 'video-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'    => $this->categoryId,
            'status'         => 'published',
            'is_verified'    => 1,
            'published_at'   => date('Y-m-d H:i:s'),
            'logo_path'      => 'assets/listings/existing-logo.webp',
            'verified_until' => $verified ? date('Y-m-d', strtotime('+1 year')) : null,
        ], $overrides), true);
    }
}
