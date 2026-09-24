<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SeoHelperTest extends CIUnitTestCase
{
    // ---- seo_excerpt -----------------------------------------------------

    public function testExcerptLeavesShortTextAlone(): void
    {
        $this->assertSame('Short and sweet.', seo_excerpt('Short and sweet.', 50));
    }

    public function testExcerptCutsAtAWordBreakWithAnEllipsis(): void
    {
        $out = seo_excerpt('The quick brown fox jumps over the lazy dog', 20);

        $this->assertSame('The quick brown fox…', $out);
        $this->assertLessThanOrEqual(20, mb_strlen($out));
    }

    public function testExcerptCollapsesWhitespaceAndNewlines(): void
    {
        $this->assertSame('One two three', seo_excerpt("  One\n\ntwo \t three ", 50));
    }

    public function testExcerptDropsTrailingPunctuationBeforeTheEllipsis(): void
    {
        $this->assertSame('Flights, hotels…', seo_excerpt('Flights, hotels, car hire and visas', 17));
        $this->assertSame('Open daily…', seo_excerpt('Open daily – including holidays', 13));
    }

    public function testExcerptNeverSplitsAMultibyteCharacter(): void
    {
        $out = seo_excerpt('We’re proudly local – ĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀ and more', 30);

        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertStringEndsWith('…', $out);
        $this->assertLessThanOrEqual(30, mb_strlen($out));
    }

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
