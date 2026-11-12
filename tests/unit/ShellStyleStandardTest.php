<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * One look for every page in the app shell, /admin and /business (owner,
 * 12 Nov 2026). Before this, each page was built its own way: the public
 * .section > .container around admin pages, a 48rem cap around some owner
 * pages, rounded-xl here and rounded-surface there, an h1 typed out in
 * Tailwind thirty times, and orange Save on some pages and ocean on others.
 *
 * The standard (resources/directory.css, "shell page standard"; the system
 * handbook's Design chapter):
 * - the page fills the width beside the sidebar; the layouts set the padding;
 * - the top bar is the page's header: its h1 is the page title, set by the
 *   view's shell_title section (or the sidebar item's name), and the page's
 *   buttons go in shell_actions. Views draw no h1 of their own, except the
 *   bare sign in pages, which have no top bar title;
 * - boxes are rounded-surface (.panel), small inner boxes rounded-inner,
 *   buttons rounded-control (.btn);
 * - the main button is .btn-primary; .btn-accent is for upgrades only.
 *
 * Reads the view files; no database.
 *
 * @internal
 */
final class ShellStyleStandardTest extends CIUnitTestCase
{
    /** Views that may use .btn-accent: an upgrade, never a Save. */
    private const ACCENT_ALLOWED = [];

    /** Signed out pages in the bare shell: the card carries the h1. */
    private const BARE = [
        'admin/login.php', 'business/sign_in.php', 'business/sign_in_link.php',
        'business/password_request.php', 'business/password_reset.php', 'business/invite.php',
    ];

    public function testShellViewsFollowTheStandard(): void
    {
        $offenders = [];

        foreach ($this->views() as $path => $html) {
            preg_match_all('/<(\w+)\b[^>]*\bclass="([^"]*)"/', $html, $m, PREG_SET_ORDER);

            foreach ($m as [, $tag, $class]) {
                $names = preg_split('/\s+/', trim($class));

                if (array_intersect(['section', 'container'], $names) !== []) {
                    $offenders[] = "{$path}: public page wrapper .section/.container ({$class})";
                }
                if ($tag !== 'p' && array_intersect(['max-w-3xl', 'max-w-4xl', 'max-w-5xl', 'max-w-6xl', 'max-w-7xl'], $names) !== []) {
                    $offenders[] = "{$path}: page width cap ({$class})";
                }
                if (array_intersect(['rounded-lg', 'rounded-xl', 'rounded-2xl', 'rounded-3xl'], $names) !== []) {
                    $offenders[] = "{$path}: use rounded-surface, rounded-inner or rounded-control ({$class})";
                }
                if (in_array('btn-accent', $names, true) && ! in_array($path, self::ACCENT_ALLOWED, true)) {
                    $offenders[] = "{$path}: the main button is btn-primary ({$class})";
                }
                if ($tag === 'h1' && ! in_array($path, self::BARE, true)) {
                    $offenders[] = "{$path}: the top bar is the header, set shell_title instead of an h1 ({$class})";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testTheLayoutsGiveTheFullWidth(): void
    {
        foreach (['layouts/admin.php', 'layouts/business.php'] as $layout) {
            $html = (string) file_get_contents(APPPATH . 'Views/' . $layout);
            $this->assertMatchesRegularExpression("/section\\('main_class'\\) \\?>(<\\?= ')?w-full px-4 py-6 lg:px-8/", $html, $layout);
        }
    }

    /** @return array<string,string> path under Views => contents */
    private function views(): array
    {
        $views = [];
        foreach (['admin', 'business'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH . 'Views/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $views[substr($file->getPathname(), strlen(APPPATH . 'Views/'))] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        foreach (['layouts/admin.php', 'layouts/business.php', 'layouts/_profile_tabs.php'] as $layout) {
            $views[$layout] = (string) file_get_contents(APPPATH . 'Views/' . $layout);
        }

        return $views;
    }
}
