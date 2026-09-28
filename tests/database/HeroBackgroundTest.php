<?php

use App\Models\DirectoryHeroImageModel;
use App\Models\DirectorySettingModel;
use App\Services\DirectorySettings;
use App\Services\HeroImageService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The home hero's optional background video: an uploaded file or a YouTube
 * video in place of the photo rotation, with the first photo kept as poster.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class HeroBackgroundTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    /** Files written under FCPATH by a test, removed again in tearDown(). */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new HeroImageService())->forget();
        (new DirectorySettings())->forget();
    }

    protected function tearDown(): void
    {
        (new HeroImageService())->forget();
        (new DirectorySettings())->forget();
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];
        parent::tearDown();
    }

    // ------------------------------------------------------- YouTube links

    public function testEveryCommonYouTubeLinkShapeYieldsTheId(): void
    {
        foreach ([
            'dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=42',
            'youtube.com/watch?v=dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
            '<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ?si=vf0w9iO0RlFjfjaS" '
                . 'title="YouTube video player" frameborder="0" allowfullscreen></iframe>',
        ] as $link) {
            $this->assertSame('dQw4w9WgXcQ', HeroImageService::youtubeId($link), $link);
        }
    }

    public function testAnythingElseIsRefused(): void
    {
        foreach ([
            '', 'hello', 'https://vimeo.com/12345678901',
            'https://evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=short',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ"><script>',
            '<iframe src="https://evil.example/embed/dQw4w9WgXcQ"></iframe>',
        ] as $link) {
            $this->assertNull(HeroImageService::youtubeId($link), $link);
        }
    }

    public function testAStartTimeInTheLinkIsKept(): void
    {
        $this->assertSame(15, HeroImageService::youtubeStart('https://youtu.be/dQw4w9WgXcQ?t=15'));
        $this->assertSame(80, HeroImageService::youtubeStart('https://youtu.be/dQw4w9WgXcQ?si=x&t=1m20s'));
        $this->assertSame(90, HeroImageService::youtubeStart('https://www.youtube.com/embed/dQw4w9WgXcQ?start=90'));
        $this->assertSame(0, HeroImageService::youtubeStart('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame(0, HeroImageService::youtubeStart('https://youtu.be/dQw4w9WgXcQ?t=soon'));

        (new HeroImageService())->saveBackground(['hero_media' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ?t=15'], null, 'admin@test');
        $this->assertSame(15, (new DirectorySettings())->heroBackground()['start']);
    }

    // --------------------------------------------------------------- saving

    public function testChoosingYouTubeStoresTheIdAndTheHomePageEmbedsIt(): void
    {
        $this->photo();

        $result = (new HeroImageService())->saveBackground(
            ['hero_media' => 'youtube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'],
            null,
            'admin@test'
        );
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(['type' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'start' => 0], (new DirectorySettings())->heroBackground());

        $html = $this->get('/')->getBody();
        $this->assertStringContainsString('data-hero-youtube="dQw4w9WgXcQ"', $html);
        // Video only: no photo before it, no rotation or captions after it.
        $this->assertStringNotContainsString('hero-slide', $html);
        $this->assertStringNotContainsString('data-hero-captions', $html);
        $this->assertStringContainsString('class="hero-home hero-home-video"', $html);
        $this->assertDoesNotMatchRegularExpression('/<section class="hero-home" data-hero>/', $html);
    }

    public function testYouTubeWithoutALinkIsRefusedAndNothingChanges(): void
    {
        $result = (new HeroImageService())->saveBackground(['hero_media' => 'youtube'], null, 'admin@test');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('youtube_url', $result['errors']);
        $this->assertNull((new DirectorySettings())->heroBackground());
    }

    public function testVideoWithoutAFileIsRefused(): void
    {
        $result = (new HeroImageService())->saveBackground(['hero_media' => 'video'], null, 'admin@test');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('video', $result['errors']);
    }

    public function testAStoredVideoRendersWithNoControls(): void
    {
        $this->photo();
        $path = $this->videoFile();
        $this->setting(DirectorySettingModel::HERO_MEDIA, 'video');
        $this->setting(DirectorySettingModel::HERO_VIDEO_PATH, $path);

        $html = $this->get('/')->getBody();

        $this->assertMatchesRegularExpression('/<video class="hero-video" data-hero-video muted loop playsinline/', $html);
        $this->assertStringContainsString($path, $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*\scontrols/', $html);
    }

    public function testAVideoFileThatHasGoneFallsBackToThePhotos(): void
    {
        $this->photo();
        $this->setting(DirectorySettingModel::HERO_MEDIA, 'video');
        $this->setting(DirectorySettingModel::HERO_VIDEO_PATH, 'assets/hero/uploads/deleted-by-hand.mp4');

        $this->assertNull((new DirectorySettings())->heroBackground());
        $this->assertStringNotContainsString('data-hero-video', $this->get('/')->getBody());
    }

    public function testSwitchingBackToPhotosKeepsTheYouTubeLinkForLater(): void
    {
        $svc = new HeroImageService();
        $svc->saveBackground(['hero_media' => 'youtube', 'youtube_url' => 'dQw4w9WgXcQ'], null, 'admin@test');
        $svc->saveBackground(['hero_media' => 'photos'], null, 'admin@test');

        $settings = new DirectorySettings();
        $this->assertNull($settings->heroBackground());
        $this->assertSame('dQw4w9WgXcQ', $settings->heroSettings()['youtube']);
    }

    public function testTheCookiePageNamesYouTubeOnlyWhileItIsTheBackground(): void
    {
        $this->assertStringNotContainsString('<strong>YouTube</strong>', $this->get('cookie-policy')->getBody());

        (new HeroImageService())->saveBackground(['hero_media' => 'youtube', 'youtube_url' => 'dQw4w9WgXcQ'], null, 'admin@test');

        $this->assertStringContainsString('<strong>YouTube</strong>', $this->get('cookie-policy')->getBody());
    }

    // -------------------------------------------------------------- headers

    public function testThePolicyAdmitsOnlyThePrivacyEnhancedPlayer(): void
    {
        // The CSP header itself is finalised outside the feature-test response,
        // so the policy is checked at its source.
        $this->assertSame(['https://www.youtube-nocookie.com'], (array) config('ContentSecurityPolicy')->frameSrc);

        $perms = $this->get('/')->response()->getHeaderLine('Permissions-Policy');
        $this->assertStringContainsString('autoplay=(self "https://www.youtube-nocookie.com")', $perms);
        $this->assertStringContainsString('camera=()', $perms);
    }

    // -------------------------------------------------------------- helpers

    private function photo(): void
    {
        (new DirectoryHeroImageModel())->insert([
            'path' => 'assets/hero/doctors-1600.webp', 'path_sm' => 'assets/hero/doctors-800.webp',
            'width' => 1600, 'height' => 1067, 'caption' => 'Doctors', 'is_active' => 1, 'sort_order' => 0,
        ]);
        (new HeroImageService())->forget();
    }

    private function setting(string $name, string $value): void
    {
        (new DirectorySettingModel())->put($name, $value, 'test');
        (new DirectorySettings())->forget();
    }

    private function videoFile(): string
    {
        $dir = FCPATH . 'assets/hero/uploads';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $rel  = 'assets/hero/uploads/test-hero-video.mp4';
        file_put_contents(FCPATH . $rel, 'not-really-an-mp4');
        $this->written[] = FCPATH . $rel;

        return $rel;
    }
}
