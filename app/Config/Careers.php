<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Rules for /careers (App\Services\CareersService): working at WebScheduler
 * Local itself, not the Jobs board.
 *
 * Every property can be overridden from .env as `careers.<name>`.
 */
class Careers extends BaseConfig
{
    /**
     * Teams a role belongs to, keyed by the stored value. The page groups
     * open roles under these headings, in this order.
     *
     * @var array<string,string>
     */
    public array $teams = [
        'sales'       => 'Sales',
        'support'     => 'Customer support',
        'engineering' => 'Engineering',
        'other'       => 'Other',
    ];

    /**
     * "About WebScheduler Local": the same paragraphs at the foot of every
     * role page, as OpenAI's postings close with "About OpenAI". Customer
     * copy, so no dashes and no hyphenated words, and we are a discovery and
     * visibility platform, never "a directory".
     *
     * @var list<string>
     */
    public array $about = [
        'WebScheduler Local is a local business discovery and visibility platform built for South Africa. '
            . 'We help people find the businesses, professionals and services around them, and help those businesses be found.',
        'Every South African business can create a profile for free, and stays free. Businesses that want to show they are '
            . 'real and checked can add the Verified Business badge. We never sell a higher place in the results.',
        'We are a small team, so the work you do here reaches real shops, practices and trades in towns across the country, '
            . 'and you will help decide how the platform grows.',
    ];

    /** The closing note under About, as Anthropic's postings carry. */
    public string $applyAnyway = 'If you do not meet every point above, please apply anyway. We would rather hear from you '
        . 'than miss someone who would be great at this. We welcome applications from everyone.';

    /**
     * Where applications are emailed. Empty means Config\Directory's admin
     * address, the same inbox /contact and /partners use.
     */
    public string $inbox = '';

    /**
     * Months an application is kept before `spark careers:purge` wipes the
     * applicant's details and CV. The form's consent line quotes this.
     */
    public int $retentionMonths = 12;
}
