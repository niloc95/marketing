<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Communications: the email templates the admin can send from
 * /admin/communications, and the audiences they can go to.
 *
 * A template is a starting point, not a fixed email. The admin edits the
 * subject and paragraphs for each campaign; what stays fixed here is which
 * audiences a template may go to, where its button points and the footer.
 * Default wording follows the copy rules in CLAUDE.md (practice profile,
 * Verified, discover, no dashes or hyphenated words, nothing South African
 * on .app).
 *
 * Merge fields (CampaignRenderer::FIELDS) are filled per profile and escaped.
 * A paragraph that uses {appearances} is left out for a profile Google has not
 * shown yet, so nobody is told "0 times".
 *
 * Button targets:
 * - signin: a live sign in link to /business (minted at send time, 7 days)
 * - upgrade: /verified on co.za, /plus on .app, on the profile's own site
 * - business: /business on the profile's own site
 * - profile: the public profile page
 */
class Campaigns extends BaseConfig
{
    /** The audiences (CampaignAudience segments). */
    public array $segments = [
        'unclaimed_imports' => 'Unclaimed imported profiles (nobody has signed in yet)',
        'free'              => 'Free profiles (signed up or claimed, no paid plan)',
        'premium'           => 'Verified and Plus profiles',
    ];

    /**
     * @var array<string,array{label:string,summary:string,segments:list<string>,subject:string,paragraphs:list<string>,button:array{0:string,1:string}}>
     */
    public array $kinds = [
        'claim' => [
            'label'      => 'Claim your practice profile',
            'summary'    => 'Tells the owner of an imported profile that it exists, where the details came from, and how to claim it.',
            'segments'   => ['unclaimed_imports'],
            'subject'    => 'Is this your {noun}? Your free profile on {site}',
            'paragraphs' => [
                'Hello,',
                '{name} has a free {noun} profile on {site}, a local discovery and visibility platform. We created it from {source}, so {audience} can discover your {noun} and find your phone number, address and hours.',
                'In the last 30 days your profile appeared {appearances} times in Google results.',
                'Claim it to check the details are right and keep them up to date: add photos, your services and your hours. Claiming costs nothing, and the profile stays free.',
                'If anything is wrong, or you would rather not be on the platform, reply to this email and we will fix or remove it.',
            ],
            'button'     => ['Claim my free profile', 'signin'],
        ],
        'verified_upgrade' => [
            'label'      => 'Upgrade to Verified or Plus',
            'summary'    => 'Tells a Free profile what the paid plan adds. Verified on co.za, Plus on .app, worded and linked per site.',
            'segments'   => ['free'],
            'subject'    => 'More for {name} on {site}',
            'paragraphs' => [
                'Hello,',
                'Thank you for keeping {name} up to date on {site}.',
                'Your profile stays free. If you would like more, the paid plan adds your team, extra branches and vacancies on the Jobs board.',
            ],
            'button'     => ['See what it adds', 'upgrade'],
        ],
        'announcement' => [
            'label'      => 'Announcement',
            'summary'    => 'A short notice to any audience, with a button to the owner area.',
            'segments'   => ['unclaimed_imports', 'free', 'premium'],
            'subject'    => 'News for {name} from {site}',
            'paragraphs' => [
                'Hello,',
                'We have news about your profile on {site}.',
            ],
            'button'     => ['Open my profile', 'business'],
        ],
    ];

    /**
     * Where an imported profile's details came from, by signup_source, for
     * {source}. Read by the owner before the first send.
     *
     * @var array<string,string>
     */
    public array $sources = [
        'import-lenmed'  => 'the public listings on the Lenmed hospital group website',
        'import-doctors' => 'public practice and hospital listings',
    ];

    public string $defaultSource = 'public practice and business listings';

    /** Highest daily limit the form accepts. */
    public int $maxDailyLimit = 500;

    /** Failures in a row that pause a campaign (a mail outage must not burn the list). */
    public int $pauseAfterFailures = 5;

    /** Tries per recipient before it is left as failed. */
    public int $maxAttempts = 3;
}
