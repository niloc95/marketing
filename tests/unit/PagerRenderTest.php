<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The directory pager partial: the links it builds and what it tells a
 * visitor and a screen reader. Its window of page numbers is pinned by
 * PagerWindowTest; this holds the URLs, which are what broke before (a venue
 * page 2 once arrived as ?amp;page=2 and silently showed page 1).
 *
 * @internal
 */
final class PagerRenderTest extends CIUnitTestCase
{
    private function render(int $page, int $total, array $query = [], int $perPage = 12): string
    {
        helper('directory_ui');

        // Decoded so the asserts read the URL a browser would follow.
        return html_entity_decode(view('directory/_pager', [
            'result'  => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => (int) ceil($total / $perPage)],
            'baseUrl' => 'https://local.test/directory/school',
            'query'   => $query,
        ], ['saveData' => false]), ENT_QUOTES | ENT_HTML5);
    }

    public function testOnePageRendersNothing(): void
    {
        $this->assertStringNotContainsString('<nav', $this->render(1, 12));
    }

    public function testSummaryPositionAndCurrentPage(): void
    {
        $html = $this->render(50, 1924);

        $this->assertStringContainsString('Showing <b>589</b> to <b>600</b>', $html);
        $this->assertStringContainsString('of <b>1,924</b> business profiles', $html);
        $this->assertStringContainsString('Page 50 of 161', $html);
        $this->assertStringContainsString('aria-current="page">50<', $html);
        $this->assertStringContainsString('href="https://local.test/directory/school?page=49" rel="prev"', $html);
        $this->assertStringContainsString('href="https://local.test/directory/school?page=51" rel="next"', $html);
        $this->assertSame(6, substr_count($html, 'class="pager-page"'), 'four neighbours plus first and last');
    }

    public function testThePagesAtEitherEndHaveNoStepPastThem(): void
    {
        $first = $this->render(1, 100);
        $this->assertStringNotContainsString('rel="prev"', $first);
        $this->assertStringContainsString('rel="next"', $first);

        $last = $this->render(9, 100);
        $this->assertStringContainsString('rel="prev"', $last);
        $this->assertStringNotContainsString('rel="next"', $last);
        $this->assertStringContainsString('Showing <b>97</b> to <b>100</b>', $last);
    }

    public function testPageOneLinksToTheCleanUrl(): void
    {
        $this->assertStringContainsString('href="https://local.test/directory/school" aria-label="Page 1"', $this->render(3, 100));
    }

    public function testFacetsAndFiltersSurviveEveryLink(): void
    {
        $html = $this->render(2, 100, ['f' => ['curriculum' => ['ieb', 'caps']], 'sort' => 'new', 'q' => '', 'category' => 'a&b']);

        $this->assertStringContainsString(
            'href="https://local.test/directory/school?f%5Bcurriculum%5D%5B0%5D=ieb&f%5Bcurriculum%5D%5B1%5D=caps&sort=new&category=a%26b&page=3" rel="next"',
            $html
        );
        $this->assertStringNotContainsString('amp;page', $html);
        $this->assertStringNotContainsString('q=', $html, 'empty values stay out of the URL');
    }

    public function testGoToPageCarriesTheQueryAsHiddenFields(): void
    {
        $html = $this->render(2, 100, ['f' => ['curriculum' => ['ieb']], 'sort' => 'new']);

        $this->assertStringContainsString('<input type="hidden" name="f[curriculum][0]" value="ieb">', $html);
        $this->assertStringContainsString('<input type="hidden" name="sort" value="new">', $html);
        $this->assertStringContainsString('max="9"', $html);
        // Seven pages or fewer all fit in the window, so no form.
        $this->assertStringNotContainsString('pager-jump', $this->render(2, 84));
    }
}
