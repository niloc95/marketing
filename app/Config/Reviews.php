<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Rules for customer reviews (App\Services\ReviewService).
 */
class Reviews extends BaseConfig
{
    /** Plain-text length limits for the review itself. */
    public int $bodyMin = 30;

    public int $bodyMax = 1500;

    /** The owner's public reply. */
    public int $replyMax = 1000;

    /** Published reviews shown on the profile before "Show more". */
    public int $perPage = 5;

    /** Distinct visitors reporting a published review before it goes back to the queue. */
    public int $reportThreshold = 3;

    /** Unconfirmed reviews are deleted this many days after they were written. */
    public int $unconfirmedDays = 7;

    /**
     * Days after a review is rejected or hidden before the reviewer's name
     * and email are wiped. The row and its email_hash stay, so the same
     * person still cannot review the business twice.
     */
    public int $decidedRetentionDays = 30;

    /**
     * Phrases in an owner's reply that email the admin. The reply is live at
     * once either way (the owner has proved their email and is on the public
     * record); a match only means a person reads it and can remove it from
     * /admin/reviews. Same spirit as Config\JobBoard::$scamPhrases.
     *
     * @var list<string>
     */
    public array $replyFlags = [
        'whatsapp me',
        'bank details',
        'send your id',
        'lawyer',
        'attorney',
        'sue you',
        'legal action',
        'liar',
        'fake review',
    ];
}
