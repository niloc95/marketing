<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SeoHelperTest extends CIUnitTestCase
{
    public function testRobotsTrueMapsToIndexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="index, follow">',
            seo_meta(['robots' => true])
        );
    }

    public function testRobotsOmittedDefaultsToIndexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="index, follow">',
            seo_meta([])
        );
    }

    public function testRobotsFalseMapsToNoindexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, follow">',
            seo_meta(['robots' => false])
        );
    }

    public function testRobotsInvalidStringFallsBackToNoindexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, follow">',
            seo_meta(['robots' => 'not-a-real-directive'])
        );
    }

    public function testRobotsAllowedLiteralsPassThroughUnchanged(): void
    {
        foreach (['index, follow', 'noindex, follow', 'noindex, nofollow'] as $directive) {
            $this->assertStringContainsString(
                '<meta name="robots" content="' . $directive . '">',
                seo_meta(['robots' => $directive])
            );
        }
    }

    public function testMissingImageFallsBackToDefaultOgImage(): void
    {
        $expected = esc(base_url(config('Directory')->ogImage()), 'attr');
        $this->assertStringContainsString(
            '<meta property="og:image" content="' . $expected . '">',
            seo_meta([])
        );
    }

    public function testEmptyStringImageFallsBackToDefaultOgImage(): void
    {
        $expected = esc(base_url(config('Directory')->ogImage()), 'attr');
        $this->assertStringContainsString(
            '<meta property="og:image" content="' . $expected . '">',
            seo_meta(['image' => ''])
        );
    }

    public function testExplicitImageIsUsedAsIs(): void
    {
        $expected = esc('https://example.test/logo.jpg', 'attr');
        $this->assertStringContainsString(
            '<meta property="og:image" content="' . $expected . '">',
            seo_meta(['image' => 'https://example.test/logo.jpg'])
        );
    }
}
