<?php

namespace App\Models\Concerns;

/**
 * Row counts per status for the admin queue tabs.
 *
 * The using model must define a STATUSES class constant (array of status
 * strings). Used by DirectoryReferralModel, DirectoryReviewModel and
 * DirectoryPartnerModel as is; JobPostModel aliases it to statusCounts()
 * and adds its 'ended' total on top.
 */
trait HasStatusCounts
{
    /**
     * @return array<string,int> every status in STATUSES set to 0, then
     *                           filled with the real counts
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->select('status, COUNT(*) AS n')->groupBy('status')->findAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}
