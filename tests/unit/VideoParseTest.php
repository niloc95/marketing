<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The profile video's link reading. Whatever comes back goes into a player
 * URL, so the interesting cases are the ones that must come back null.
 *
 * @internal
 */
final class VideoParseTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('video');
    }

    public function testYouTubeShapes(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'youtube.com/watch?v=dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            '<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ?si=x" frameborder="0"></iframe>',
        ] as $link) {
            $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'hash' => ''], video_parse($link), $link);
        }
    }

    public function testVimeoShapes(): void
    {
        foreach ([
            'https://vimeo.com/76979871',
            'vimeo.com/76979871',
            'https://vimeo.com/channels/staffpicks/76979871',
            'https://vimeo.com/groups/shortfilms/videos/76979871',
            'https://player.vimeo.com/video/76979871',
            '<iframe src="https://player.vimeo.com/video/76979871?badge=0" allow="autoplay"></iframe>',
        ] as $link) {
            $this->assertSame(['provider' => 'vimeo', 'id' => '76979871', 'hash' => ''], video_parse($link), $link);
        }
    }

    public function testUnlistedVimeoKeepsItsKey(): void
    {
        foreach ([
            'https://vimeo.com/76979871/8a1b2c3d4e',
            'https://player.vimeo.com/video/76979871?h=8a1b2c3d4e',
            '<iframe src="https://player.vimeo.com/video/76979871?h=8a1b2c3d4e&amp;badge=0"></iframe>',
        ] as $link) {
            $this->assertSame(['provider' => 'vimeo', 'id' => '76979871', 'hash' => '8a1b2c3d4e'], video_parse($link), $link);
        }
    }

    public function testAnythingElseIsRefused(): void
    {
        foreach ([
            '',
            'not a link',
            'javascript:alert(1)',
            'https://example.com/watch?v=dQw4w9WgXcQ',
            'https://evil.test/vimeo.com/76979871',
            'https://vimeo.com/about',
            'https://player.vimeo.com/video/abc',
            'https://www.youtube.com/watch?v=short',
            'https://vimeo.com/76979871?h=<script>',
            '<iframe src="https://evil.test/embed/dQw4w9WgXcQ"></iframe>',
        ] as $link) {
            $this->assertNull(video_parse($link), $link);
        }
    }

    public function testPlayerUrlsUseThePrivacyModesAndHideTheControls(): void
    {
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&mute=0&controls=0&disablekb=1&fs=0&iv_load_policy=3&rel=0&playsinline=1&enablejsapi=1',
            video_embed_url('youtube', 'dQw4w9WgXcQ'),
        );
        $this->assertSame(
            'https://player.vimeo.com/video/76979871?autoplay=1&muted=0&loop=0&controls=0&title=0&byline=0&portrait=0&dnt=1&h=8a1b2c3d4e',
            video_embed_url('vimeo', '76979871', '8a1b2c3d4e'),
        );
        $this->assertSame('https://vimeo.com/76979871/8a1b2c3d4e', video_watch_url('vimeo', '76979871', '8a1b2c3d4e'));
    }

    public function testTheTilePlayerIsSilentAndLoops(): void
    {
        $youtube = video_embed_url('youtube', 'dQw4w9WgXcQ', '', ['muted' => true, 'loop' => true, 'origin' => 'https://local.example']);
        $this->assertStringContainsString('mute=1', $youtube);
        // YouTube loops one video only as a playlist of itself.
        $this->assertStringContainsString('loop=1&playlist=dQw4w9WgXcQ', $youtube);
        $this->assertStringContainsString('origin=https%3A%2F%2Flocal.example', $youtube);

        $vimeo = video_embed_url('vimeo', '76979871', '', ['muted' => true, 'loop' => true]);
        $this->assertStringContainsString('muted=1&loop=1', $vimeo);
    }
}
