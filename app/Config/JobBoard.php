<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Rules for the Jobs board (App\Services\JobBoardService).
 */
class JobBoard extends BaseConfig
{
    /** Days a new post stays up when the poster does not pick a closing date. */
    public int $defaultDays = 30;

    /**
     * The furthest out a closing date may be. Google treats long-lived and
     * undated jobs as stale, so every post ends and has to be renewed.
     */
    public int $maxDays = 60;

    /** How many days before closing the poster gets a renew reminder. */
    public int $remindDaysBefore = 3;

    /** Responses a service request takes before it stops accepting more. */
    public int $maxResponses = 5;

    /**
     * Lead alerts: listed businesses emailed when a matching service request
     * goes live. Ten is double the reply cap, so the request still gets
     * competing replies without mailing a whole category.
     */
    public int $maxAlertsPerRequest = 10;

    /** No business gets more lead alerts than this in any 24 hours. */
    public int $maxAlertsPerBusinessPerDay = 3;

    /** Distinct visitors reporting a post before it is hidden for review. */
    public int $reportThreshold = 3;

    /** Months after a post ends before the poster's contact details are wiped. */
    public int $retentionMonths = 12;

    /**
     * Phrases that send a post to the admin queue, even from a listed
     * business. They come from the South African job-scam pattern: an
     * applicant is "accepted" and then asked to pay for registration,
     * training, a uniform or placement, usually over WhatsApp. A match only
     * means a person looks first; it never rejects a post by itself, so a
     * false positive costs a short wait, not a lost post.
     *
     * Matched case-insensitively on whole words against the title and
     * description.
     *
     * @var list<string>
     */
    public array $scamPhrases = [
        'registration fee',
        'application fee',
        'training fee',
        'uniform fee',
        'placement fee',
        'admin fee',
        'administration fee',
        'processing fee',
        'joining fee',
        'starter kit',
        'pay to apply',
        'pay a fee',
        'refundable deposit',
        'upfront payment',
        'whatsapp only',
        'whatsapp me',
        'send your id',
        'bank details',
        'crypto',
        'bitcoin',
        'forex',
        'no experience needed earn',
        'work from home earn',
    ];

    /**
     * Employment types, keyed by the stored value, mapped to the label shown
     * and Google's JobPosting employmentType.
     *
     * @var array<string,array{label:string,schema:string}>
     */
    public array $employmentTypes = [
        'full_time'  => ['label' => 'Full time',  'schema' => 'FULL_TIME'],
        'part_time'  => ['label' => 'Part time',  'schema' => 'PART_TIME'],
        'contract'   => ['label' => 'Contract',   'schema' => 'CONTRACTOR'],
        'temporary'  => ['label' => 'Temporary',  'schema' => 'TEMPORARY'],
        'internship' => ['label' => 'Internship / learnership', 'schema' => 'INTERN'],
        'volunteer'  => ['label' => 'Volunteer',  'schema' => 'VOLUNTEER'],
    ];

    /**
     * Salary periods: label and Google's QuantitativeValue unitText.
     *
     * @var array<string,array{label:string,schema:string}>
     */
    public array $salaryPeriods = [
        'hour'  => ['label' => 'per hour',  'schema' => 'HOUR'],
        'day'   => ['label' => 'per day',   'schema' => 'DAY'],
        'week'  => ['label' => 'per week',  'schema' => 'WEEK'],
        'month' => ['label' => 'per month', 'schema' => 'MONTH'],
        'year'  => ['label' => 'per year',  'schema' => 'YEAR'],
    ];
}
