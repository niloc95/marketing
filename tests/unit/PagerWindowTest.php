<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * pager_window() decides which page numbers the directory pager prints. The
 * pager used to print every page, which stops working past a few dozen, so
 * these hold it to first, last, current and neighbours at any size.
 *
 * @internal
 */
final class PagerWindowTest extends CIUnitTestCase
{
    public function testOnePageIsJustThatPage(): void
    {
        $this->assertSame([1], pager_window(1, 1));
    }

    public function testFewPagesShowEveryPage(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], pager_window(4, 7));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], pager_window(1, 7));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], pager_window(7, 7));
    }

    public function testFirstPageOfMany(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, '…', 417], pager_window(1, 417));
    }

    public function testLastPageOfMany(): void
    {
        $this->assertSame([1, '…', 411, 412, 413, 414, 415, 416, 417], pager_window(417, 417));
    }

    public function testMiddleOfManyHasBothGaps(): void
    {
        $this->assertSame([1, '…', 198, 199, 200, 201, 202, '…', 417], pager_window(200, 417));
    }

    public function testASinglePageGapIsShownNotHidden(): void
    {
        // Page 4 with siblings 2 starts its run at 2, so there is no gap at all;
        // at page 5 the run starts at 3 and "1 … 3" would hide just page 2.
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, '…', 417], pager_window(5, 417));
        $this->assertSame([1, '…', 411, 412, 413, 414, 415, 416, 417], pager_window(413, 417));
    }

    public function testOutOfRangePagesClamp(): void
    {
        $this->assertSame(pager_window(417, 417), pager_window(9999, 417));
        $this->assertSame(pager_window(1, 417), pager_window(-3, 417));
        $this->assertSame([1], pager_window(1, 0));
    }

    public function testAlwaysNineEntriesAndAlwaysTheCurrentPage(): void
    {
        // A fixed width keeps Next under the same spot while paging through.
        foreach ([9, 10, 161, 417] as $total) {
            for ($p = 1; $p <= $total; $p++) {
                $window = pager_window($p, $total);
                $this->assertCount(9, $window, "page {$p} of {$total}");
                $this->assertContains($p, $window, "page {$p} of {$total}");
            }
        }
    }
}
