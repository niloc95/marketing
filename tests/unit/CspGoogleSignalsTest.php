<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\ContentSecurityPolicy;

/**
 * GA4 with Google signals sends hits to www.google.com/g/collect, the
 * visitor's Google country domain and stats.g.doubleclick.net. Production
 * blocked all of them until Nov 2026; every block is a lost hit.
 *
 * @internal
 */
final class CspGoogleSignalsTest extends CIUnitTestCase
{
    public function testGoogleSignalsHostsMayBeReachedAsFetchAndImage(): void
    {
        $csp = new ContentSecurityPolicy();

        foreach (['https://*.google.com', 'https://*.g.doubleclick.net', 'https://*.googletagmanager.com', 'https://www.google.co.za'] as $host) {
            $this->assertContains($host, (array) $csp->connectSrc, $host . ' in connect-src');
            $this->assertContains($host, (array) $csp->imageSrc, $host . ' in img-src');
        }
        // The analytics hosts that were already there stay.
        $this->assertContains('https://*.google-analytics.com', (array) $csp->connectSrc);
        $this->assertContains('https://www.google-analytics.com', (array) $csp->imageSrc);
    }
}
