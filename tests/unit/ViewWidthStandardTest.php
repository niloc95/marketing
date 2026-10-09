<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every page uses one of two widths.
 *
 * A page and its cards span the .container (max-w-7xl). A single action form
 * (sign in, unsubscribe, contact, checkout) uses .form-card-narrow. Before this
 * rule, cards were capped at five different widths (3xl, 2xl, lg, md, none),
 * one view per decision, so some pages were full width and some were not.
 *
 * This reads the view files and fails on a .form-card or .container that sets
 * its own max-w-*. A narrower line length for text belongs on the paragraph,
 * not on the card or the page.
 *
 * No database.
 *
 * @internal
 */
final class ViewWidthStandardTest extends CIUnitTestCase
{
    public function testNoCardOrContainerSetsItsOwnWidth(): void
    {
        $offenders = [];
        $files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH . 'Views', FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file->getPathname()), $m);

            foreach ($m[1] as $class) {
                $names = preg_split('/\s+/', trim($class));

                if (array_intersect(['form-card', 'container'], $names) === []) {
                    continue;
                }

                foreach ($names as $name) {
                    if (str_starts_with($name, 'max-w-')) {
                        $offenders[] = substr($file->getPathname(), strlen(APPPATH)) . ': ' . $class;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Use .form-card (full width) or .form-card-narrow, not a max-w-* of its own.');
    }
}
